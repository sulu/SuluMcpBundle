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
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Mcp\Application\Metadata\FieldNormalizer;

/**
 * @internal
 */
class TemplatesResource
{
    public function __construct(
        private readonly MetadataProviderInterface $formMetadataProvider,
        private readonly FieldNormalizer $fieldNormalizer,
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    #[McpResource(
        uri: 'sulu://templates',
        name: 'sulu_templates',
        description: 'Available Sulu templates grouped by content type. Top-level keys are `page`, `article`, and `snippet` (any type with no templates installed is omitted). Each entry maps a template key to its field schema. Use the template key when creating or updating content of that type. Block fields list their allowed types with fields inlined, except types referencing a global block, which carry `globalBlock: <name>`; their field definitions are listed once in sulu://global_blocks.',
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
        return [
            'key' => $form->getKey(),
            'fields' => $this->fieldNormalizer->normalizeForm($form, 'en'),
        ];
    }
}
