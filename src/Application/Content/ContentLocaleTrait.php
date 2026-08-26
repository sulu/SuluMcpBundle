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
 * A loadGhost query also returns the entity in a locale it has no content in. The
 * merge then covers the unlocalized dimension only, so the resolved locale stays null.
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

    /**
     * @template T of ContentRichEntityInterface
     *
     * @param DimensionContentInterface<T> $dimensionContent
     *
     * @return array{error: string, hint: string}|null null when the translation exists
     */
    private static function missingBlockTranslationError(
        DimensionContentInterface $dimensionContent,
        string $type,
        string $uuid,
        string $locale,
    ): ?array {
        if (!self::isMissingTranslation($dimensionContent, $locale)) {
            return null;
        }

        return self::missingTranslationError(
            \ucfirst($type),
            $uuid,
            $locale,
            $dimensionContent,
            \sprintf('Create the "%s" translation with sulu_%s_update first, then work on its blocks.', $locale, $type),
        );
    }
}
