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

/**
 * The locales content can be written in: the ones of all webspaces, which is also what the
 * admin offers for snippets, articles and categories (`sulu_core.locales`).
 *
 * Without the check a create or update tool stores a translation in any string it is given.
 * It is then only reachable through a list in that locale.
 *
 * @internal
 */
final readonly class ContentLocales
{
    /**
     * @param list<string> $locales
     */
    public function __construct(
        private array $locales,
    ) {
    }

    /**
     * @return array{error: string, hint: string}|null null when the locale is one of the site's
     */
    public function refusal(string $locale): ?array
    {
        if (\in_array($locale, $this->locales, true)) {
            return null;
        }

        return [
            'error' => \sprintf('"%s" is not a locale of this site.', \mb_substr($locale, 0, 40)),
            'hint' => \sprintf('Use one of: %s.', \implode(', ', $this->locales)),
        ];
    }
}
