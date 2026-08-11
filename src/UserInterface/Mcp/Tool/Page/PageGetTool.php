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

namespace Sulu\Mcp\UserInterface\Mcp\Tool\Page;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Application\Content\ContentNormalizerTrait;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;
use Sulu\Page\Domain\Exception\PageNotFoundException;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;

/**
 * @internal
 */
class PageGetTool
{
    use ContentNormalizerTrait;

    public function __construct(
        private readonly PageRepositoryInterface $pageRepository,
        private readonly ContentManagerInterface $contentManager,
        private readonly ToolPermissionCheckerInterface $permissionChecker,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'sulu_page_get',
        description: 'Get a single page by its UUID. Returns draft metadata, template fields, block summaries (index, _id, type, title), and SEO/excerpt data. Use sulu_block_list with type="page" to fetch full block content. Always call this before sulu_page_update.',
    )]
    #[RequiresPermission(
        requirements: [new PermissionRequirement('sulu.webspaces.#context#', PermissionTypes::VIEW)],
        objectResolved: true,
        discoveryContexts: [WebspacePermissionResolver::ANY_WEBSPACE_CONTEXT],
    )]
    public function getPage(string $webspace, string $locale, string $uuid): array
    {
        try {
            $page = $this->pageRepository->getOneBy(
                [
                    'uuid' => $uuid,
                    'locale' => $locale,
                    'stage' => DimensionContentInterface::STAGE_DRAFT,
                ],
                [
                    PageRepositoryInterface::GROUP_SELECT_PAGE_ADMIN => true,
                ],
            );

            // Context comes from the loaded entity; the page ACL is checked object-level.
            $this->permissionChecker->check(
                'sulu.webspaces.'.$page->getWebspaceKey(),
                PermissionTypes::VIEW,
                $locale,
                Page::class,
                $uuid,
            );

            $dimensionContent = $this->contentManager->resolve($page, [
                'locale' => $locale,
                'stage' => DimensionContentInterface::STAGE_DRAFT,
            ]);

            $normalized = $this->contentManager->normalize($dimensionContent);

            $compacted = $this->compactContent($normalized, $this->detectBlockProperties($normalized));

            return [
                'uuid' => $page->getUuid(),
                'webspace' => $page->getWebspaceKey(),
                'locale' => $locale,
                'data' => $compacted,
            ];
        } catch (PermissionDeniedException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        } catch (PageNotFoundException) {
            return [
                'error' => 'Page not found: '.$uuid,
                'hint' => 'Verify the UUID and locale. Use sulu_page_list or sulu_content_search to find pages.',
            ];
        }
    }
}
