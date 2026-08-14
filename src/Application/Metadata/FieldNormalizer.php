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

/**
 * @internal
 */
final readonly class FieldNormalizer
{
    /**
     * @return list<array<string, mixed>>
     */
    public function normalizeForm(FormMetadata $form, string $locale): array
    {
        $fields = [];
        foreach ($form->getFlatFieldMetadata() as $item) {
            $fields[] = $this->normalizeItem($item, $locale);
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeItem(FieldMetadata $item, string $locale): array
    {
        $field = [
            'name' => $item->getName(),
            'type' => $item->getType(),
            'label' => $item->getLabel($locale) ?? $item->getName(),
            'required' => $item->isRequired(),
        ];

        if ('block' === $item->getType()) {
            $types = [];
            foreach ($item->getTypes() as $typeName => $typeForm) {
                $globalBlockTag = $typeForm->getTagsByName('sulu.global_block')[0] ?? null;

                if (null !== $globalBlockTag) {
                    $types[$typeName] = [
                        'key' => $typeName,
                        'label' => $typeForm->getTitle($locale),
                        'globalBlock' => $globalBlockTag->getAttribute('global_block'),
                    ];

                    continue;
                }

                $types[$typeName] = [
                    'key' => $typeName,
                    'label' => $typeForm->getTitle($locale),
                    'fields' => $this->normalizeForm($typeForm, $locale),
                ];
            }
            $field['types'] = $types;
        }

        return $field;
    }
}
