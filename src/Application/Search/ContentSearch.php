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

namespace Sulu\Mcp\Application\Search;

use CmsIg\Seal\Search\Condition\Condition;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;

/**
 * Keyword search over the `website` SEAL index, the logic behind the `sulu_content_search` MCP
 * tool. Framework-agnostic on purpose: it depends on no MCP type, so it can be driven from any
 * caller, not only the tool adapter.
 *
 * @internal
 */
final class ContentSearch
{
    private const TYPE_MAP = [
        'page' => 'pages',
        'article' => 'articles',
        'product' => 'products',
    ];

    // Spelled out literally, not via ProductInterface::RESOURCE_KEY/ProductAdmin::SECURITY_CONTEXT:
    // this class is instantiated whether or not SuluProductBundle is installed.
    private const PRODUCT_RESOURCE_KEY = 'products';
    private const PRODUCT_SECURITY_CONTEXT = 'sulu.product.products';

    private const ARTICLE_RESOURCE_KEY = 'articles';

    public function __construct(
        private readonly WebsiteSearch $websiteSearch,
        private readonly WebspacePermissionResolver $webspacePermissionResolver,
        private readonly ToolPermissionCheckerInterface $permissionChecker,
        private readonly ArticleSecurityContextResolver $articleContextResolver,
        private readonly bool $productsIndexed = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function search(
        string $query,
        string $locale,
        ?string $webspace = null,
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
        // explicit type="article"/"product" is refused outright rather than silently
        // filtered away.
        $canSeeArticles = $this->hasArticlePermission($locale);

        if (self::ARTICLE_RESOURCE_KEY === $resourceKey && !$canSeeArticles) {
            return [
                'error' => 'Permission denied: no accessible security context grants the required permissions.',
                'hint' => 'Requires VIEW on "sulu.article.articles" (or the matching article group context).',
            ];
        }

        $canSeeProducts = $this->productsIndexed
            && $this->permissionChecker->has(self::PRODUCT_SECURITY_CONTEXT, PermissionTypes::VIEW, $locale);

        if (self::PRODUCT_RESOURCE_KEY === $resourceKey && !$this->productsIndexed) {
            return [
                'error' => 'Unsupported content type "product".',
                'hint' => 'Requires SuluProductBundle to be installed.',
            ];
        }

        if (self::PRODUCT_RESOURCE_KEY === $resourceKey && !$canSeeProducts) {
            return [
                'error' => 'Permission denied: no accessible security context grants the required permissions.',
                'hint' => \sprintf('Requires VIEW on "%s".', self::PRODUCT_SECURITY_CONTEXT),
            ];
        }

        try {
            $builder = $this->websiteSearch->builder($locale, $query, $page, $limit)
                ->addFilter(Condition::in('webspaces', $effective));

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

            return $this->websiteSearch->run($builder, $page, $limit);
        } catch (\Throwable $e) {
            return [
                'error' => \sprintf('Content search failed: %s', $e->getMessage()),
                'hint' => 'Only published content is indexed. Verify the locale is correct and type is "page", "article" or "product" (or omit to search all).',
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
