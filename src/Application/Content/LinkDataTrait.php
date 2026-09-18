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
 * Shared handling of the "Link" page setting, which turns a page into a redirect
 * to internal content or an external url.
 *
 * @internal
 */
trait LinkDataTrait
{
    /**
     * @param array<string, mixed> $linkData
     * @param array<string, mixed> $currentData the normalized content of the page being written
     *
     * @return array<string, mixed>|null an error payload, or null when the link data is usable
     */
    private function validateLinkData(array $linkData, array $currentData): ?array
    {
        if ([] === $linkData) {
            return null;
        }

        $provider = $linkData['provider'] ?? null;

        if (!\is_string($provider) || '' === $provider) {
            return [
                'error' => 'The "linkData" object needs a non-empty "provider" string.',
                'hint' => 'Use the provider of the link target, e.g. "page" or "article" for internal content and "external" for a url. Call sulu_get_context for the providers this project declares.',
            ];
        }

        // The admin form hides the link section while a shadow is on, so accepting both here
        // would produce a state the interface cannot show or undo.
        if (true === ($currentData['shadowOn'] ?? false)) {
            return [
                'error' => 'A link cannot be set on a page that has a shadow locale enabled.',
                'hint' => 'Disable the shadow first. The two settings are mutually exclusive in the admin interface.',
            ];
        }

        return null;
    }

    /**
     * The data mapper keys off "linkOn" and only reads "linkData" when it is true, so an
     * empty array clears the link and omitting the parameter leaves it untouched.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $linkData
     *
     * @return array<string, mixed>
     */
    private function applyLinkData(array $data, ?array $linkData): array
    {
        if (null === $linkData) {
            // content must not smuggle a link assignment past the explicit parameter
            unset($data['linkOn'], $data['linkData']);

            return $data;
        }

        $data['linkOn'] = [] !== $linkData;
        $data['linkData'] = [] !== $linkData ? $linkData : null;

        return $data;
    }
}
