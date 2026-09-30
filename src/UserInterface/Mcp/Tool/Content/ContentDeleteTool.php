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
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Application\Content\ContentTypeExtensionRegistry;
use Sulu\Mcp\Application\Content\ContentTypeResolver;
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
        description: 'Permanently delete a page, article, snippet, or any type a bundle registers, by UUID. Set "type" to "page", "article", "snippet", or another registered type. Removes both draft and published versions — this cannot be undone. For pages with children, set forceRemoveChildren=true to delete the whole subtree (ignored for articles/snippets). Snippets may be referenced by other content; deleting one removes that shared content everywhere it is used. A registered type may cascade the deletion to related entities; check that type\'s own list tool first if unsure.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false),
    )]
    #[DangerousTool('delete')]
    #[RequiresPermission(
        requirements: [
            new PermissionRequirement('#context#', PermissionTypes::EDIT),
            new PermissionRequirement('#context#', PermissionTypes::DELETE),
        ],
        objectResolved: true,
        discoveryContexts: ['sulu.snippet.snippets', ContentTypeExtensionRegistry::ANY_EXTENSION_CONTEXT, ArticleSecurityContextResolver::ANY_ARTICLE_GROUP_CONTEXT, WebspacePermissionResolver::ANY_WEBSPACE_CONTEXT],
    )]
    public function deleteContent(
        string $type,
        string $uuid,
        string $locale,
        bool $forceRemoveChildren = false,
    ): array {
        if (!$this->contentTypeResolver->supports($type)) {
            return [
                'error' => \sprintf('Unsupported content type "%s".', $type),
                'hint' => \sprintf('Supported types: %s.', \implode(', ', $this->contentTypeResolver->supportedTypes())),
            ];
        }

        try {
            $entity = $this->contentTypeResolver->loadDraft($type, $uuid, $locale);
            if (null === $entity) {
                return [
                    'error' => \sprintf('%s not found: %s', \ucfirst($type), $uuid),
                    'hint' => \sprintf('Verify the UUID exists (use sulu_%s_get).', $type),
                ];
            }

            $extension = $this->contentTypeResolver->get($type);
            $security = $this->contentSecurityContextResolver->forEntity($type, $entity);

            $this->permissionChecker->check(
                $security->context,
                [PermissionTypes::EDIT, PermissionTypes::DELETE],
                $locale,
                $security->aclObjectType,
                null !== $security->aclObjectType ? $uuid : null,
            );

            if ($extension instanceof RemovalGuardInterface) {
                $extension->assertCanRemove($uuid);
            }

            $message = $this->contentTypeResolver->createRemoveMessage($type, $uuid, $locale, $forceRemoveChildren);

            $this->handle(new Envelope($message, [new EnableFlushStamp()]));

            return [
                'success' => true,
                'type' => $type,
                'uuid' => $uuid,
                'deleted' => true,
            ];
        } catch (PermissionDeniedException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            return [
                'error' => \sprintf('Failed to delete %s %s: %s', $type, $uuid, $e->getMessage()),
                'hint' => \sprintf('Verify the UUID exists (use sulu_%s_get). For a page with children, set forceRemoveChildren=true.', $type),
            ];
        }
    }
}
