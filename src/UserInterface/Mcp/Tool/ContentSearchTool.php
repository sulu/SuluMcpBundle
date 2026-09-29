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

namespace Sulu\Mcp\UserInterface\Mcp\Tool;

use CmsIg\Seal\EngineInterface;
use CmsIg\Seal\Search\Condition\Condition;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;

/**
 * @internal
 */
class ContentSearchTool
{
    private const TYPE_MAP = [
        'page' => 'pages',
        'article' => 'articles',
    ];

    // Spelled out literally, not via ProductInterface::RESOURCE_KEY/ProductAdmin::SECURITY_CONTEXT:
    // this class is registered whether or not SuluProductBundle is installed.
    private const PRODUCT_RESOURCE_KEY = 'products';
    private const PRODUCT_SECURITY_CONTEXT = 'sulu.product.products';

    private const ARTICLE_RESOURCE_KEY = 'articles';

    public function __construct(
        private readonly EngineInterface $engine,
        private readonly WebspacePermissionResolver $webspacePermissionResolver,
        private readonly ToolPermissionCheckerInterface $permissionChecker,
        private readonly ArticleSecurityContextResolver $articleContextResolver,
        private readonly bool $productsIndexed = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'sulu_content_search',
        title: 'Search Content',
        description: 'Search published website content (articles and pages) by keyword. Searches both titles and full content text. Returns matching items with their UUID and resource type — use resourceKey to pick the right get tool (sulu_article_get or sulu_page_get) and resourceId as the UUID. Filter by type ("page" or "article") to restrict results to one content type. Filter by webspace to scope results to one site. Only published content is searchable.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    #[RequiresPermission(
        requirements: [new PermissionRequirement('#context#', PermissionTypes::VIEW)],
        objectResolved: true,
        discoveryContexts: [WebspacePermissionResolver::ANY_WEBSPACE_CONTEXT],
    )]
    public function search(
        string $query,
        string $locale,
        #[Schema(description: 'Webspace key to restrict results to one site (e.g. "example"). Omit to search all webspaces.')]
        ?string $webspace = null,
        #[Schema(description: 'Content type to search. Valid values: "page" or "article". Omit to search both.', enum: ['page', 'article'])]
        ?string $type = null,
        int $page = 1,
        int $limit = 20,
    ): array {
        // The `website` index carries only `webspaces`, no securityContext,
        // so per-object ACL filtering isn't possible here. Constraining to the webspaces
        // the caller may VIEW is the best available mirror.
        $permitted = $this->webspacePermissionResolver->permittedWebspaceKeys(PermissionTypes::VIEW, $locale);
        if ([] === $permitted) {
            return ['results' => [], 'total' => 0, 'hint' => 'No webspaces are readable with your permissions.'];
        }

        $effective = null !== $webspace ? \array_values(\array_intersect($permitted, [$webspace])) : $permitted;
        if ([] === $effective) {
            return ['results' => [], 'total' => 0, 'hint' => \sprintf('Webspace "%s" is not readable with your permissions.', $webspace)];
        }

        $resourceKey = null !== $type ? (self::TYPE_MAP[$type] ?? $type) : null;

        // Pages, articles and products all land in the same `website` index. A page's
        // security context is its webspace, already checked above. Articles and products
        // each carry their own, separate security context, so both need an extra check
        // here: an untyped search only surfaces them once the caller holds it, and an
        // explicit type="article" is refused outright rather than silently filtered away.
        $canSeeArticles = $this->hasArticlePermission($locale);

        if (self::ARTICLE_RESOURCE_KEY === $resourceKey && !$canSeeArticles) {
            return [
                'error' => 'Permission denied: no accessible security context grants the required permissions.',
                'hint' => 'Requires VIEW on "sulu.article.articles" (or the matching article group context).',
            ];
        }

        $canSeeProducts = $this->productsIndexed
            && $this->permissionChecker->has(self::PRODUCT_SECURITY_CONTEXT, PermissionTypes::VIEW, $locale);

        try {
            $builder = $this->engine->createSearchBuilder('website')
                ->addFilter(Condition::search($query))
                ->addFilter(Condition::equal('locale', $locale))
                ->addFilter(Condition::in('webspaces', $effective))
                ->limit($limit)
                ->offset(($page - 1) * $limit);

            if (null !== $resourceKey) {
                $builder->addFilter(Condition::equal('resourceKey', $resourceKey));
            } else {
                $visibleResourceKeys = [self::TYPE_MAP['page']];
                if ($canSeeArticles) {
                    $visibleResourceKeys[] = self::ARTICLE_RESOURCE_KEY;
                }
                if ($canSeeProducts) {
                    $visibleResourceKeys[] = self::PRODUCT_RESOURCE_KEY;
                }
                $builder->addFilter(Condition::in('resourceKey', $visibleResourceKeys));
            }

            $result = $builder->getResult();

            $results = [];
            foreach ($result as $document) {
                $results[] = [
                    'resourceKey' => $document['resourceKey'] ?? null,
                    'resourceId' => $document['resourceId'] ?? null,
                    'locale' => $document['locale'] ?? null,
                    'title' => $document['title'] ?? null,
                    'url' => $document['url'] ?? null,
                    'webspaces' => $document['webspaces'] ?? [],
                    'authoredAt' => $document['authoredAt'] ?? null,
                    'metadata' => $document['metadata'] ?? [],
                ];
            }

            return [
                'results' => $results,
                'total' => $result->total(),
                'page' => $page,
                'limit' => $limit,
            ];
        } catch (\Throwable $e) {
            return [
                'error' => \sprintf('Content search failed: %s', $e->getMessage()),
                'hint' => 'Only published content is indexed. Verify the locale is correct and type is "page" or "article" (or omit to search both).',
            ];
        }
    }

    /**
     * The `website` index carries no template, so per-group filtering the way
     * ArticleListTool does isn't possible here: VIEW on any one article group is
     * enough to see article results at all.
     */
    private function hasArticlePermission(string $locale): bool
    {
        foreach ($this->articleContextResolver->candidates() as $context) {
            if ($this->permissionChecker->has($context, PermissionTypes::VIEW, $locale)) {
                return true;
            }
        }

        return false;
    }
}
