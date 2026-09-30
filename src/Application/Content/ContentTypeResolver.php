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

/**
 * @internal
 */
final readonly class ContentTypeResolver
{
    public function __construct(
        private ContentTypeExtensionRegistry $extensionRegistry,
    ) {
    }

    public function supports(string $resourceKey): bool
    {
        return null !== $this->find($resourceKey);
    }

    /**
     * @return list<string>
     */
    public function supportedResourceKeys(): array
    {
        return $this->extensionRegistry->resourceKeys();
    }

    /**
     * @return list<ContentTypeExtensionInterface>
     */
    public function all(): array
    {
        return $this->extensionRegistry->all();
    }

    public function find(string $resourceKey): ?ContentTypeExtensionInterface
    {
        return $this->extensionRegistry->find($resourceKey);
    }

    public function get(string $resourceKey): ContentTypeExtensionInterface
    {
        return $this->find($resourceKey) ?? throw new \InvalidArgumentException(\sprintf(
            'Unsupported content type "%s". Supported: %s.',
            $resourceKey,
            \implode(', ', $this->supportedResourceKeys()),
        ));
    }

    /**
     * $loadGhost is opt-in: the aggregate then spans every locale, so check ContentLocaleTrait first.
     */
    public function loadDraft(string $resourceKey, string $uuid, string $locale, bool $loadGhost = false): ?object
    {
        try {
            return $this->find($resourceKey)?->loadDraft($uuid, $locale, $loadGhost);
        } catch (\Throwable) {
            return null;
        }
    }

    public function loadForTransition(string $resourceKey, string $uuid, string $locale): ?object
    {
        try {
            return $this->find($resourceKey)?->loadForTransition($uuid, $locale);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createModifyMessage(string $resourceKey, string $uuid, array $data): object
    {
        return $this->get($resourceKey)->createModifyMessage($uuid, $data);
    }

    /**
     * `forceRemoveChildren` only affects pages.
     */
    public function createRemoveMessage(string $resourceKey, string $uuid, string $locale, bool $forceRemoveChildren = false): object
    {
        return $this->get($resourceKey)->createRemoveMessage($uuid, $locale, $forceRemoveChildren);
    }

    /**
     * Build the per-type workflow transition message (e.g. 'publish' / 'unpublish').
     */
    public function createTransitionMessage(string $resourceKey, string $uuid, string $locale, string $transition): object
    {
        return $this->get($resourceKey)->createTransitionMessage($uuid, $locale, $transition);
    }
}
