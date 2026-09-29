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

use CmsIg\Seal\Schema\Index;
use CmsIg\Seal\Schema\Schema;
use CmsIg\Seal\Search\Condition\Condition;

/**
 * Structured product search over the `website` SEAL index, the logic behind the
 * `sulu_product_search` MCP tool, and the fields
 * `Sulu\Product\Infrastructure\Sulu\Search\Visitor\WebsiteProductAttributesReindexProviderEnhancer`
 * (an `@internal` class, no BC promise) adds to it. The field names and the option/number key
 * encoding are duplicated here rather than read from that class; a pinned test in
 * ProductSearchTest asserts they still match its actual `textValue()`/`numericField()`.
 * Framework-agnostic on purpose: it depends on no MCP type, so it can be driven from any caller,
 * not only the tool adapter.
 *
 * @internal
 */
final class ProductSearch
{
    private const PRODUCT_RESOURCE_KEY = 'products';
    private const PRODUCT_FIELD = 'product';
    private const PRODUCT_FAMILY_ID_FIELD = 'productFamilyId';
    private const TEXT_VALUES_FIELD = 'attributes_text_values';
    private const NUMERIC_VALUES_FIELD = 'attributes_numeric_values';
    private const DATE_FORMAT = 'Y-m-d';

    public function __construct(
        private readonly WebsiteSearch $websiteSearch,
        private readonly Schema $schema,
    ) {
    }

    /**
     * @param array<string, list<string>>|null $options attribute key => option keys to match
     * @param array<string, array{min?: int|float|string, max?: int|float|string}>|null $ranges attribute key => bounds
     *
     * @return array<string, mixed>
     */
    public function search(
        string $locale,
        ?string $query = null,
        ?string $webspace = null,
        ?string $productFamily = null,
        ?array $options = null,
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
    public static function textValue(string $attributeKey, string $optionKey): string
    {
        return self::sanitizeAttributeKey($attributeKey) . ':' . $optionKey;
    }

    /**
     * Matches `WebsiteProductAttributesReindexProviderEnhancer::sanitize()` (private there,
     * reachable only through `textValue()`/`numericField()`, both duplicated above instead).
     */
    public static function sanitizeAttributeKey(string $key): string
    {
        $name = (string) \preg_replace('/[^A-Za-z0-9_]/', '_', $key);

        return 1 === \preg_match('/^[A-Za-z]/', $name) ? $name : 'a_' . $name;
    }
}
