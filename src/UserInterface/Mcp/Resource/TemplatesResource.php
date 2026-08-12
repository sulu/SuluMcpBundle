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
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\ItemMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;

/**
 * @internal
 */
class TemplatesResource
{
    /** @var array<string, FormMetadata>|null */
    private ?array $globalBlockForms = null;

    public function __construct(
        private readonly MetadataProviderInterface $formMetadataProvider,
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    #[McpResource(
        uri: 'sulu://templates',
        name: 'sulu_templates',
        description: 'Available Sulu templates grouped by content type. Top-level keys are `page`, `article`, and `snippet` (any type with no templates installed is omitted). Each entry maps a template key to its field schema. Use the template key when creating or updating content of that type.',
        mimeType: 'application/json',
    )]
    public function getTemplates(): array
    {
        $result = [];
        foreach (['page', 'article', 'snippet'] as $contentType) {
            $templates = $this->loadTemplatesByType($contentType);
            if ([] !== $templates) {
                $result[$contentType] = $templates;
            }
        }

        return $result;
    }

    /** @return array<string, array<string, mixed>> */
    private function loadTemplatesByType(string $contentType): array
    {
        try {
            $typedMetadata = $this->formMetadataProvider->getMetadata($contentType, 'en', []);
        } catch (\Throwable) {
            return [];
        }

        if (!$typedMetadata instanceof TypedFormMetadata) {
            return [];
        }

        $result = [];
        $forms = $typedMetadata->getForms();
        /** @var array<string, FormMetadata> $forms */
        foreach ($forms as $key => $formMetadata) {
            $result[$key] = $this->normalizeTemplate($formMetadata);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function normalizeTemplate(FormMetadata $form): array
    {
        $fields = [];
        foreach ($form->getItems() as $item) {
            $fields[] = $this->normalizeItem($item);
        }

        return ['key' => $form->getKey(), 'fields' => $fields];
    }

    /**
     * @param ItemMetadata $item
     * @param array<string, true> $visiting block type names currently on the resolution path
     *
     * @return array<string, mixed>
     */
    private function normalizeItem($item, array $visiting = []): array
    {
        $field = [
            'name' => $item->getName(),
            'type' => $item->getType(),
            'label' => $item->getLabel('en') ?? $item->getName(),
            'required' => $item instanceof FieldMetadata && $item->isRequired(),
        ];

        if ($item instanceof FieldMetadata && 'block' === $item->getType()) {
            $types = [];
            $types = $item->getTypes();
            /** @var array<string, FormMetadata> $types */
            foreach ($types as $typeName => $blockForm) {
                $resolvedForm = $this->resolveBlockForm($typeName, $blockForm);

                if (isset($visiting[$typeName])) {
                    $types[$typeName] = [
                        'key' => $typeName,
                        'label' => $resolvedForm->getTitle('en'),
                        'fields' => [],
                        'cyclic' => true,
                    ];

                    continue;
                }

                $blockFields = [];
                foreach ($resolvedForm->getItems() as $blockItem) {
                    $blockFields[] = $this->normalizeItem($blockItem, $visiting + [$typeName => true]);
                }
                $types[$typeName] = [
                    'key' => $typeName,
                    'label' => $resolvedForm->getTitle('en'),
                    'fields' => $blockFields,
                ];
            }
            $field['types'] = $types;
        }

        return $field;
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
}
