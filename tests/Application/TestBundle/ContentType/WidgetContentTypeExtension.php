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

namespace Sulu\Mcp\Tests\Application\TestBundle\ContentType;

use Sulu\Mcp\Domain\Content\ContentSecurity;
use Sulu\Mcp\Domain\Content\ContentTypeExtensionInterface;

/**
 * Stands in for a bundle like SuluProductBundle, deliberately named "widgets".
 */
final class WidgetContentTypeExtension implements ContentTypeExtensionInterface
{
    public function getType(): string
    {
        return 'widget';
    }

    public function getTemplateType(): string
    {
        return 'widget';
    }

    public function getResourceKey(): string
    {
        return 'widgets';
    }

    public function getViewSecurityContexts(): array
    {
        return ['sulu.mcp_test.widgets'];
    }

    public function getSecurity(object $aggregate): ContentSecurity
    {
        return new ContentSecurity('sulu.mcp_test.widgets');
    }

    public function loadDraft(string $uuid, string $locale, bool $loadGhost = false): ?object
    {
        return null;
    }

    public function loadForTransition(string $uuid, string $locale): ?object
    {
        return null;
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
        return (object) ['kind' => 'remove', 'uuid' => $uuid, 'locale' => $locale];
    }

    public function createTransitionMessage(string $uuid, string $locale, string $transition): object
    {
        return (object) ['kind' => 'transition', 'uuid' => $uuid, 'locale' => $locale, 'transition' => $transition];
    }
}
