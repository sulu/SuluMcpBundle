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

use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Mcp\Application\Content\StrippedContentFields;

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
    public function __construct(
        private readonly MetadataProviderInterface $formMetadataProvider,
        private readonly MetadataLocaleResolver $localeResolver,
        private readonly ExtensionFormKeys $formKeys,
    ) {
    }

    /**
     * @return array{seo: list<array{name: string, type: string, label: string, required: bool}>, excerpt: list<array{name: string, type: string, label: string, required: bool}>}
     */
    public function getExtensionFields(): array
    {
        return [
            'seo' => $this->fields($this->formKeys->seo(), 'seo'),
            'excerpt' => $this->fields($this->formKeys->excerpt(), 'excerpt'),
        ];
    }

    /**
     * @param list<string> $formKeys
     *
     * @return list<array{name: string, type: string, label: string, required: bool}>
     */
    private function fields(array $formKeys, string $namespace): array
    {
        $locale = $this->localeResolver->resolve();
        $prefix = $namespace . '/';
        $fields = [];
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
                $rawName = $item->getName();
                if (\in_array($rawName, StrippedContentFields::NAMES, true)) {
                    continue;
                }
                $name = \str_starts_with($rawName, $prefix) ? \substr($rawName, \strlen($prefix)) : $rawName;
                // A form may redefine a field of a higher-priority one (e.g. excerpt/image): the last one
                // wins, as in the admin, which merges the sub-forms in priority order.
                $fields[$name] = [
                    'name' => $name,
                    'type' => $item->getType(),
                    'label' => $item->getLabel($locale) ?? $name,
                    'required' => $item->isRequired(),
                ];
            }
        }

        return \array_values($fields);
    }
}
