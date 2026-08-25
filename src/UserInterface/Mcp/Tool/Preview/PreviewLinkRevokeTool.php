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
use Sulu\Bundle\PreviewBundle\Application\Manager\PreviewLinkManagerInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\TemplateInterface;
use Sulu\Mcp\Application\Content\ContentTypeResolver;
use Sulu\Mcp\Application\Security\ContentSecurityContextResolver;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Page\Domain\Model\Page;

/**
 * @internal
 */
class PreviewLinkRevokeTool
{
    private const TYPE_MAP = ['page' => 'pages', 'article' => 'articles'];

    public function __construct(
        private readonly PreviewLinkManagerInterface $previewLinkManager,
        private readonly ContentTypeResolver $contentTypeResolver,
        private readonly ContentManagerInterface $contentManager,
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
        description: 'Revoke/invalidate a previously generated public preview link for a page or article. After revoking, the preview URL will no longer work. Pass `type` as "page" or "article" (the same singular values used by the other tools). If no preview link exists for the resource, the operation returns an error — verify a link exists with sulu_preview_link_generate before revoking.',
    )]
    #[RequiresPermission(
        requirements: [new PermissionRequirement('#context#', PermissionTypes::EDIT)],
        objectResolved: true,
        discoveryContexts: [ArticleSecurityContextResolver::ANY_ARTICLE_GROUP_CONTEXT, WebspacePermissionResolver::ANY_WEBSPACE_CONTEXT],
    )]
    public function revokePreviewLink(
        #[Schema(description: 'Content type to preview: "page" or "article" (same singular values used by the other tools).', enum: ['page', 'article'])]
        string $type,
        string $uuid,
        string $locale,
    ): array {
        try {
            $entity = $this->contentTypeResolver->loadDraft($type, $uuid, $locale);
            if (null === $entity) {
                return [
                    'error' => \sprintf('%s not found: %s', $type, $uuid),
                    'hint' => 'Verify the type ("page"/"article"), uuid and locale.',
                ];
            }

            $dimensionContent = 'article' === $type
                ? $this->contentManager->resolve($entity, ['locale' => $locale, 'stage' => DimensionContentInterface::STAGE_DRAFT]) // @phpstan-ignore argument.type, argument.templateType (upstream generic is invariant; loadDraft() returns a bare object)
                : null;

            // Preview links are gated on EDIT, stricter than the admin UI's VIEW.
            $this->permissionChecker->check(
                $this->contentSecurityContextResolver->forEntity(
                    $type,
                    $entity,
                    $dimensionContent instanceof TemplateInterface ? $dimensionContent : null,
                ),
                PermissionTypes::EDIT,
                $locale,
                'page' === $type ? Page::class : null,
                'page' === $type ? $uuid : null,
            );

            $resourceKey = self::TYPE_MAP[$type] ?? $type;
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
