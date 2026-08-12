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

use Sulu\Bundle\AdminBundle\Application\BlockIdGenerator\BlockIdGeneratorInterface;

/**
 * Normalizes block data from AI clients and ensures string keys for Sulu compatibility.
 *
 * AI clients (Claude, ChatGPT) sometimes send block data as a list [{"key": "value"}]
 * instead of a flat object {"key": "value"}. This trait provides normalization methods.
 *
 * @internal
 */
trait BlockDataNormalizerTrait
{
    /**
     * Recursively assign a generated _id to every block-shaped sub-array (any array with a string `type`).
     *
     * Walks into all list-typed values so inline-nested children (e.g. a section's `blocks` array)
     * also receive ids — without this, parentBlockId / block_update can't find them later.
     *
     * @param array<array-key, mixed> $block
     *
     * @return array<array-key, mixed>
     */
    private function assignBlockIds(array $block, BlockIdGeneratorInterface $generator): array
    {
        if (isset($block['type']) && \is_string($block['type']) && !isset($block['_id'])) {
            $block['_id'] = $generator->generateId();
        }

        foreach ($block as $key => $value) {
            if (!\is_array($value) || !\array_is_list($value) || [] === $value) {
                continue;
            }
            $block[$key] = \array_map(
                fn (mixed $item) => \is_array($item) ? $this->assignBlockIds($item, $generator) : $item,
                $value,
            );
        }

        return $block;
    }

    /**
     * Normalize blockData from AI clients that may send it as a list.
     *
     * Handles: [{"content": "..."}] -> {"content": "..."}
     * Passes through: {"content": "..."} -> {"content": "..."}
     *
     * Also ensures all keys are strings (Sulu's MetadataResolver requires string keys).
     *
     * @param array<mixed> $blockData
     *
     * @return array<string, mixed>
     */
    private function normalizeBlockData(array $blockData): array
    {
        // If it's a list with one element, extract that element
        if (\array_is_list($blockData) && 1 === \count($blockData) && \is_array($blockData[0])) {
            $blockData = $blockData[0];
        }

        // Ensure all keys are strings (Sulu's MetadataResolver requires string keys)
        return self::stringifyKeys($blockData);
    }

    /**
     * Normalize block order indices from MCP clients.
     *
     * Some clients keep stale schemas and send numeric indices as strings. Treat
     * those as integers while rejecting non-index values.
     *
     * @param array<mixed> $newOrder
     *
     * @return list<int>|null
     */
    private function normalizeBlockOrder(array $newOrder): ?array
    {
        $normalized = [];

        foreach ($newOrder as $index) {
            if (\is_int($index)) {
                $normalized[] = $index;

                continue;
            }

            if (\is_string($index) && \preg_match('/^\d+$/', $index)) {
                $normalized[] = (int) $index;

                continue;
            }

            return null;
        }

        return $normalized;
    }

    /**
     * Recursively convert all array keys to strings.
     * Sulu's MetadataResolver requires string keys (it uses str_contains() on keys).
     *
     * @param array<array-key, mixed> $array
     *
     * @return array<string, mixed>
     */
    private static function stringifyKeys(array $array): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $stringKey = (string) $key;
            $result[$stringKey] = \is_array($value) ? self::stringifyKeys($value) : $value;
        }

        return $result;
    }

    /**
     * Normalize content from AI clients that may send it as a list instead of a flat object.
     *
     * Handles: [{"article": "..."}] → ["article" => "..."]
     * Handles: [{"name": "article", "value": "..."}] → ["article" => "..."]
     * Passes through: {"article": "..."} → ["article" => "..."]
     *
     * @param array<mixed> $content
     *
     * @return array<string, mixed>
     */
    public static function normalizeContent(array $content): array
    {
        if ([] !== $content && !\array_is_list($content)) {
            return self::stringifyKeys($content);
        }

        $normalized = [];
        foreach ($content as $item) {
            if (\is_array($item)) {
                if (isset($item['name'], $item['value']) && \is_string($item['name'])) {
                    $normalized[$item['name']] = $item['value'];
                } else {
                    $normalized = \array_merge($normalized, self::stringifyKeys($item));
                }
            }
        }

        return $normalized;
    }
}
