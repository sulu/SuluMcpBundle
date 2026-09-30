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

namespace Sulu\Mcp\Tests\Unit\Fixture;

use Sulu\Mcp\Domain\Content\ContentSecurity;
use Sulu\Mcp\Domain\Content\ContentTypeExtensionInterface;

/**
 * @internal
 */
class FakeContentTypeExtension implements ContentTypeExtensionInterface
{
    public function __construct(
        private readonly string $type = 'widget',
        private readonly string $resourceKey = 'widgets',
        private readonly string $securityContext = 'sulu.widget.widgets',
        private readonly ?object $draft = null,
        private readonly ?object $transitionEntity = null,
    ) {
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTemplateType(): string
    {
        return $this->type;
    }

    public function getResourceKey(): string
    {
        return $this->resourceKey;
    }

    public function getViewSecurityContexts(): array
    {
        return [$this->securityContext];
    }

    public function getSecurity(object $aggregate, string $locale): ContentSecurity
    {
        return new ContentSecurity($this->securityContext);
    }

    public function loadDraft(string $uuid, string $locale, bool $loadGhost = false): ?object
    {
        return $this->draft;
    }

    public function loadForTransition(string $uuid, string $locale): ?object
    {
        return $this->transitionEntity;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createModifyMessage(string $uuid, array $data): object
    {
        return (object) ['kind' => 'modify', 'uuid' => $uuid, 'data' => $data];
    }

    public function createRemoveMessage(string $uuid, string $locale, bool $forceRemoveChildren = false): object
    {
        return (object) ['kind' => 'remove', 'uuid' => $uuid, 'locale' => $locale, 'forceRemoveChildren' => $forceRemoveChildren];
    }

    public function createTransitionMessage(string $uuid, string $locale, string $transition): object
    {
        return (object) ['kind' => 'transition', 'uuid' => $uuid, 'locale' => $locale, 'transition' => $transition];
    }
}
