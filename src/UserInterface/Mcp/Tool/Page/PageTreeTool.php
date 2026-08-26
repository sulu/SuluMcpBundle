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
use Mcp\Capability\Attribute\Schema;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Application\Security\AccessControlFilterFactory;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;
use Sulu\Page\Domain\Model\PageDimensionContentInterface;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;

/**
 * @internal
 */
class PageTreeTool
{
    public function __construct(
        private readonly PageRepositoryInterface $pageRepository,
        private readonly ContentManagerInterface $contentManager,
        private readonly WebspacePermissionResolver $webspacePermissionResolver,
        private readonly AccessControlFilterFactory $accessControlFilterFactory,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'sulu_page_tree',
        title: 'Get Page Tree',
        description: 'Get the page tree as a nested hierarchy for a webspace. Each node contains uuid, title, url, template, a 1-based "position" among its siblings (what sulu_page_reorder expects), and a "children" array with the same structure. Shows the site structure — use this to find the parentId when creating new pages, or to understand the site navigation. Root-level pages are direct children of the webspace root. Accepts an optional maxDepth to limit response size on deep site trees; when a node has hasChildren:true but children:[] the branch was depth-truncated — request again with a higher maxDepth or fetch that branch separately.',
    )]
    #[RequiresPermission(
        requirements: [new PermissionRequirement('sulu.webspaces.#context#', PermissionTypes::VIEW)],
        objectResolved: true,
        discoveryContexts: [WebspacePermissionResolver::ANY_WEBSPACE_CONTEXT],
    )]
    public function getPageTree(
        string $webspace,
        string $locale,
        #[Schema(description: 'Maximum nesting depth to return (0 = root pages only). Omit for the full tree. Use to limit response size on deep site trees.')]
        ?int $maxDepth = null,
    ): array {
        $permitted = $this->webspacePermissionResolver->permittedWebspaceKeys(PermissionTypes::VIEW, $locale);
        if (!\in_array($webspace, $permitted, true)) {
            return [
                'webspace' => $webspace,
                'locale' => $locale,
                'tree' => [],
                'hint' => \sprintf('Webspace "%s" is not readable with your permissions.', $webspace),
            ];
        }

        // Scope the query to the requested webspace (checked permitted above); otherwise
        // the tree mixes in every other webspace the user happens to be permitted on.
        $pages = $this->pageRepository->findByAsTree(
            [
                'webspaceKey' => $webspace,
                'locale' => $locale,
                'stage' => DimensionContentInterface::STAGE_DRAFT,
                // Per-page ACLs, as in PageListTool.
                'accessControl' => $this->accessControlFilterFactory->forPermission(PermissionTypes::VIEW),
            ],
            [],
            [PageRepositoryInterface::GROUP_SELECT_PAGE_ADMIN => true],
        );

        $tree = [];
        foreach ($pages as $page) {
            $tree[] = $this->buildTreeNode($page, $locale, 0, $maxDepth);
        }

        return [
            'webspace' => $webspace,
            'locale' => $locale,
            'tree' => $tree,
        ];
    }

    /**
     * Counted over the parent's own children (mapped `ORDER BY lft`), not over the nodes
     * in this response: the query is ACL-filtered, and sulu_page_reorder needs the
     * absolute ordinal that reorderOneBy() counts against.
     */
    private function siblingPosition(PageInterface $page): int
    {
        $parent = $page->getParent();
        if (null === $parent) {
            return 1;
        }

        $position = 1;
        foreach ($parent->getChildren() as $sibling) {
            if ($sibling->getUuid() === $page->getUuid()) {
                break;
            }

            ++$position;
        }

        return $position;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTreeNode(PageInterface $page, string $locale, int $depth = 0, ?int $maxDepth = null): array
    {
        /** @var PageDimensionContentInterface $dimensionContent */
        $dimensionContent = $this->contentManager->resolve($page, [
            'locale' => $locale,
            'stage' => DimensionContentInterface::STAGE_DRAFT,
        ]);

        $children = $page->getChildren();
        $childNodes = [];

        if (null === $maxDepth || $depth < $maxDepth) {
            foreach ($children as $child) {
                $childNodes[] = $this->buildTreeNode($child, $locale, $depth + 1, $maxDepth);
            }
        }

        return [
            'uuid' => $page->getUuid(),
            'title' => $dimensionContent->getTitle(),
            'url' => $dimensionContent->getRoute()?->getSlug(),
            'templateKey' => $dimensionContent->getTemplateKey(),
            'hasChildren' => !$children->isEmpty(),
            'parentUuid' => $page->getParent()?->getUuid(),
            'depth' => $depth,
            'position' => $this->siblingPosition($page),
            'workflowPlace' => $dimensionContent->getWorkflowPlace(),
            'availableLocales' => $dimensionContent->getAvailableLocales(),
            'children' => $childNodes,
        ];
    }
}
