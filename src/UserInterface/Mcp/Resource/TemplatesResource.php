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
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Mcp\Application\Metadata\FieldSchemaGeneratorInterface;

/**
 * @internal
 */
class TemplatesResource
{
    public function __construct(
        private readonly MetadataProviderInterface $formMetadataProvider,
        private readonly FieldSchemaGeneratorInterface $schemaGenerator,
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    #[McpResource(
        uri: 'sulu://templates',
        name: 'sulu_templates',
        description: 'Available Sulu templates grouped by content type. Top-level keys are `page`, `article`, and `snippet` (any type with no templates installed is omitted). Each entry maps a template key to `{key, label, schema}`, where `schema` is a JSON Schema for the flat content payload the template accepts. Each schema property also carries `x-sulu-type` — the underlying Sulu field type (see `fieldTypes` in sulu_get_context) — since JSON Schema itself only expresses JSON types. Use the template key when creating or updating content of that type.',
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
        foreach ($typedMetadata->getForms() as $key => $formMetadata) {
            $result[(string) $key] = [
                'key' => $formMetadata->getKey(),
                'label' => $formMetadata->getTitle('en'),
                'schema' => $this->schemaGenerator->generate($formMetadata->getItems(), 'en'),
            ];
        }

        return $result;
    }
}
