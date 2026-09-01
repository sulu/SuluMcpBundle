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

/**
 * Interprets the URL an assistant hands to sulu_media_upload.
 *
 * The URL an assistant has is almost always the one rendered in an `<img src>`, which on
 * a Sulu site is a resized format URL. Downloading it would import a thumbnail and lose
 * the source resolution for good, so a format URL is rewritten to the download route that
 * serves the original.
 *
 * The route patterns come from this instance's own `sulu_media` configuration. Applying
 * them to a remote host is a heuristic: the remote may route media differently, or may not
 * be Sulu at all and only happen to match. Every rewrite therefore keeps the URL as given
 * as its fallback, and MediaSource::hasFallback() tells the caller a retry is worthwhile.
 *
 * @internal
 */
class MediaSourceUrlResolver
{
    /**
     * `{format}/{segment}/{id}-{fileName}` -- the expansion LocalFormatCache::getPathUrl()
     * substitutes for `{slug}` in the media proxy path.
     */
    private const FORMAT_SLUG_PATTERN = '(?<format>[^/]+)/(?<segment>[^/]+)/(?<id>\d+)-(?<fileName>[^/]+)';

    /**
     * `{slug}` in the download path is the plain file name, `{id}` the media id.
     */
    private const DOWNLOAD_SLUG_PATTERN = '(?<fileName>[^/]+)';

    private readonly ?string $localOrigin;

    public function __construct(
        string $serverUrl,
        private readonly string $mediaProxyPath = '/uploads/media/{slug}',
        private readonly string $mediaDownloadPath = '/media/{id}/download/{slug}',
    ) {
        $this->localOrigin = self::originOf($serverUrl);
    }

    public function resolve(string $url): MediaSource
    {
        if ('' === $url) {
            throw new \InvalidArgumentException('The media source URL must not be empty.');
        }

        $path = self::pathOf($url);
        $origin = self::originOf($url);
        $isLocal = null !== $this->localOrigin && (null === $origin || $origin === $this->localOrigin);

        $format = null !== $path ? $this->matchFormatPath($path) : null;

        if (null !== $path && $isLocal) {
            $localId = $format['id'] ?? $this->matchDownloadPath($path);

            if (null !== $localId) {
                return MediaSource::localMedia($url, $localId);
            }
        }

        if (null === $format || null === $origin) {
            return MediaSource::direct($url);
        }

        $downloadPath = \str_replace(
            ['{id}', '{slug}'],
            [(string) $format['id'], $format['fileName']],
            $this->mediaDownloadPath,
        );

        // The query is dropped rather than carried over: a format URL's `?v=2-6` is not the
        // numeric version the download route understands, so it would be ignored anyway,
        // and without it the route serves the latest version -- which is what "the original"
        // means here.
        return MediaSource::formatUrl($origin . $downloadPath, $url);
    }

    /**
     * @return array{id: int, fileName: string}|null
     */
    private function matchFormatPath(string $path): ?array
    {
        $pattern = self::pathPattern($this->mediaProxyPath, self::FORMAT_SLUG_PATTERN);

        if (null === $pattern || 1 !== \preg_match($pattern, $path, $matches)) {
            return null;
        }

        return ['id' => (int) $matches['id'], 'fileName' => $matches['fileName']];
    }

    private function matchDownloadPath(string $path): ?int
    {
        $pattern = self::pathPattern($this->mediaDownloadPath, self::DOWNLOAD_SLUG_PATTERN);

        if (null === $pattern || 1 !== \preg_match($pattern, $path, $matches)) {
            return null;
        }

        return (int) $matches['id'];
    }

    /**
     * Turns a configured route pattern into an anchored regex, with `{id}` matching digits
     * and `{slug}` matching whatever the caller says a slug looks like there.
     */
    private static function pathPattern(string $routePath, string $slugPattern): ?string
    {
        if (!\str_contains($routePath, '{slug}')) {
            return null;
        }

        $quoted = \preg_quote($routePath, '#');

        return '#^' . \str_replace(
            [\preg_quote('{id}', '#'), \preg_quote('{slug}', '#')],
            ['(?<id>\d+)', $slugPattern],
            $quoted,
        ) . '$#';
    }

    /**
     * `scheme://host[:port]`, or null when the URL carries no host (a relative URL, which is
     * what sulu_media_get returns for this instance's own media).
     *
     * @return non-empty-string|null
     */
    private static function originOf(string $url): ?string
    {
        $parts = \parse_url($url);

        if (false === $parts || !isset($parts['scheme'], $parts['host']) || '' === $parts['host']) {
            return null;
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return \strtolower($parts['scheme'] . '://' . $parts['host']) . $port;
    }

    private static function pathOf(string $url): ?string
    {
        $path = \parse_url($url, \PHP_URL_PATH);

        return \is_string($path) && '' !== $path ? $path : null;
    }
}
