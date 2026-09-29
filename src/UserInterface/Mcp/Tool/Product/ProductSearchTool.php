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

namespace Sulu\Mcp\UserInterface\Mcp\Tool\Product;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Application\Search\ProductSearch;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;
use Sulu\Product\Infrastructure\Sulu\Admin\ProductAdmin;

/**
 * MCP adapter for ProductSearch: declares the tool's schema and permission gate, everything
 * else is that class's job.
 *
 * @internal
 */
class ProductSearchTool
{
    public function __construct(
        private readonly ProductSearch $productSearch,
    ) {
    }

    /**
     * @param array<string, list<string>>|null $options attribute key => option keys to match
     * @param array<string, array{min?: int|float|string, max?: int|float|string}>|null $ranges attribute key => bounds
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'sulu_product_search',
        title: 'Search Products',
        description: 'Search published products by keyword and by structured filters: product family, option values, and number or date ranges. Structured filtering needs the product search index enabled (sulu_product.search.website.additional_product_filters) and only works for attributes marked "filterable" in sulu_attribute_list. A text-type attribute has no structured filter. Match it through the free-text query instead. Only published, current data is searched. A product with variants is never returned itself, only its variants are, each its own result with its own resourceId. Call sulu_product_get with a result\'s resourceId for the full record.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    #[RequiresPermission(requirements: [
        new PermissionRequirement(ProductAdmin::SECURITY_CONTEXT, PermissionTypes::VIEW),
    ])]
    public function search(
        string $locale,
        ?string $query = null,
        #[Schema(description: 'Webspace key to restrict results to one site (e.g. "example"). Omit to search all webspaces.')]
        ?string $webspace = null,
        #[Schema(description: 'Product family UUID to restrict results to one family. Read a result\'s "product.productFamilyId", or resolve a name via sulu_product_family_list, first.')]
        ?string $productFamily = null,
        #[Schema(
            type: 'object',
            additionalProperties: true,
            description: 'Attribute key => list of option keys to match, e.g. {"colour": ["red", "black"]}. Option keys within one attribute are ORed, different attributes are ANDed. Use the exact attribute key and option key from sulu_attribute_list, never a translated name.',
        )]
        ?array $options = null,
        #[Schema(
            type: 'object',
            additionalProperties: true,
            description: 'Attribute key => {"min": ..., "max": ...} to match a number or date attribute, either bound optional, e.g. {"current_rating": {"min": 10}}. A date bound is "Y-m-d". Different attributes are ANDed.',
        )]
        ?array $ranges = null,
        int $page = 1,
        int $limit = 20,
    ): array {
        return $this->productSearch->search($locale, $query, $webspace, $productFamily, $options, $ranges, $page, $limit);
    }
}
