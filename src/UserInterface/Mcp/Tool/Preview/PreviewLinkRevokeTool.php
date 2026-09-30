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

namespace Sulu\Mcp\UserInterface\Mcp\Tool\Preview;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Sulu\Bundle\PreviewBundle\Application\Manager\PreviewLinkManagerInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Application\Content\ContentTypeResolver;
use Sulu\Mcp\Application\Content\ContentTypeSchemaExpander;
use Sulu\Mcp\Application\Security\ContentSecurityContextResolver;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Domain\Content\NotSearchableContentTypeInterface;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\Domain\Security\DangerousTool;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;

/**
 * @internal
 */
class PreviewLinkRevokeTool
{
    public function __construct(
        private readonly PreviewLinkManagerInterface $previewLinkManager,
        private readonly ContentTypeResolver $contentTypeResolver,
        private readonly ToolPermissionCheckerInterface $permissionChecker,
        private readonly ContentSecurityContextResolver $contentSecurityContextResolver,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'sulu_preview_link_revoke',
        title: 'Revoke Preview Link',
        description: 'Revoke/invalidate a previously generated public preview link for a content entity. After revoking, the preview URL will no longer work. Pass `resourceKey` as one of {resourceKeys}. If no preview link exists for the resource, the operation returns an error — verify a link exists with sulu_preview_link_generate before revoking.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false),
    )]
    #[DangerousTool('publish')]
    #[RequiresPermission(
        requirements: [new PermissionRequirement('#context#', PermissionTypes::EDIT)],
        objectResolved: true,
        discoveryContexts: [ArticleSecurityContextResolver::ANY_ARTICLE_GROUP_CONTEXT, WebspacePermissionResolver::ANY_WEBSPACE_CONTEXT],
    )]
    public function revokePreviewLink(
        #[Schema(description: 'The resourceKey of the content type to preview: {resourceKeys}.', enum: [ContentTypeSchemaExpander::RESOURCE_KEYS])]
        string $resourceKey,
        string $uuid,
        string $locale,
    ): array {
        $extension = $this->contentTypeResolver->find($resourceKey);
        if (null === $extension || $extension instanceof NotSearchableContentTypeInterface) {
            return [
                'error' => \sprintf('Resource key "%s" cannot be previewed.', $resourceKey),
                'hint' => 'Use a previewable resourceKey such as "pages" or "articles".',
            ];
        }

        try {
            $entity = $this->contentTypeResolver->loadDraft($resourceKey, $uuid, $locale);
            if (null === $entity) {
                return [
                    'error' => \sprintf('%s not found: %s', $resourceKey, $uuid),
                    'hint' => 'Verify the type ("page"/"article"), uuid and locale.',
                ];
            }

            $security = $this->contentSecurityContextResolver->forEntity($resourceKey, $entity, $locale);

            // Preview links are gated on EDIT, stricter than the admin UI's VIEW.
            $this->permissionChecker->check(
                $security->context,
                PermissionTypes::EDIT,
                $locale,
                $security->aclObjectType,
                null !== $security->aclObjectType ? $uuid : null,
            );

            $this->previewLinkManager->revoke($resourceKey, $uuid, $locale);

            return [
                'success' => true,
                'action' => 'revoked',
                'resourceKey' => $resourceKey,
                'resourceId' => $uuid,
                'locale' => $locale,
            ];
        } catch (PermissionDeniedException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            return [
                'error' => \sprintf('Failed to revoke preview link: %s', $e->getMessage()),
                'hint' => 'Verify a preview link exists for this resource. Use sulu_preview_link_generate to create one first.',
            ];
        }
    }
}
