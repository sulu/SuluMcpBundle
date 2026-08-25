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
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;
use Sulu\Product\Domain\Model\ProductInterface;
use Sulu\Product\Domain\Repository\ProductRepositoryInterface;

/**
 * @internal
 */
class ProductListTool
{
    private const SUMMARY_FIELDS = [
        'title', 'code', 'status', 'productFamily', 'template',
        'url', 'locale', 'stage',
        'published', 'publishedState', 'workflowPlace',
        'created', 'changed',
        'availableLocales', 'contentLocales', 'ghostLocale',
    ];

    private const ALLOWED_SORT_FIELDS = ['title', 'id'];
    private const ALLOWED_SORT_ORDERS = ['asc', 'desc'];

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ContentManagerInterface $contentManager,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'sulu_product_list',
        title: 'List Products',
        description: 'List products with optional filters. Returns lightweight summaries (title, code, status, family, workflow state) — use sulu_product_get for a single product\'s full data. Variants are excluded by default because they belong to their parent; set includeVariants=true to list them too, or use sulu_product_variant_list for one parent\'s variants. Results are paginated via "page" and "limit".',
    )]
    #[RequiresPermission(requirements: [
        new PermissionRequirement('sulu.product.products', PermissionTypes::VIEW),
    ])]
    public function listProducts(
        string $locale,
        #[Schema(description: 'Filter by product type. One of "product", "product_with_variants" or "variant". Omit to list every non-variant product.', enum: ['product', 'product_with_variants', 'variant'])]
        ?string $type = null,
        #[Schema(description: 'Include variants in the result. Ignored when "type" is set explicitly.')]
        bool $includeVariants = false,
        int $page = 1,
        int $limit = 20,
        #[Schema(description: 'Field to sort by. The product repository supports only "title" and "id". Defaults to "title".', enum: ['title', 'id'])]
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

        try {
            $filters = [
                'locale' => $locale,
                'stage' => DimensionContentInterface::STAGE_DRAFT,
                'page' => $page,
                'limit' => $limit,
            ];

            if (null !== $type) {
                $filters['types'] = [$type];
            } elseif (!$includeVariants) {
                $filters['excludeTypes'] = [ProductInterface::TYPE_VARIANT];
            }

            $products = $this->productRepository->findBy(
                $filters,
                [$sortBy => $sortOrder],
                [ProductRepositoryInterface::GROUP_SELECT_PRODUCT_ADMIN => true],
            );

            $total = $this->productRepository->countBy($filters);

            return [
                'products' => $this->summarize($products, $locale),
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
            ];
        } catch (\Throwable $e) {
            return [
                'error' => \sprintf('Failed to list products: %s', $e->getMessage()),
                'hint' => 'Verify the locale is configured for this installation.',
            ];
        }
    }

    /**
     * @param iterable<ProductInterface> $products
     *
     * @return list<array<string, mixed>>
     */
    private function summarize(iterable $products, string $locale): array
    {
        $results = [];

        foreach ($products as $product) {
            $dimensionContent = $this->contentManager->resolve($product, [
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
                'uuid' => $product->getUuid(),
                'type' => $product->getType(),
                'parent' => $product->getParent()?->getUuid(),
                'data' => $summary,
            ];
        }

        return $results;
    }
}
