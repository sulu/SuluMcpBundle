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

namespace Sulu\Mcp\Application\Media;

use Symfony\Component\Mime\MimeTypes;

/**
 * Decides what a set of downloaded bytes may be stored under.
 *
 * Both candidate names are untrustworthy in the same way: one comes from a URL the model
 * supplied, the other from a parameter the model filled in. They therefore get the same
 * treatment, which is the whole reason this is a collaborator rather than something the
 * downloader keeps to itself.
 *
 * @internal
 */
class MediaFileNamer
{
    private const FALLBACK = 'image';

    /**
     * Characters kept from the stem. A URL path can be arbitrarily long, and this one was
     * chosen by the model rather than typed into a form, so it is cut well short of the
     * filesystem's own limit rather than at it.
     */
    private const MAX_STEM_LENGTH = 100;

    /**
     * @param non-empty-string $mimeType
     *
     * @return non-empty-string
     */
    public function fromUrl(string $url, string $mimeType): string
    {
        $path = \parse_url($url, \PHP_URL_PATH);

        return $this->normalize(\is_string($path) ? \basename(\rawurldecode($path)) : '', $mimeType);
    }

    /**
     * @param non-empty-string $mimeType
     *
     * @return non-empty-string
     */
    public function normalize(string $name, string $mimeType): string
    {
        // Separators and NULs go first, then the leading dots they leave behind: "../../x.gif"
        // would otherwise survive as "....x.gif", which is a hidden file rather than a traversal
        // but still not a name anyone asked for. MediaManager cleans it further, but not before
        // it has been trusted here.
        $name = \ltrim(\trim(\str_replace(['/', '\\', "\0"], '', $name)), '.');

        if ('' === $name) {
            $name = self::FALLBACK;
        }

        $extensions = MimeTypes::getDefault()->getExtensions($mimeType);
        $canonical = $extensions[0] ?? null;
        $extension = \strtolower(\pathinfo($name, \PATHINFO_EXTENSION));
        $keepExtension = null === $canonical || \in_array($extension, $extensions, true);

        // Renamed to match what the bytes actually are, so a ".php" served as an image, or a
        // URL with no extension at all, still lands as an image file.
        $suffix = $keepExtension ? ('' !== $extension ? '.' . $extension : '') : '.' . $canonical;
        $stem = \pathinfo($name, \PATHINFO_FILENAME);

        if ('' === $stem) {
            $stem = self::FALLBACK;
        }

        return $this->shorten($stem) . $suffix;
    }

    /**
     * @return non-empty-string
     */
    private function shorten(string $stem): string
    {
        if (\mb_strlen($stem) <= self::MAX_STEM_LENGTH) {
            return '' === $stem ? self::FALLBACK : $stem;
        }

        $shortened = \rtrim(\mb_substr($stem, 0, self::MAX_STEM_LENGTH));

        return '' === $shortened ? self::FALLBACK : $shortened;
    }
}
