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

use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Mcp\Application\Metadata\MetadataLocaleResolver;

/**
 * Validates block field data against the block type's schema.
 *
 * Without this check, the MCP layer accepted any keys in blockData and forwarded
 * them to Sulu, where they were stored verbatim. The admin UI then read from the
 * expected template field keys and showed empty blocks, while the read-side
 * normalizer flattened bogus `{name, value}` pairs and hid the corruption.
 *
 * A block type name is only unique within the block property that declares it, and
 * `item` is used by nearly every card list, so every lookup here walks the chain of
 * (block property, block type) steps down from the template form instead of
 * searching the metadata for a matching name.
 *
 * @internal
 */
final readonly class BlockDataValidator
{
    private const GLOBAL_BLOCK_TAG = 'sulu.global_block';

    public function __construct(
        private MetadataProviderInterface $formMetadataProvider,
        private MetadataLocaleResolver $localeResolver,
    ) {
    }

    /**
     * @param list<array{property: string, type: string}> $blockPath Chain of (block property, block type)
     *                                                               steps from the template form down to
     *                                                               and including the block to validate.
     *                                                               Empty when the caller cannot locate the
     *                                                               block: schema checks are then skipped,
     *                                                               the storage-shape guard still runs
     * @param array<string, mixed> $blockData Normalized blockData (flat object form)
     *
     * @return array<string, mixed>|null Error payload, or null when valid
     */
    public function validate(
        string $contentType,
        ?string $templateKey,
        string $blockType,
        array $blockPath,
        array $blockData,
    ): ?array {
        if ($error = $this->rejectNameValuePattern($blockType, $blockData)) {
            return $error;
        }

        if ([] === $blockPath) {
            return null;
        }

        $form = $this->resolveBlockPath($contentType, $templateKey, $blockPath);

        if (!$form instanceof FormMetadata) {
            // Block type not discoverable in metadata: skip strict validation rather
            // than blocking legitimate use of project-specific or inline block types
            // whose definitions we cannot resolve here.
            return null;
        }

        // Name the type the schema was actually resolved for, which is what the message
        // describes, rather than the caller's label for the same block.
        return $this->validateKeys($blockPath[\array_key_last($blockPath)]['type'], $blockData, $form);
    }

    /**
     * Return the block property of the form at $parentPath that offers $blockType.
     *
     * Answers "where does a block of this type belong inside this parent", which the
     * current content cannot answer: a list that is still empty is indistinguishable
     * from one that does not exist, and a parent may offer several block properties.
     * Null when the parent form is undiscoverable or offers the type in no property,
     * or in more than one, so the caller can fall back to its own guess.
     *
     * @param list<array{property: string, type: string}> $parentPath
     */
    public function resolveBlockProperty(
        string $contentType,
        ?string $templateKey,
        array $parentPath,
        string $blockType,
    ): ?string {
        if ([] === $parentPath) {
            return null;
        }

        $parentForm = $this->resolveBlockPath($contentType, $templateKey, $parentPath);
        if (!$parentForm instanceof FormMetadata) {
            return null;
        }

        $properties = [];
        foreach ($parentForm->getFlatFieldMetadata() as $name => $field) {
            if (isset($field->getTypes()[$blockType])) {
                $properties[] = $name;
            }
        }

        return 1 === \count($properties) ? $properties[0] : null;
    }

    /**
     * Recursively validate every block in a content tree (template field values),
     * descending into nested block lists. Returns the first error payload found,
     * or null when the whole tree is valid. Used by create/update tools to reject
     * an invalid one-shot draft before any write.
     *
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>|null
     */
    public function validateContentTree(array $content, string $contentType, ?string $templateKey): ?array
    {
        return $this->validateBlockLists($content, $this->templateForms($contentType, $templateKey));
    }

    /**
     * Reject any blockData key that is not a field of $form (ignoring the internal
     * `type`, `_id`, and `settings*` keys).
     *
     * @param array<array-key, mixed> $blockData
     *
     * @return array<string, mixed>|null
     */
    private function validateKeys(string $blockType, array $blockData, FormMetadata $form): ?array
    {
        $validKeys = $this->extractFieldNames($form);

        $invalidKeys = [];
        foreach (\array_keys($blockData) as $key) {
            if ('type' === $key || '_id' === $key || \str_starts_with((string) $key, 'settings')) {
                continue;
            }
            if (!\in_array((string) $key, $validKeys, true)) {
                $invalidKeys[] = (string) $key;
            }
        }

        if ([] === $invalidKeys) {
            return null;
        }

        return [
            'error' => \sprintf(
                'Unknown keys for block type "%s": %s. Valid keys: %s.',
                $blockType,
                \implode(', ', $invalidKeys),
                \implode(', ', $validKeys),
            ),
            'hint' => 'Pass blockData as a flat object whose keys are template field names, e.g. blockData={"title": "...", "description": "<p>...</p>"}. Use sulu_get_context to inspect block type field schemas.',
        ];
    }

    /**
     * Validate every block found in the list-valued entries of $node, recursing into
     * each block's own list-valued fields (nested blocks). $parentForms are the forms
     * whose fields the keys of $node are looked up in: the candidate template forms at
     * the root, the single resolved block form below that.
     *
     * @param array<array-key, mixed> $node
     * @param list<FormMetadata> $parentForms
     *
     * @return array<string, mixed>|null
     */
    private function validateBlockLists(array $node, array $parentForms): ?array
    {
        foreach ($node as $propertyName => $value) {
            if (!\is_array($value) || !\array_is_list($value)) {
                continue;
            }

            foreach ($value as $item) {
                if (!\is_array($item) || !isset($item['type']) || !\is_string($item['type'])) {
                    continue;
                }

                $blockType = $item['type'];

                if ($error = $this->rejectNameValuePattern($blockType, $item)) {
                    return $error;
                }

                // Resolve the block form once and reuse it for key validation, required
                // field validation, and as the scope of the block's own nested lists.
                $form = $this->resolveBlockType($parentForms, (string) $propertyName, $blockType);
                if ($form instanceof FormMetadata) {
                    if ($error = $this->validateKeys($blockType, $item, $form)) {
                        return $error;
                    }

                    if ($error = $this->validateRequiredFields($blockType, $item, $form)) {
                        return $error;
                    }
                }

                if ($error = $this->validateBlockLists($item, $form instanceof FormMetadata ? [$form] : [])) {
                    return $error;
                }
            }
        }

        return null;
    }

    /**
     * Detect the `[{"name": "field", "value": "..."}]` storage-shape pattern that
     * AI clients sometimes emit. This shape is silently stored by Sulu and breaks
     * the admin UI, so give a tailored message before generic key validation.
     *
     * @param array<array-key, mixed> $blockData
     *
     * @return array<string, mixed>|null
     */
    private function rejectNameValuePattern(string $blockType, array $blockData): ?array
    {
        if (
            2 !== \count($blockData)
            || !\array_key_exists('name', $blockData)
            || !\array_key_exists('value', $blockData)
        ) {
            return null;
        }

        return [
            'error' => \sprintf(
                'Block data for "%s" is in Sulu\'s internal {name, value} storage shape, not the API shape. Pass {fieldName: value} directly, e.g. blockData={"%s": "..."} instead of blockData=[{"name": "%s", "value": "..."}].',
                $blockType,
                \is_string($blockData['name']) ? $blockData['name'] : 'fieldName',
                \is_string($blockData['name']) ? $blockData['name'] : 'fieldName',
            ),
            'hint' => 'Use sulu_get_context to see the block type\'s field schema.',
        ];
    }

    /**
     * Reject a one-shot block whose required template fields are missing. Only the
     * recursive tree path (create/update authoring) calls this, so single-block
     * updates stay partial-friendly and skip it. Undiscoverable block types are
     * skipped, consistent with key validation.
     *
     * @param array<array-key, mixed> $blockData
     *
     * @return array<string, mixed>|null
     */
    private function validateRequiredFields(string $blockType, array $blockData, FormMetadata $form): ?array
    {
        $missing = [];
        foreach ($form->getFlatFieldMetadata() as $item) {
            if ($item->isRequired() && !isset($blockData[$item->getName()])) {
                $missing[] = $item->getName();
            }
        }

        if ([] === $missing) {
            return null;
        }

        return [
            'error' => \sprintf('Block type "%s" is missing required field(s): %s.', $blockType, \implode(', ', $missing)),
            'hint' => 'Provide every required field for the block. Use sulu_get_context to see which block fields are required.',
        ];
    }

    /**
     * Walk $blockPath down from each candidate template form and return the form the
     * last step resolves to, or null when no candidate resolves the whole chain.
     *
     * @param list<array{property: string, type: string}> $blockPath
     */
    private function resolveBlockPath(string $contentType, ?string $templateKey, array $blockPath): ?FormMetadata
    {
        foreach ($this->templateForms($contentType, $templateKey) as $templateForm) {
            $form = $templateForm;

            foreach ($blockPath as $step) {
                $resolved = $this->resolveBlockType([$form], $step['property'], $step['type']);
                if (!$resolved instanceof FormMetadata) {
                    continue 2;
                }

                $form = $resolved;
            }

            return $form;
        }

        return null;
    }

    /**
     * Return the form describing $blockType as offered by the $propertyName field of
     * one of $parentForms, or null when no parent offers that type there.
     *
     * @param list<FormMetadata> $parentForms
     */
    private function resolveBlockType(array $parentForms, string $propertyName, string $blockType): ?FormMetadata
    {
        foreach ($parentForms as $parentForm) {
            $field = $parentForm->getFlatFieldMetadata()[$propertyName] ?? null;
            if (!$field instanceof FieldMetadata) {
                continue;
            }

            $type = $field->getTypes()[$blockType] ?? null;
            if (!$type instanceof FormMetadata) {
                continue;
            }

            $form = $this->dereferenceGlobalBlock($type);
            if ($form instanceof FormMetadata) {
                return $form;
            }
        }

        return null;
    }

    /**
     * A block type referencing a global block carries only a `sulu.global_block` tag;
     * its fields live in the separate global block metadata. Resolving that reference
     * is what keeps types nested inside global blocks discoverable.
     */
    private function dereferenceGlobalBlock(FormMetadata $type): ?FormMetadata
    {
        $tag = $type->findTag(self::GLOBAL_BLOCK_TAG);

        if (null === $tag) {
            return [] !== $type->getItems() ? $type : null;
        }

        $name = $tag->getAttribute('global_block');
        if (!\is_string($name)) {
            return null;
        }

        $form = $this->globalBlockForms()[$name] ?? null;
        if (!$form instanceof FormMetadata || [] === $form->getItems()) {
            return null;
        }

        return $form;
    }

    /**
     * The template forms a block path may start from: the addressed template when it
     * is known, every template of the content type otherwise.
     *
     * @return list<FormMetadata>
     */
    private function templateForms(string $contentType, ?string $templateKey): array
    {
        try {
            $typed = $this->formMetadataProvider->getMetadata($contentType, $this->localeResolver->resolve(), []);
        } catch (\Throwable) {
            return [];
        }

        if (!$typed instanceof TypedFormMetadata) {
            return [];
        }

        $forms = $typed->getForms();

        if (null !== $templateKey && isset($forms[$templateKey])) {
            return [$forms[$templateKey]];
        }

        return \array_values($forms);
    }

    /** @return array<array-key, FormMetadata> */
    private function globalBlockForms(): array
    {
        try {
            $typed = $this->formMetadataProvider->getMetadata('block', $this->localeResolver->resolve(), ['ignore_global_blocks' => true]);
        } catch (\Throwable) {
            return [];
        }

        if (!$typed instanceof TypedFormMetadata) {
            return [];
        }

        return $typed->getForms();
    }

    /** @return list<string> */
    private function extractFieldNames(FormMetadata $form): array
    {
        return \array_keys($form->getFlatFieldMetadata());
    }
}
