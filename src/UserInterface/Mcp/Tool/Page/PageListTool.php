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
use Sulu\Page\Domain\Repository\PageRepositoryInterface;

/**
 * @internal
 */
class PageListTool
{
    private const SUMMARY_FIELDS = [
        'title', 'template', 'url', 'locale', 'stage',
        'published', 'publishedState', 'workflowPlace',
        'authored', 'author', 'created', 'changed',
        'availableLocales', 'contentLocales', 'ghostLocale',
        'shadowOn', 'shadowLocale',
        'navigationContexts',
    ];

    private const ALLOWED_SORT_FIELDS = ['title', 'authored', 'created', 'changed', 'workflowPublished'];
    private const ALLOWED_SORT_ORDERS = ['asc', 'desc'];

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
        name: 'sulu_page_list',
        title: 'List Pages',
        description: 'List pages in a webspace with optional filters. Returns lightweight summaries (title, template, URL, workflow state, dates) — no blocks or HTML content. Use sulu_page_get with a UUID to fetch the full content of a specific page. Use "template" to filter by template key (e.g. "default", "homepage"). Use "parentId" with a page UUID to list only direct children. Results are paginated — use "page" and "limit" to control.',
    )]
    #[RequiresPermission(
        requirements: [new PermissionRequirement('sulu.webspaces.#context#', PermissionTypes::VIEW)],
        objectResolved: true,
        discoveryContexts: [WebspacePermissionResolver::ANY_WEBSPACE_CONTEXT],
    )]
    public function listPages(
        string $webspace,
        string $locale,
        ?string $template = null,
        #[Schema(description: 'UUID of the parent page (a string). Omit for root-level pages. Get UUIDs from sulu_page_tree or sulu_page_list.')]
        ?string $parentId = null,
        int $page = 1,
        int $limit = 20,
        #[Schema(
            description: 'Field to sort pages by. "authored" is the field for "latest pages" — it is the editorial date shown to readers (settable by the author, so it can be backdated). "created" is the immutable database insertion timestamp and is usually NOT what "latest" means to a reader. "changed" is the last-edited timestamp. "workflowPublished" is when the page was last published. Defaults to "title".',
            enum: ['title', 'authored', 'created', 'changed', 'workflowPublished'],
        )]
        string $sortBy = 'title',
        #[Schema(description: 'Sort direction, "asc" or "desc". Defaults to "asc".', enum: ['asc', 'desc'])]
        string $sortOrder = 'asc',
    ): array {
        if (!\in_array($sortBy, self::ALLOWED_SORT_FIELDS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unsupported sortBy "%s". Supported: %s.', $sortBy, \implode(', ', self::ALLOWED_SORT_FIELDS)));
        }

        if (!\in_array($sortOrder, self::ALLOWED_SORT_ORDERS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unsupported sortOrder "%s". Supported: %s.', $sortOrder, \implode(', ', self::ALLOWED_SORT_ORDERS)));
        }

        $sortBys = [$sortBy => $sortOrder];

        $permitted = $this->webspacePermissionResolver->permittedWebspaceKeys(PermissionTypes::VIEW, $locale);
        if (!\in_array($webspace, $permitted, true)) {
            return [
                'pages' => [],
                'total' => 0,
                'page' => $page,
                'limit' => $limit,
                'hint' => \sprintf('Webspace "%s" is not readable with your permissions.', $webspace),
            ];
        }

        // Scope the query to the requested webspace (checked permitted above) so the
        // rows and `total` agree; filtering after a paginated query returns short pages.
        $filters = [
            'webspaceKey' => $webspace,
            'locale' => $locale,
            'stage' => DimensionContentInterface::STAGE_DRAFT,
            'page' => $page,
            'limit' => $limit,
            // Webspace permission alone does not cover per-page ACLs; without this a
            // denied subtree still appears here even though sulu_page_get rejects it.
            'accessControl' => $this->accessControlFilterFactory->forPermission(PermissionTypes::VIEW),
        ];

        if (null !== $template) {
            $filters['templateKeys'] = [$template];
        }

        if (null !== $parentId) {
            $filters['parentId'] = $parentId;
        }

        $total = $this->pageRepository->countBy($filters);

        // Two-step paging: `limit` on the admin select sets setMaxResults on a query
        // that fetch-joins the to-many dimension contents, truncating SQL rows rather
        // than pages -- `limit: 3` returned 2 pages, the last partially hydrated.
        // findIdentifiersBy() selects DISTINCT uuids, so the limit applies to pages.
        $uuids = [...$this->pageRepository->findIdentifiersBy($filters, $sortBys)];
        if ([] === $uuids) {
            return ['pages' => [], 'total' => $total, 'page' => $page, 'limit' => $limit];
        }

        $pages = $this->pageRepository->findBy(
            ['uuids' => $uuids, 'locale' => $locale, 'stage' => DimensionContentInterface::STAGE_DRAFT],
            $sortBys,
            [PageRepositoryInterface::GROUP_SELECT_PAGE_ADMIN => true],
        );

        $results = [];
        foreach ($pages as $pageEntity) {
            $dimensionContent = $this->contentManager->resolve($pageEntity, [
                'locale' => $locale,
                'stage' => DimensionContentInterface::STAGE_DRAFT,
            ]);

            $normalized = $this->contentManager->normalize($dimensionContent);

            $summary = [];
            foreach (self::SUMMARY_FIELDS as $field) {
                if (\array_key_exists($field, $normalized)) {
                    $summary[$field] = $normalized[$field];
                }
            }

            $results[] = [
                'uuid' => $pageEntity->getUuid(),
                'data' => $summary,
            ];
        }

        return [
            'pages' => $results,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }
}
