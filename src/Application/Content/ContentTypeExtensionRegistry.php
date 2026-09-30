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

use Sulu\Mcp\Domain\Content\ContentTypeExtensionInterface;
use Sulu\Mcp\Domain\Content\NotSearchableContentTypeInterface;

/**
 * @internal
 */
final class ContentTypeExtensionRegistry
{
    /**
     * Grants access if the caller has VIEW on any registered extension's security contexts.
     */
    public const ANY_EXTENSION_CONTEXT = 'sulu.mcp.content_type_extension.#any#';

    /**
     * @var array<string, ContentTypeExtensionInterface>
     */
    private readonly array $byResourceKey;

    /**
     * @param iterable<ContentTypeExtensionInterface> $extensions
     */
    public function __construct(iterable $extensions)
    {
        $byResourceKey = [];
        foreach ($extensions as $extension) {
            $resourceKey = $extension->getResourceKey();

            if (isset($byResourceKey[$resourceKey])) {
                throw new \LogicException(\sprintf('Resource key "%s" is registered twice, by "%s" and "%s".', $resourceKey, $byResourceKey[$resourceKey]::class, $extension::class));
            }

            $byResourceKey[$resourceKey] = $extension;
        }

        $this->byResourceKey = $byResourceKey;
    }

    public function has(string $resourceKey): bool
    {
        return isset($this->byResourceKey[$resourceKey]);
    }

    public function get(string $resourceKey): ContentTypeExtensionInterface
    {
        return $this->byResourceKey[$resourceKey] ?? throw new \InvalidArgumentException(\sprintf('No content type extension registered for resourceKey "%s".', $resourceKey));
    }

    public function find(string $resourceKey): ?ContentTypeExtensionInterface
    {
        return $this->byResourceKey[$resourceKey] ?? null;
    }

    /**
     * @return list<string>
     */
    public function resourceKeys(): array
    {
        return \array_keys($this->byResourceKey);
    }

    /**
     * @return list<ContentTypeExtensionInterface>
     */
    public function all(): array
    {
        return \array_values($this->byResourceKey);
    }

    /**
     * @return list<ContentTypeExtensionInterface>
     */
    public function searchable(): array
    {
        return \array_values(\array_filter(
            $this->byResourceKey,
            static fn (ContentTypeExtensionInterface $extension): bool => !$extension instanceof NotSearchableContentTypeInterface,
        ));
    }

    /**
     * @return list<string>
     */
    public function searchableResourceKeys(): array
    {
        return \array_map(static fn (ContentTypeExtensionInterface $extension): string => $extension->getResourceKey(), $this->searchable());
    }
}
