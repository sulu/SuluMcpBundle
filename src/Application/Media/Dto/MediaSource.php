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

namespace Sulu\Mcp\Application\Media\Dto;

/**
 * Where an upload should read its bytes from, once the given URL has been interpreted.
 *
 * @internal
 */
final readonly class MediaSource
{
    /**
     * The URL names media this instance already owns; no download is needed.
     */
    public const KIND_LOCAL_MEDIA = 'local_media';

    /**
     * The URL is a Sulu format (thumbnail) URL and was rewritten to the original.
     */
    public const KIND_FORMAT_URL = 'format_url';

    /**
     * The URL is used as given.
     */
    public const KIND_DIRECT = 'direct';

    /**
     * @param self::KIND_* $kind
     * @param non-empty-string $url the URL to download, or the URL as given for a local media hit
     * @param non-empty-string $fallbackUrl the URL to retry with when $url does not resolve
     */
    private function __construct(
        public string $kind,
        public string $url,
        public string $fallbackUrl,
        public ?int $localMediaId = null,
    ) {
    }

    /**
     * @param non-empty-string $url
     */
    public static function localMedia(string $url, int $mediaId): self
    {
        return new self(self::KIND_LOCAL_MEDIA, $url, $url, $mediaId);
    }

    /**
     * @param non-empty-string $downloadUrl
     * @param non-empty-string $givenUrl
     */
    public static function formatUrl(string $downloadUrl, string $givenUrl): self
    {
        return new self(self::KIND_FORMAT_URL, $downloadUrl, $givenUrl);
    }

    /**
     * @param non-empty-string $url
     */
    public static function direct(string $url): self
    {
        return new self(self::KIND_DIRECT, $url, $url);
    }

    /**
     * Whether a failed download of `url` is worth retrying against `fallbackUrl`.
     */
    public function hasFallback(): bool
    {
        return $this->url !== $this->fallbackUrl;
    }
}
