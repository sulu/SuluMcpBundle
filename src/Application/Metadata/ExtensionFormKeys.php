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

namespace Sulu\Mcp\Application\Metadata;

/**
 * Resolves the form keys that make up the seo / excerpt tabs.
 *
 * Sulu collects every form tagged `sulu_content.content_excerpt_form` /
 * `sulu_content.content_seo_form` (core and project forms alike) into the
 * `sulu_content.content_excerpt_forms` / `sulu_content.content_seo_forms`
 * parameters, already ordered by priority (highest first) by `ExcerptFormPass` /
 * `SeoFormPass`. Reading them instead of hard-coding the core keys makes project
 * extensions (e.g. a custom `excerpt/media` field) available to the MCP tools.
 *
 * Every registered form counts for every resource type (pages, articles,
 * snippets). The admin applies a form only when the content class matches its
 * `instanceOf` (see `InstanceOfFormMetadataVisitor`); that filter is not applied here.
 *
 * @internal
 */
final readonly class ExtensionFormKeys
{
    /**
     * @param array<string, array{instanceOf: class-string, priority: int}> $excerptForms
     * @param array<string, array{instanceOf: class-string, priority: int}> $seoForms
     */
    public function __construct(
        private array $excerptForms,
        private array $seoForms,
    ) {
    }

    /**
     * @return list<string>
     */
    public function excerpt(): array
    {
        return \array_map(\strval(...), \array_keys($this->excerptForms));
    }

    /**
     * @return list<string>
     */
    public function seo(): array
    {
        return \array_map(\strval(...), \array_keys($this->seoForms));
    }
}
