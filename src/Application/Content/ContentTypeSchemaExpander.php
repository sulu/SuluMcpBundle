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

use Mcp\Schema\Tool;

/**
 * @phpstan-import-type ToolInputSchema from Tool
 *
 * @internal
 */
final readonly class ContentTypeSchemaExpander
{
    /**
     * Skips types marked NotSearchableContentTypeInterface.
     */
    public const SEARCHABLE_RESOURCE_KEYS = '{searchableResourceKeys}';

    public const RESOURCE_KEYS = '{resourceKeys}';

    /**
     * Every resource key the admin has a `detail` view for, content type or not.
     */
    public const ADMIN_LINK_RESOURCE_KEYS = '{adminLinkResourceKeys}';

    /**
     * @param array<string, array{views?: array<string, string>}> $adminResources the `sulu_admin.resources` parameter
     */
    public function __construct(
        private ContentTypeExtensionRegistry $extensionRegistry,
        private array $adminResources = [],
    ) {
    }

    public function expandText(?string $text): ?string
    {
        if (null === $text || !\str_contains($text, '{')) {
            return $text;
        }

        return \str_replace(
            [self::SEARCHABLE_RESOURCE_KEYS, self::RESOURCE_KEYS, self::ADMIN_LINK_RESOURCE_KEYS],
            [$this->quoted($this->extensionRegistry->searchableResourceKeys()), $this->quoted($this->extensionRegistry->resourceKeys()), $this->quoted($this->adminLinkResourceKeys())],
            $text,
        );
    }

    /**
     * @param ToolInputSchema $schema
     *
     * @return ToolInputSchema
     */
    public function expandInputSchema(array $schema): array
    {
        /** @var ToolInputSchema $expanded */
        $expanded = $this->expandNode($schema);

        return $expanded;
    }

    /**
     * @param array<array-key, mixed> $schema
     *
     * @return array<array-key, mixed>
     */
    private function expandNode(array $schema): array
    {
        foreach ($schema as $key => $value) {
            if ('enum' === $key && [self::SEARCHABLE_RESOURCE_KEYS] === $value) {
                $schema[$key] = $this->extensionRegistry->searchableResourceKeys();
            } elseif ('enum' === $key && [self::RESOURCE_KEYS] === $value) {
                $schema[$key] = $this->extensionRegistry->resourceKeys();
            } elseif ('enum' === $key && [self::ADMIN_LINK_RESOURCE_KEYS] === $value) {
                $schema[$key] = $this->adminLinkResourceKeys();
            } elseif ('description' === $key && \is_string($value)) {
                $schema[$key] = $this->expandText($value);
            } elseif (\is_array($value)) {
                $schema[$key] = $this->expandNode($value);
            }
        }

        return $schema;
    }

    /**
     * @return list<string>
     */
    private function adminLinkResourceKeys(): array
    {
        $keys = [];
        foreach ($this->adminResources as $resourceKey => $resource) {
            if (isset($resource['views']['detail'])) {
                $keys[] = (string) $resourceKey;
            }
        }
        \sort($keys);

        return $keys;
    }

    /**
     * @param list<string> $resourceKeys
     */
    private function quoted(array $resourceKeys): string
    {
        return \implode(', ', \array_map(static fn (string $key): string => \sprintf('"%s"', $key), $resourceKeys));
    }
}
