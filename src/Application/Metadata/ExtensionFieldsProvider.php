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

namespace Sulu\Mcp\Application\Metadata;

use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;

/**
 * Exposes the project's live `seo` and `excerpt` field definitions so AI clients
 * know which fields to pass to create/update. Names are presented as the input
 * keys the ContentMetadataMapper expects (the `seo/` / `excerpt/` prefix stripped;
 * top-level columns like `seoNoIndex` / `excerptCategories` kept as-is).
 *
 * @internal
 */
class ExtensionFieldsProvider
{
    private const SEO_FORM_KEYS = ['content_seo_metadata'];
    private const EXCERPT_FORM_KEYS = ['content_excerpt_metadata', 'content_excerpt_taxonomies'];

    public function __construct(
        private readonly MetadataProviderInterface $formMetadataProvider,
    ) {
    }

    /**
     * @return array{seo: list<array{name: string, type: string, label: string, required: bool}>, excerpt: list<array{name: string, type: string, label: string, required: bool}>}
     */
    public function getExtensionFields(): array
    {
        return [
            'seo' => $this->fields(self::SEO_FORM_KEYS, 'seo'),
            'excerpt' => $this->fields(self::EXCERPT_FORM_KEYS, 'excerpt'),
        ];
    }

    /**
     * @param list<string> $formKeys
     *
     * @return list<array{name: string, type: string, label: string, required: bool}>
     */
    private function fields(array $formKeys, string $namespace): array
    {
        $prefix = $namespace.'/';
        $fields = [];
        foreach ($formKeys as $formKey) {
            try {
                $metadata = $this->formMetadataProvider->getMetadata($formKey, 'en', []);
            } catch (\Throwable) {
                continue;
            }
            if (!$metadata instanceof FormMetadata) {
                continue;
            }
            foreach ($metadata->getItems() as $item) {
                $rawName = $item->getName();
                $name = \str_starts_with($rawName, $prefix) ? \substr($rawName, \strlen($prefix)) : $rawName;
                $fields[] = [
                    'name' => $name,
                    'type' => $item->getType(),
                    'label' => $item->getLabel('en') ?? $name,
                    'required' => $item instanceof FieldMetadata && $item->isRequired(),
                ];
            }
        }

        return $fields;
    }
}
