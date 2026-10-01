<?php

declare(strict_types=1);

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Mcp\UserInterface\Mcp\Tool\Content;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Application\Content\ContentTypeExtensionRegistry;
use Sulu\Mcp\Application\Content\ContentTypeResolver;
use Sulu\Mcp\Application\Content\ContentTypeSchemaExpander;
use Sulu\Mcp\Application\Security\ContentSecurityContextResolver;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\Domain\Security\DangerousTool;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @internal
 */
class ContentPublishTool
{
    use HandleTrait;

    public function __construct(
        MessageBusInterface $messageBus,
        private readonly ContentTypeResolver $contentTypeResolver,
        private readonly ToolPermissionCheckerInterface $permissionChecker,
        private readonly ContentSecurityContextResolver $contentSecurityContextResolver,
    ) {
        $this->messageBus = $messageBus;
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'sulu_content_publish',
        title: 'Publish Content',
        description: 'Publish a content entity to make its current draft the live version. Set "resourceKey" to one of {resourceKeys} (formerly "type", which is no longer accepted). The response carries "resourceKey" instead of "type". A resource key a bundle registers may have its own publish order or cascade rules; check that type\'s own tools if unsure. Content is always created/updated as a draft first — call this after creating or updating to go live. Can be called again to re-publish after edits. IMPORTANT: Always ask the user for confirmation before calling this tool — never publish without explicit user approval.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false),
    )]
    #[DangerousTool('publish')]
    #[RequiresPermission(
        requirements: [
            new PermissionRequirement('#context#', PermissionTypes::EDIT),
            new PermissionRequirement('#context#', PermissionTypes::LIVE),
        ],
        objectResolved: true,
        discoveryContexts: [ContentTypeExtensionRegistry::ANY_EXTENSION_CONTEXT, ArticleSecurityContextResolver::ANY_ARTICLE_GROUP_CONTEXT, WebspacePermissionResolver::ANY_WEBSPACE_CONTEXT],
    )]
    public function publishContent(
        #[Schema(description: 'The resourceKey of the content type: {resourceKeys}.', enum: [ContentTypeSchemaExpander::RESOURCE_KEYS])]
        string $resourceKey,
        string $uuid,
        string $locale,
    ): array {
        if (!$this->contentTypeResolver->supports($resourceKey)) {
            return [
                'error' => \sprintf('Unsupported content type "%s".', $resourceKey),
                'hint' => \sprintf('Supported types: %s.', \implode(', ', $this->contentTypeResolver->supportedResourceKeys())),
            ];
        }

        try {
            $entity = $this->contentTypeResolver->loadForTransition($resourceKey, $uuid, $locale);
            if (null === $entity) {
                return [
                    'error' => \sprintf('%s not found: %s', \ucfirst($resourceKey), $uuid),
                    'hint' => 'Verify the UUID and resourceKey are correct (use the matching get tool, e.g. sulu_page_get).',
                ];
            }

            $security = $this->contentSecurityContextResolver->forEntity($resourceKey, $entity, $locale);

            $this->permissionChecker->check(
                $security->context,
                [PermissionTypes::EDIT, PermissionTypes::LIVE],
                $locale,
                $security->aclObjectType,
                null !== $security->aclObjectType ? $uuid : null,
            );

            $message = $this->contentTypeResolver->createTransitionMessage($resourceKey, $uuid, $locale, 'publish');

            $this->handle(new Envelope($message, [new EnableFlushStamp()]));

            return [
                'success' => true,
                'resourceKey' => $resourceKey,
                'uuid' => $uuid,
                'action' => 'published',
                'locale' => $locale,
            ];
        } catch (PermissionDeniedException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            return [
                'error' => \sprintf('Failed to publish %s %s: %s', $resourceKey, $uuid, $e->getMessage()),
                'hint' => 'Verify the content exists and is in draft state (use the matching get tool, e.g. sulu_page_get, to check workflowPlace).',
            ];
        }
    }
}
