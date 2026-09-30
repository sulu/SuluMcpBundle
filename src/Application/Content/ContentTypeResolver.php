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

    public function supports(string $type): bool
    {
        return null !== $this->find($type);
    }

    /**
     * @return list<string>
     */
    public function supportedTypes(): array
    {
        return $this->extensionRegistry->types();
    }

    /**
     * @return list<ContentTypeExtensionInterface>
     */
    public function all(): array
    {
        return $this->extensionRegistry->all();
    }

    public function find(string $type): ?ContentTypeExtensionInterface
    {
        return $this->extensionRegistry->find($type);
    }

    public function get(string $type): ContentTypeExtensionInterface
    {
        return $this->find($type) ?? throw new \InvalidArgumentException(\sprintf(
            'Unsupported content type "%s". Supported: %s.',
            $type,
            \implode(', ', $this->supportedTypes()),
        ));
    }

    /**
     * $loadGhost is opt-in: the aggregate then spans every locale, so check ContentLocaleTrait first.
     */
    public function loadDraft(string $type, string $uuid, string $locale, bool $loadGhost = false): ?object
    {
        try {
            return $this->find($type)?->loadDraft($uuid, $locale, $loadGhost);
        } catch (\Throwable) {
            return null;
        }
    }

    public function loadForTransition(string $type, string $uuid, string $locale): ?object
    {
        try {
            return $this->find($type)?->loadForTransition($uuid, $locale);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createModifyMessage(string $type, string $uuid, array $data): object
    {
        return $this->get($type)->createModifyMessage($uuid, $data);
    }

    /**
     * `forceRemoveChildren` only affects pages.
     */
    public function createRemoveMessage(string $type, string $uuid, string $locale, bool $forceRemoveChildren = false): object
    {
        return $this->get($type)->createRemoveMessage($uuid, $locale, $forceRemoveChildren);
    }

    /**
     * Build the per-type workflow transition message (e.g. 'publish' / 'unpublish').
     */
    public function createTransitionMessage(string $type, string $uuid, string $locale, string $transition): object
    {
        return $this->get($type)->createTransitionMessage($uuid, $locale, $transition);
    }
}
