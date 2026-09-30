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

namespace Sulu\Mcp\Domain\Content;

/**
 * A content type the unified tools work on. Tag an implementation `sulu_mcp.content_type_extension`
 * (autoconfigured) to plug a type in.
 */
interface ContentTypeExtensionInterface
{
    /**
     * The `type` value the unified tools accept, e.g. "product".
     */
    public function getType(): string;

    /**
     * The resourceKey of the SEAL `website` index.
     */
    public function getResourceKey(): string;

    /**
     * The key Sulu's form metadata uses, not the tool parameter.
     */
    public function getTemplateType(): string;

    /**
     * VIEW on any one of these makes the type visible. Empty means webspace permissions govern.
     *
     * @return list<string>
     */
    public function getViewSecurityContexts(): array;

    /**
     * Resolves what it needs from the loaded draft aggregate itself, e.g. an article's template group.
     */
    public function getSecurity(object $aggregate): ContentSecurity;

    /**
     * $loadGhost also matches an entity in a locale it has no content in.
     */
    public function loadDraft(string $uuid, string $locale, bool $loadGhost = false): ?object;

    /**
     * With dimension contents hydrated for draft and live.
     */
    public function loadForTransition(string $uuid, string $locale): ?object;

    /**
     * @param array<string, mixed> $data
     */
    public function createModifyMessage(string $uuid, array $data): object;

    public function createRemoveMessage(string $uuid, string $locale, bool $forceRemoveChildren = false): object;

    public function createTransitionMessage(string $uuid, string $locale, string $transition): object;
}
