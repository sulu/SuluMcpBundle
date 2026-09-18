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
 * Shared handling of the "Shadow" setting, which makes one localisation serve another's
 * content instead of maintaining its own.
 *
 * @internal
 */
trait ShadowTrait
{
    /**
     * @param array<string, mixed> $currentData the normalized content of the item being written
     *
     * @return array<string, mixed>|null an error payload, or null when the shadow is usable
     */
    private function validateShadow(
        ?bool $shadowOn,
        ?string $shadowLocale,
        string $locale,
        array $currentData,
    ): ?array {
        if (true !== $shadowOn) {
            return null;
        }

        if (null === $shadowLocale || '' === $shadowLocale) {
            return [
                'error' => 'Enabling a shadow needs a "shadowLocale".',
                'hint' => 'Pass the locale whose content should be mirrored, e.g. shadowLocale: "en". The eligible locales are in "shadowLocales" of the get tool.',
            ];
        }

        if ($shadowLocale === $locale) {
            return [
                'error' => 'A locale cannot shadow itself.',
                'hint' => 'Pass a different locale in "shadowLocale", or set shadowOn to false to remove the shadow.',
            ];
        }

        // The admin form hides the shadow section while a link is on, so accepting both here
        // would produce a state the interface cannot show or undo.
        if (true === ($currentData['linkOn'] ?? false)) {
            return [
                'error' => 'A shadow cannot be enabled on an item that has a link set.',
                'hint' => 'Remove the link first by passing linkData: {}. The two settings are mutually exclusive in the admin interface.',
            ];
        }

        /** @var array<string, string> $shadowLocales */
        $shadowLocales = \is_array($currentData['shadowLocales'] ?? null) ? $currentData['shadowLocales'] : [];

        // A locale other locales already mirror cannot become a shadow itself, otherwise their
        // content would resolve through a shadow of a shadow.
        if (\in_array($locale, \array_values($shadowLocales), true)) {
            return [
                'error' => \sprintf('The locale "%s" is already mirrored by another locale and cannot become a shadow itself.', $locale),
                'hint' => 'Remove the shadows pointing at this locale first, or shadow a different locale.',
            ];
        }

        return null;
    }

    /**
     * The data mapper acts as soon as either key is present, so both are removed when neither
     * parameter is given, and "shadowOn: false" clears the shadow whatever locale is passed.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function applyShadow(array $data, ?bool $shadowOn, ?string $shadowLocale): array
    {
        if (null === $shadowOn && null === $shadowLocale) {
            // content must not smuggle a shadow past the explicit parameters
            unset($data['shadowOn'], $data['shadowLocale']);

            return $data;
        }

        // only shadowLocale given means the caller wants the shadow on
        $data['shadowOn'] = $shadowOn ?? true;
        $data['shadowLocale'] = true === $data['shadowOn'] ? $shadowLocale : null;

        return $data;
    }
}
