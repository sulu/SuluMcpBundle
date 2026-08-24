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

use Sulu\Content\Domain\Model\ContentRichEntityInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;

/**
 * Tells a not-yet-translated locale apart from one that has content of its own.
 *
 * Sulu calls an entity viewed in a locale it has no content in a "ghost"; the
 * persisted `ghostLocale` on the unlocalized dimension names the locale it is a
 * ghost of. `loadGhost` is what makes such an entity findable at all -- a plain
 * locale filter does not match it. The merge then covers the unlocalized
 * dimension only, so the resolved locale stays null; that is the signal used here.
 *
 * @internal
 */
trait ContentLocaleTrait
{
    /**
     * @template T of ContentRichEntityInterface
     *
     * @param DimensionContentInterface<T> $dimensionContent
     */
    private static function isMissingTranslation(DimensionContentInterface $dimensionContent, string $locale): bool
    {
        return $locale !== $dimensionContent->getLocale();
    }

    /**
     * @template T of ContentRichEntityInterface
     *
     * @param DimensionContentInterface<T> $dimensionContent
     *
     * @return array{error: string, hint: string}
     */
    private static function missingTranslationError(
        string $label,
        string $uuid,
        string $locale,
        DimensionContentInterface $dimensionContent,
        string $hint,
    ): array {
        $translatedLocales = $dimensionContent->getAvailableLocales() ?? [];

        return [
            'error' => \sprintf('%s %s has no "%s" content yet.', $label, $uuid, $locale),
            'hint' => \sprintf(
                '%s Existing locales: %s.',
                $hint,
                [] !== $translatedLocales ? \implode(', ', $translatedLocales) : 'none',
            ),
        ];
    }
}
