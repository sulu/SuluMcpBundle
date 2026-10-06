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
use Sulu\Content\Domain\Model\ContentRichEntityInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Application\Content\ContentTypeExtensionRegistry;
use Sulu\Mcp\Application\Content\ContentTypeResolver;
use Sulu\Mcp\Application\Content\ContentTypeSchemaExpander;
use Sulu\Mcp\Application\Security\ContentSecurityContextResolver;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Domain\Content\RemovalGuardInterface;
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
class ContentDeleteTool
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
        name: 'sulu_content_delete',
        title: 'Delete Content',
        description: 'Permanently delete a content entity by UUID. Set "resourceKey" to one of {resourceKeys} (formerly "type", which is no longer accepted). The response carries "resourceKey" instead of "type". Removes both draft and published versions — this cannot be undone. For pages with children, set forceRemoveChildren=true to delete the whole subtree (ignored for other resource keys). Snippets may be referenced by other content; deleting one removes that shared content everywhere it is used. A resource key a bundle registers may cascade the deletion to related entities; check that type\'s own list tool first if unsure.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false),
    )]
    #[DangerousTool('delete')]
    #[RequiresPermission(
        requirements: [
            new PermissionRequirement('#context#', PermissionTypes::EDIT),
            new PermissionRequirement('#context#', PermissionTypes::DELETE),
        ],
        objectResolved: true,
        discoveryContexts: [ContentTypeExtensionRegistry::ANY_EXTENSION_CONTEXT, ArticleSecurityContextResolver::ANY_ARTICLE_GROUP_CONTEXT, WebspacePermissionResolver::ANY_WEBSPACE_CONTEXT],
    )]
    public function deleteContent(
        #[Schema(description: 'The resourceKey of the content type: {resourceKeys}.', enum: [ContentTypeSchemaExpander::RESOURCE_KEYS])]
        string $resourceKey,
        string $uuid,
        string $locale,
        bool $forceRemoveChildren = false,
    ): array {
        if (!$this->contentTypeResolver->supports($resourceKey)) {
            return [
                'error' => \sprintf('Unsupported content type "%s".', $resourceKey),
                'hint' => \sprintf('Supported types: %s.', \implode(', ', $this->contentTypeResolver->supportedResourceKeys())),
            ];
        }

        try {
            $entity = $this->contentTypeResolver->loadDraft($resourceKey, $uuid, $locale);
            if (null === $entity) {
                return [
                    'error' => \sprintf('%s not found: %s', \ucfirst($resourceKey), $uuid),
                    'hint' => 'Verify the UUID and resourceKey are correct (use the matching get tool, e.g. sulu_page_get).',
                ];
            }

            $extension = $this->contentTypeResolver->get($resourceKey);

            // the removal deletes every locale, and each locale may resolve to another security context (a snippet or
            // article template group), so the permissions are required for all of them
            foreach (self::contentLocales($entity, $locale) as $contentLocale) {
                $security = $this->contentSecurityContextResolver->forEntity($resourceKey, $entity, $contentLocale);

                $this->permissionChecker->check(
                    $security->context,
                    [PermissionTypes::EDIT, PermissionTypes::DELETE],
                    $contentLocale,
                    $security->aclObjectType,
                    null !== $security->aclObjectType ? $uuid : null,
                );
            }

            if ($extension instanceof RemovalGuardInterface) {
                $extension->assertCanRemove($uuid);
            }

            $message = $this->contentTypeResolver->createRemoveMessage($resourceKey, $uuid, $locale, $forceRemoveChildren);

            $this->handle(new Envelope($message, [new EnableFlushStamp()]));

            return [
                'success' => true,
                'resourceKey' => $resourceKey,
                'uuid' => $uuid,
                'deleted' => true,
            ];
        } catch (PermissionDeniedException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            return [
                'error' => \sprintf('Failed to delete %s %s: %s', $resourceKey, $uuid, $e->getMessage()),
                'hint' => 'Verify the UUID and resourceKey are correct (use the matching get tool, e.g. sulu_page_get). For a page with children, set forceRemoveChildren=true.',
            ];
        }
    }

    /**
     * The requested locale, followed by every locale the entity has content in.
     *
     * @return list<string>
     */
    private static function contentLocales(object $entity, string $locale): array
    {
        $locales = [$locale];
        if ($entity instanceof ContentRichEntityInterface) {
            foreach ($entity->getDimensionContents() as $dimensionContent) {
                if (null === $dimensionContent->getLocale() && DimensionContentInterface::STAGE_DRAFT === $dimensionContent->getStage()) {
                    $locales = [...$locales, ...($dimensionContent->getAvailableLocales() ?? [])];
                }
            }
        }

        return \array_values(\array_unique($locales));
    }
}
