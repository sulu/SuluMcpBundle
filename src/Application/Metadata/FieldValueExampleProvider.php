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
 * Supplies a value-shape example (and an optional hint) per Sulu field type so
 * get_context can show AI clients HOW to fill a property, not just its type name.
 *
 * Scope is intentionally limited to the common scalar types. Complex selection
 * types (media_selection, smart_content, *_selection, …) store project- or
 * content-type-specific shapes that are not derivable from form metadata, so they
 * are deliberately omitted (describe() returns null and no example is emitted)
 * rather than risking a wrong guess.
 *
 * @internal
 */
final class FieldValueExampleProvider
{
    /**
     * @var array<string, array{example: mixed, hint: string|null}>
     */
    private const EXAMPLES = [
        'text_line' => ['example' => 'Example text', 'hint' => null],
        'text_area' => ['example' => "First line\nSecond line", 'hint' => 'Plain multi-line text, no HTML.'],
        'text_editor' => [
            'example' => '<p>Example <strong>rich</strong> text with an <sulu-link href="REPLACE-WITH-TARGET-UUID" provider="page">internal link</sulu-link>.</p>',
            'hint' => 'HTML (CKEditor). For an INTERNAL link use <sulu-link href="<target-uuid>" provider="page|article|media">text</sulu-link> — "provider" defaults to "page" and "href" is the target content UUID. For an EXTERNAL link use a normal <a href="https://…">. Do not wrap the value in <html>/<body>.',
        ],
        'number' => ['example' => 42, 'hint' => null],
        'checkbox' => ['example' => true, 'hint' => null],
        'color' => ['example' => '#1a2b3c', 'hint' => null],
        'date' => ['example' => '2026-06-03', 'hint' => 'ISO date (Y-m-d).'],
        'time' => ['example' => '13:30', 'hint' => null],
        'email' => ['example' => 'hello@example.com', 'hint' => null],
        'url' => ['example' => 'https://example.com', 'hint' => null],
        'phone' => ['example' => '+49 30 1234567', 'hint' => null],
    ];

    /**
     * @return array{example: mixed, hint: string|null}|null
     */
    public function describe(string $type): ?array
    {
        return self::EXAMPLES[$type] ?? null;
    }
}
