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

use CmsIg\Seal\Schema\Index;
use CmsIg\Seal\Schema\Schema as SealSchema;
use CmsIg\Seal\Search\Condition\Condition;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Application\Search\WebsiteSearch;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;
use Sulu\Product\Infrastructure\Sulu\Admin\ProductAdmin;

/**
 * Structured product search over the `website` SEAL index, the fields
 * `Sulu\Product\Infrastructure\Sulu\Search\Visitor\WebsiteProductAttributesReindexProviderEnhancer`
 * (an `@internal` class, no BC promise) adds to it. The field names and the option/number key
 * encoding are duplicated here rather than read from that class; a pinned test in
 * ProductSearchToolTest asserts they still match its actual `textValue()`/`numericField()`.
 *
 * @internal
 */
class ProductSearchTool
{
    private const PRODUCT_RESOURCE_KEY = 'products';
    private const PRODUCT_FIELD = 'product';
    private const PRODUCT_FAMILY_ID_FIELD = 'productFamilyId';
    private const TEXT_VALUES_FIELD = 'attributes_text_values';
    private const NUMERIC_VALUES_FIELD = 'attributes_numeric_values';
    private const DATE_FORMAT = 'Y-m-d';

    public function __construct(
        private readonly WebsiteSearch $websiteSearch,
        private readonly SealSchema $schema,
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
        $websiteIndex = $this->schema->indexes['website'] ?? null;
        $hasStructuredFilters = null !== $productFamily || null !== $options || null !== $ranges;

        // findFieldByPath() only resolves a leaf field: the "product" path alone is an
        // ObjectField, so it always returns null. A field inside it that is always present
        // when the schema loader ran stands in for "is the product field indexed at all".
        if ($hasStructuredFilters && (null === $websiteIndex || null === $websiteIndex->findFieldByPath(self::PRODUCT_FIELD . '.' . self::PRODUCT_FAMILY_ID_FIELD))) {
            return [
                'error' => 'Product attribute filtering is not indexed.',
                'hint' => 'Set sulu_product.search.website.additional_product_filters to true and run cmsig:seal:reindex --index website --drop. Search by "query" only until then.',
            ];
        }

        $rangeFilters = $this->rangeFilters($ranges, $websiteIndex);
        if (isset($rangeFilters['error'])) {
            return $rangeFilters;
        }

        $optionFilters = $this->optionFilters($options);
        if (isset($optionFilters['error'])) {
            return $optionFilters;
        }

        try {
            $builder = $this->websiteSearch->builder($locale, $query, $page, $limit)
                ->addFilter(Condition::equal('resourceKey', self::PRODUCT_RESOURCE_KEY));

            if (null !== $webspace) {
                $builder->addFilter(Condition::equal('webspaces', $webspace));
            }

            if (null !== $productFamily) {
                $builder->addFilter(Condition::equal(self::PRODUCT_FIELD . '.' . self::PRODUCT_FAMILY_ID_FIELD, $productFamily));
            }

            foreach ($optionFilters['filters'] as $filter) {
                $builder->addFilter($filter);
            }

            foreach ($rangeFilters['filters'] as $filter) {
                $builder->addFilter($filter);
            }

            return $this->websiteSearch->run($builder, $page, $limit);
        } catch (\Throwable $e) {
            return [
                'error' => \sprintf('Product search failed: %s', $e->getMessage()),
                'hint' => 'Verify the locale is correct. If a structured filter was just enabled, run cmsig:seal:reindex --index website --drop first.',
            ];
        }
    }

    /**
     * @param array<string, list<string>>|null $options
     *
     * @return array{filters: list<object>, error?: string, hint?: string}
     */
    private function optionFilters(?array $options): array
    {
        $filters = [];

        foreach ($options ?? [] as $attributeKey => $optionKeys) {
            if ([] === $optionKeys) {
                return [
                    'filters' => [],
                    'error' => \sprintf('Invalid options for attribute "%s".', $attributeKey),
                    'hint' => 'Pass a non-empty list of option keys for each attribute.',
                ];
            }

            $values = [];
            foreach ($optionKeys as $optionKey) {
                $values[] = self::textValue((string) $attributeKey, (string) $optionKey);
            }

            $filters[] = Condition::in(self::PRODUCT_FIELD . '.' . self::TEXT_VALUES_FIELD, $values);
        }

        return ['filters' => $filters];
    }

    /**
     * @param array<string, array{min?: int|float|string, max?: int|float|string}>|null $ranges
     *
     * @return array{filters: list<object>, error?: string, hint?: string}
     */
    private function rangeFilters(?array $ranges, ?Index $websiteIndex): array
    {
        $filters = [];

        foreach ($ranges ?? [] as $attributeKey => $bounds) {
            if ([] === $bounds) {
                return [
                    'filters' => [],
                    'error' => \sprintf('Invalid range for attribute "%s".', $attributeKey),
                    'hint' => 'Pass {"min": ...}, {"max": ...}, or both.',
                ];
            }

            $path = self::PRODUCT_FIELD . '.' . self::NUMERIC_VALUES_FIELD . '.' . self::sanitizeAttributeKey((string) $attributeKey);

            if (null === $websiteIndex?->findFieldByPath($path)) {
                return [
                    'filters' => [],
                    'error' => \sprintf('"%s" is not a filterable number or date attribute.', $attributeKey),
                    'hint' => 'Call sulu_attribute_list and check "filterable" and "type" for the exact key.',
                ];
            }

            foreach (['min' => 'greaterThanEqual', 'max' => 'lessThanEqual'] as $bound => $method) {
                if (!\array_key_exists($bound, $bounds)) {
                    continue;
                }

                $value = self::parseRangeBound($bounds[$bound]);
                if (null === $value) {
                    return [
                        'filters' => [],
                        'error' => \sprintf('Invalid "%s" for attribute "%s".', $bound, $attributeKey),
                        'hint' => 'Use a number, or a date as "Y-m-d".',
                    ];
                }

                $filters[] = Condition::{$method}($path, $value);
            }
        }

        return ['filters' => $filters];
    }

    private static function parseRangeBound(mixed $bound): ?float
    {
        if (\is_int($bound) || \is_float($bound)) {
            return (float) $bound;
        }

        if (\is_string($bound)) {
            $date = \DateTimeImmutable::createFromFormat('!' . self::DATE_FORMAT, $bound, new \DateTimeZone('UTC'));

            if (false !== $date && $date->format(self::DATE_FORMAT) === $bound) {
                return (float) $date->getTimestamp();
            }
        }

        return null;
    }

    /**
     * Matches `WebsiteProductAttributesReindexProviderEnhancer::textValue()`.
     */
    private static function textValue(string $attributeKey, string $optionKey): string
    {
        return self::sanitizeAttributeKey($attributeKey) . ':' . $optionKey;
    }

    /**
     * Matches `WebsiteProductAttributesReindexProviderEnhancer::sanitize()` (private there,
     * reachable only through `textValue()`/`numericField()`, both duplicated above instead).
     */
    private static function sanitizeAttributeKey(string $key): string
    {
        $name = (string) \preg_replace('/[^A-Za-z0-9_]/', '_', $key);

        return 1 === \preg_match('/^[A-Za-z]/', $name) ? $name : 'a_' . $name;
    }
}
