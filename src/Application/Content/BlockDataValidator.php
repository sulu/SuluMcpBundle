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
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;

/**
 * Validates block field data against the block type's schema.
 *
 * Without this check, the MCP layer accepted any keys in blockData and forwarded
 * them to Sulu, where they were stored verbatim. The admin UI then read from the
 * expected template field keys and showed empty blocks, while the read-side
 * normalizer flattened bogus `{name, value}` pairs and hid the corruption.
 *
 * @internal
 */
final readonly class BlockDataValidator
{
    public function __construct(
        private MetadataProviderInterface $formMetadataProvider,
    ) {
    }

    /**
     * @param array<string, mixed> $blockData Normalized blockData (flat object form)
     *
     * @return array<string, mixed>|null Error payload, or null when valid
     */
    public function validate(
        string $contentType,
        ?string $templateKey,
        string $blockType,
        array $blockData,
    ): ?array {
        if ($error = $this->rejectNameValuePattern($blockType, $blockData)) {
            return $error;
        }

        $form = $this->findBlockTypeForm($contentType, $templateKey, $blockType);

        if (!$form instanceof FormMetadata) {
            // Block type not discoverable in metadata: skip strict validation rather
            // than blocking legitimate use of project-specific or inline block types
            // whose definitions we cannot resolve here.
            return null;
        }

        return $this->validateKeys($blockType, $blockData, $form);
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
        return $this->validateBlockLists($content, $contentType, $templateKey);
    }

    /**
     * Validate every block found in the list-valued entries of $node, recursing
     * into each block's own list-valued fields (nested blocks).
     *
     * @param array<array-key, mixed> $node
     *
     * @return array<string, mixed>|null
     */
    private function validateBlockLists(array $node, string $contentType, ?string $templateKey): ?array
    {
        foreach ($node as $value) {
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

                // Resolve the block form once and reuse it for both key and
                // required-field validation, instead of looking it up twice.
                $form = $this->findBlockTypeForm($contentType, $templateKey, $blockType);
                if ($form instanceof FormMetadata) {
                    if ($error = $this->validateKeys($blockType, $item, $form)) {
                        return $error;
                    }

                    if ($error = $this->validateRequiredFields($blockType, $item, $form)) {
                        return $error;
                    }
                }

                if ($error = $this->validateBlockLists($item, $contentType, $templateKey)) {
                    return $error;
                }
            }
        }

        return null;
    }

    /**
     * Detect the `[{"name": "field", "value": "..."}]` storage-shape pattern that
     * AI clients sometimes emit. This shape is silently stored by Sulu and breaks
     * the admin UI -- give a tailored message before generic key validation.
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
     * Return the FormMetadata describing $blockType inside $contentType templates,
     * or null when the block type cannot be discovered (caller should skip strict
     * checks in that case).
     */
    private function findBlockTypeForm(string $contentType, ?string $templateKey, string $blockType): ?FormMetadata
    {
        $form = $this->findInTemplates($contentType, $templateKey, $blockType);
        if ($form instanceof FormMetadata) {
            return $form;
        }

        return $this->findInGlobalBlocks($blockType);
    }

    private function findInTemplates(string $contentType, ?string $templateKey, string $blockType): ?FormMetadata
    {
        try {
            $typed = $this->formMetadataProvider->getMetadata($contentType, 'en', []);
        } catch (\Throwable) {
            return null;
        }

        if (!$typed instanceof TypedFormMetadata) {
            return null;
        }

        $forms = $typed->getForms();
        $candidates = null !== $templateKey && isset($forms[$templateKey])
            ? [$templateKey => $forms[$templateKey]]
            : $forms;

        foreach ($candidates as $form) {
            $blockForm = $this->scanFormForBlockType($form, $blockType);
            if ($blockForm instanceof FormMetadata) {
                return $blockForm;
            }
        }

        return null;
    }

    private function scanFormForBlockType(FormMetadata $form, string $blockType): ?FormMetadata
    {
        foreach ($form->getFlatFieldMetadata() as $item) {
            if ('block' !== $item->getType()) {
                continue;
            }

            foreach ($item->getTypes() as $typeName => $blockForm) {
                if ($typeName === $blockType && [] !== $blockForm->getItems()) {
                    return $blockForm;
                }

                // Recurse into nested block definitions
                $nested = $this->scanFormForBlockType($blockForm, $blockType);
                if ($nested instanceof FormMetadata) {
                    return $nested;
                }
            }
        }

        return null;
    }

    private function findInGlobalBlocks(string $blockType): ?FormMetadata
    {
        try {
            $typed = $this->formMetadataProvider->getMetadata('block', 'en', ['ignore_global_blocks' => true]);
        } catch (\Throwable) {
            return null;
        }

        if (!$typed instanceof TypedFormMetadata) {
            return null;
        }

        $form = $typed->getForms()[$blockType] ?? null;
        if (!$form instanceof FormMetadata || [] === $form->getItems()) {
            return null;
        }

        return $form;
    }

    /** @return list<string> */
    private function extractFieldNames(FormMetadata $form): array
    {
        return \array_keys($form->getFlatFieldMetadata());
    }
}
