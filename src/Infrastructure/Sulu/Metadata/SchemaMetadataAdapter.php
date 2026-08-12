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

namespace Sulu\Mcp\Infrastructure\Sulu\Metadata;

use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\ItemMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SchemaMetadataProvider;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Mcp\Application\Metadata\FieldSchemaGeneratorInterface;

/**
 * @internal
 */
final class SchemaMetadataAdapter implements FieldSchemaGeneratorInterface
{
    /** @var array<string, TypedFormMetadata> */
    private array $globalBlockCatalogueByLocale = [];

    public function __construct(
        private readonly SchemaMetadataProvider $schemaMetadataProvider,
        private readonly MetadataProviderInterface $formMetadataProvider,
    ) {
    }

    public function generate(array $items, string $locale): array
    {
        $schema = $this->asMap($this->schemaMetadataProvider->getMetadata($items)->toJsonSchema());

        /** @var array<string, array<string, mixed>> $definitions */
        $definitions = [];
        $schema = $this->annotateProperties($schema, $items, $locale, $definitions, []);

        if ([] !== $definitions) {
            $schema['definitions'] = $definitions;
        }

        return $schema;
    }

    /**
     * @param array<string, mixed>                $node         a schema fragment with a 'properties' map
     * @param ItemMetadata[]                      $items        the items $node's 'properties' were generated from
     * @param array<string, array<string, mixed>> &$definitions accumulator: global block name => its own schema
     * @param array<string, true>                 $visiting     global block names on the current resolution path
     *
     * @return array<string, mixed>
     */
    private function annotateProperties(array $node, array $items, string $locale, array &$definitions, array $visiting): array
    {
        if (!isset($node['properties'])) {
            $properties = [];
        } elseif (\is_array($node['properties'])) {
            $properties = $this->asMap($node['properties']);
        } else {
            return $node;
        }

        foreach ($this->flattenFields($items) as $field) {
            $name = $field->getName();
            $propertySchema = $this->asMap($properties[$name] ?? null);
            $propertySchema['x-sulu-type'] = $field->getType();

            if ('block' === $field->getType()) {
                $propertySchema = $this->annotateBlockField($propertySchema, $field, $locale, $definitions, $visiting);
            }

            $properties[$name] = $propertySchema;
        }

        if ([] !== $properties) {
            $node['properties'] = $properties;
        }

        return $node;
    }

    /**
     * @param array<string, mixed>                $blockPropertySchema
     * @param array<string, array<string, mixed>> &$definitions
     * @param array<string, true>                 $visiting
     *
     * @return array<string, mixed>
     */
    private function annotateBlockField(array $blockPropertySchema, FieldMetadata $field, string $locale, array &$definitions, array $visiting): array
    {
        $itemsSchema = $blockPropertySchema['items'] ?? null;
        $allOfRaw = \is_array($itemsSchema) ? ($itemsSchema['allOf'] ?? null) : null;
        if (!\is_array($allOfRaw)) {
            return $blockPropertySchema;
        }

        $allOf = [];
        foreach ($allOfRaw as $index => $entryRaw) {
            $entry = $this->asMap($entryRaw);

            $if = $this->asMap($entry['if'] ?? null);
            $ifProperties = $this->asMap($if['properties'] ?? null);
            $typeConst = $this->asMap($ifProperties['type'] ?? null);
            $typeKey = $typeConst['const'] ?? null;

            $blockType = \is_string($typeKey) ? ($field->getTypes()[$typeKey] ?? null) : null;
            if (!$blockType instanceof FormMetadata) {
                $allOf[$index] = $entry;

                continue;
            }

            $then = $this->asMap($entry['then'] ?? null);
            if (isset($then['$ref'])) {
                $this->resolveGlobalBlockDefinition($typeKey, $locale, $definitions, $visiting);
                $allOf[$index] = $entry;

                continue;
            }

            $then = $this->annotateProperties($then, $blockType->getItems(), $locale, $definitions, $visiting);
            $then['title'] = $blockType->getTitle($locale);
            $entry['then'] = $then;
            $allOf[$index] = $entry;
        }

        $itemsSchema = $this->asMap($itemsSchema);
        $itemsSchema['allOf'] = \array_values($allOf);
        $blockPropertySchema['items'] = $itemsSchema;

        return $blockPropertySchema;
    }

    /**
     * @param array<string, array<string, mixed>> &$definitions
     * @param array<string, true>                 $visiting
     */
    private function resolveGlobalBlockDefinition(string $name, string $locale, array &$definitions, array $visiting): void
    {
        if (isset($definitions[$name]) || isset($visiting[$name])) {
            // Cycle guard: Sulu's own resolver has none and would recurse forever.
            return;
        }

        $globalForm = $this->getGlobalBlockForm($name, $locale);
        if (!$globalForm instanceof FormMetadata) {
            $definitions[$name] = ['type' => 'object'];

            return;
        }

        $visiting[$name] = true;
        $items = $globalForm->getItems();
        $schema = $this->asMap($this->schemaMetadataProvider->getMetadata($items)->toJsonSchema());
        $schema = $this->annotateProperties($schema, $items, $locale, $definitions, $visiting);
        $schema['title'] = $globalForm->getTitle($locale);
        $definitions[$name] = $schema;
    }

    private function getGlobalBlockForm(string $name, string $locale): ?FormMetadata
    {
        $catalogue = $this->globalBlockCatalogueByLocale[$locale] ??= $this->loadGlobalBlockCatalogue($locale);

        return $catalogue->getForms()[$name] ?? null;
    }

    private function loadGlobalBlockCatalogue(string $locale): TypedFormMetadata
    {
        $metadata = $this->formMetadataProvider->getMetadata('block', $locale, ['ignore_global_blocks' => true]);

        return $metadata instanceof TypedFormMetadata ? $metadata : new TypedFormMetadata();
    }

    /**
     * @param ItemMetadata[] $items
     *
     * @return list<FieldMetadata>
     */
    private function flattenFields(array $items): array
    {
        $fields = [];
        foreach ($items as $item) {
            if ($item instanceof SectionMetadata) {
                foreach ($this->flattenFields($item->getItems()) as $nested) {
                    $fields[] = $nested;
                }

                continue;
            }

            if ($item instanceof FieldMetadata) {
                $fields[] = $item;
            }
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private function asMap(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }
}
