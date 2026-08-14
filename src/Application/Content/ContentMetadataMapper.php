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

namespace Sulu\Mcp\Application\Content;

use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;

/**
 * Maps the MCP `excerpt` / `seo` objects onto the content `$data` shape Sulu's
 * modify messages expect.
 *
 * Field names and placement are driven by Sulu's live form metadata (so a project
 * that customises the seo/excerpt forms via metadata works with no code change):
 * a metadata field name with a `/` (e.g. `seo/title`) nests under that namespace;
 * a name without (e.g. `seoNoIndex`, `excerptCategories`) is a top-level column.
 *
 * @internal
 */
final readonly class ContentMetadataMapper
{
    private const SEO_FORM_KEYS = ['content_seo_metadata'];
    private const EXCERPT_FORM_KEYS = ['content_excerpt_metadata', 'content_excerpt_taxonomies'];

    /** Fixed Sulu-core columns stored at the top level (outside the seo/excerpt sub-arrays). */
    private const TOP_LEVEL_FIELDS = ['seoNoIndex', 'seoNoFollow', 'seoHideInSitemap', 'excerptCategories', 'excerptTags'];

    public function __construct(
        private MetadataProviderInterface $formMetadataProvider,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $excerpt
     *
     * @return array<string, mixed>
     */
    public function applyExcerpt(array $data, ?array $excerpt, string $locale): array
    {
        return $this->apply($data, $excerpt, 'excerpt', self::EXCERPT_FORM_KEYS, $locale);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $seo
     *
     * @return array<string, mixed>
     */
    public function applySeo(array $data, ?array $seo, string $locale): array
    {
        return $this->apply($data, $seo, 'seo', self::SEO_FORM_KEYS, $locale);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $input
     * @param list<string> $formKeys
     *
     * @return array<string, mixed>
     */
    private function apply(array $data, ?array $input, string $namespace, array $formKeys, string $locale): array
    {
        if (null === $input) {
            return $data;
        }

        $validNames = $this->loadFieldNames($formKeys, $locale);

        $unknown = [];
        foreach ($input as $key => $value) {
            $fieldName = $this->resolveFieldName((string) $key, $namespace, $validNames);

            if ([] !== $validNames && !\in_array($fieldName, $validNames, true)) {
                $unknown[] = (string) $key;

                continue;
            }

            $data = $this->place($data, $fieldName, $value);
        }

        if ([] !== $unknown) {
            return [
                'error' => \sprintf('Unknown %s field(s): %s.', $namespace, \implode(', ', $unknown)),
                'hint' => \sprintf('Available %s fields for this project: %s. Call sulu_get_context to see them.', $namespace, \implode(', ', $this->inputKeys($validNames, $namespace))),
            ];
        }

        return $data;
    }

    /**
     * @param list<string> $validNames
     */
    private function resolveFieldName(string $key, string $namespace, array $validNames): string
    {
        if (\str_contains($key, '/')) {
            return $key;
        }
        if (\in_array($key, self::TOP_LEVEL_FIELDS, true)) {
            return $key;
        }
        if (\in_array($key, $validNames, true)) {
            return $key;
        }

        return $namespace . '/' . $key;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function place(array $data, string $fieldName, mixed $value): array
    {
        if (\str_contains($fieldName, '/')) {
            [$ns, $key] = \explode('/', $fieldName, 2);
            /** @var array<string, mixed> $current */
            $current = \is_array($data[$ns] ?? null) ? $data[$ns] : [];
            $current[$key] = $value;
            $data[$ns] = $current;

            return $data;
        }

        $data[$fieldName] = $value;

        return $data;
    }

    /**
     * @param list<string> $formKeys
     *
     * @return list<string>
     */
    private function loadFieldNames(array $formKeys, string $locale): array
    {
        $names = [];
        foreach ($formKeys as $formKey) {
            try {
                $metadata = $this->formMetadataProvider->getMetadata($formKey, $locale, []);
            } catch (\Throwable) {
                continue;
            }
            if (!$metadata instanceof FormMetadata) {
                continue;
            }
            foreach ($metadata->getFlatFieldMetadata() as $item) {
                $names[] = $item->getName();
            }
        }

        return $names;
    }

    /**
     * Present field names as the keys a caller passes (strip the `<namespace>/` prefix).
     *
     * @param list<string> $validNames
     *
     * @return list<string>
     */
    private function inputKeys(array $validNames, string $namespace): array
    {
        $prefix = $namespace . '/';

        return \array_map(
            static fn (string $name): string => \str_starts_with($name, $prefix) ? \substr($name, \strlen($prefix)) : $name,
            $validNames,
        );
    }
}
