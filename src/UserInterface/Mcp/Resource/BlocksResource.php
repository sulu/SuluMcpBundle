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

namespace Sulu\Mcp\UserInterface\Mcp\Resource;

use Mcp\Capability\Attribute\McpResource;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;

/**
 * @internal
 */
class BlocksResource
{
    /** @var array<string, FormMetadata>|null */
    private ?array $globalBlockForms = null;

    public function __construct(
        private readonly MetadataProviderInterface $formMetadataProvider,
    ) {
    }

    /** @return list<array<string, mixed>> */
    #[McpResource(
        uri: 'sulu://blocks',
        name: 'sulu_blocks',
        description: 'Available block types with their field definitions across all webspaces (per D-02: static URI cannot filter by webspace). Shows which templates each block type can be used in.',
        mimeType: 'application/json',
    )]
    public function getBlocks(): array
    {
        $typedMetadata = $this->formMetadataProvider->getMetadata('page', 'en', []);
        if (!$typedMetadata instanceof TypedFormMetadata) {
            return [];
        }

        return $this->extractBlockTypes($typedMetadata);
    }

    /** @return list<array<string, mixed>> */
    private function extractBlockTypes(TypedFormMetadata $typedMetadata): array
    {
        $blockTypes = [];
        $forms = $typedMetadata->getForms();
        /** @var array<string, FormMetadata> $forms */
        foreach ($forms as $templateKey => $formMetadata) {
            foreach ($formMetadata->getItems() as $item) {
                if (!$item instanceof FieldMetadata || 'block' !== $item->getType()) {
                    continue;
                }
                $types = $item->getTypes();
                /** @var array<string, FormMetadata> $types */
                foreach ($types as $blockTypeName => $blockForm) {
                    if (!isset($blockTypes[$blockTypeName])) {
                        $resolvedForm = $this->resolveBlockForm($blockTypeName, $blockForm);
                        $blockTypes[$blockTypeName] = [
                            'key' => $blockTypeName,
                            'label' => $resolvedForm->getTitle('en'),
                            'fields' => $this->normalizeBlockFields($resolvedForm, [$blockTypeName => true]),
                            'available_in_templates' => [],
                        ];
                    }
                    $blockTypes[$blockTypeName]['available_in_templates'][] = $templateKey;
                }
            }
        }

        return \array_values($blockTypes);
    }

    private function resolveBlockForm(string $blockTypeName, FormMetadata $blockForm): FormMetadata
    {
        if ([] !== $blockForm->getItems()) {
            return $blockForm;
        }

        $globalBlock = $this->getGlobalBlockForms()[$blockTypeName] ?? null;
        if (null !== $globalBlock) {
            return $globalBlock;
        }

        return $blockForm;
    }

    /**
     * @return array<string, FormMetadata>
     */
    private function getGlobalBlockForms(): array
    {
        if (null === $this->globalBlockForms) {
            $blockMetadata = $this->formMetadataProvider->getMetadata('block', 'en', ['ignore_global_blocks' => true]);
            /** @var array<string, FormMetadata> $forms */
            $forms = $blockMetadata instanceof TypedFormMetadata ? $blockMetadata->getForms() : [];
            $this->globalBlockForms = $forms;
        }

        return $this->globalBlockForms;
    }

    /**
     * @param array<string, true> $visiting block type names currently on the resolution path
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeBlockFields(FormMetadata $blockForm, array $visiting = []): array
    {
        $fields = [];
        foreach ($blockForm->getItems() as $item) {
            $field = [
                'name' => $item->getName(),
                'type' => $item->getType(),
                'label' => $item->getLabel('en') ?? $item->getName(),
            ];

            if ($item instanceof FieldMetadata && 'block' === $item->getType()) {
                $types = [];
                $types = $item->getTypes();
                /** @var array<string, FormMetadata> $types */
                foreach ($types as $typeName => $nestedBlockForm) {
                    $resolvedNested = $this->resolveBlockForm($typeName, $nestedBlockForm);

                    if (isset($visiting[$typeName])) {
                        $types[$typeName] = [
                            'key' => $typeName,
                            'label' => $resolvedNested->getTitle('en'),
                            'fields' => [],
                            'cyclic' => true,
                        ];

                        continue;
                    }

                    $types[$typeName] = [
                        'key' => $typeName,
                        'label' => $resolvedNested->getTitle('en'),
                        'fields' => $this->normalizeBlockFields($resolvedNested, $visiting + [$typeName => true]),
                    ];
                }
                $field['types'] = $types;
            }

            $fields[] = $field;
        }

        return $fields;
    }
}
