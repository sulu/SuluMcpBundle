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

use Sulu\Mcp\Application\Media\Dto\DownloadedFile;
use Sulu\Mcp\Domain\Exception\MediaDownloadException;
use Sulu\Mcp\Domain\Exception\MediaSourceUnreachableException;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Fetches a model-supplied URL into a temporary file.
 *
 * This is the one place in the bundle where the server issues an outbound request to an
 * address it was told rather than one it configured, so it is also where that request is
 * constrained: only http(s), only hosts the operator allows, a bounded number of redirects,
 * a bounded duration and a bounded response size.
 *
 * Redirects are followed one hop at a time so that every address in the chain is held to the
 * same rules, not just the one the model supplied. Blocking private and loopback addresses is
 * the injected client's job -- it is wired as a NoPrivateNetworkHttpClient, and issuing each
 * hop as its own request is what puts every hop through it. The literal-IP check here is a
 * second lock on the same door, so a misconfigured client cannot silently turn the tool into
 * a port scanner.
 *
 * @internal
 */
class MediaDownloader
{
    private const ALLOWED_SCHEMES = ['http', 'https'];
    private const MAX_REDIRECTS = 3;

    /**
     * Raster types only, named rather than matched on an `image/` prefix. SVG is an image by
     * mime type but a document in practice: it can carry a <script>, Sulu's
     * MediaStreamController lists only the html/xml types as dangerous to serve inline, and
     * `sulu_media.upload.blocked_file_types` is empty by default. A human upload has someone
     * choosing the file; here it comes off whatever page the model happened to be reading.
     *
     * @var list<string>
     */
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];

    /**
     * Seconds of inactivity before the transfer is abandoned.
     */
    private const IDLE_TIMEOUT = 10.0;

    /**
     * Seconds the whole call may take, every redirect hop included.
     */
    private const MAX_DURATION = 30.0;

    private readonly int $maxFileSize;

    /**
     * @param int<1, max> $maxFilesizeInMegabytes counted in MB, because it is sulu_media's own
     *                                            upload.max_filesize: there is no point fetching
     *                                            more than the project will store
     * @param list<string> $allowedHosts empty allows any host the client will talk to
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly MediaFileNamer $fileNamer,
        int $maxFilesizeInMegabytes,
        private readonly array $allowedHosts = [],
    ) {
        $this->maxFileSize = $maxFilesizeInMegabytes * 1024 * 1024;
    }

    /**
     * @throws MediaDownloadException
     */
    public function download(string $url): DownloadedFile
    {
        $this->assertFetchable($url);

        $path = @\tempnam(\sys_get_temp_dir(), 'sulu-mcp-media-');

        if (false === $path) {
            throw new MediaDownloadException('Could not create a temporary file for the download.');
        }

        try {
            $size = $this->streamTo($url, $path);

            $mimeType = MimeTypes::getDefault()->guessMimeType($path);

            if (null === $mimeType || !\in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
                throw new MediaDownloadException(\sprintf(
                    'The response from "%s" is not an image this tool accepts (detected "%s"). Accepted: %s.',
                    $url,
                    $mimeType ?? 'unknown',
                    \implode(', ', self::ALLOWED_MIME_TYPES),
                ));
            }

            return new DownloadedFile($path, $this->fileNamer->fromUrl($url, $mimeType), $mimeType, $size);
        } catch (\Throwable $e) {
            @\unlink($path);

            throw $e;
        }
    }

    /**
     * @return int bytes written
     *
     * @throws MediaDownloadException
     */
    private function streamTo(string $url, string $path): int
    {
        $handle = @\fopen($path, 'wb');

        if (false === $handle) {
            throw new MediaDownloadException('Could not open the temporary file for writing.');
        }

        try {
            $response = $this->requestFollowingRedirects($url);

            $size = 0;

            foreach ($this->httpClient->stream($response) as $chunk) {
                if ($chunk->isLast()) {
                    break;
                }

                $content = $chunk->getContent();
                $size += \strlen($content);

                // Checked against what has actually arrived rather than Content-Length,
                // which the remote is free to understate.
                if ($size > $this->maxFileSize) {
                    throw new MediaDownloadException(\sprintf(
                        'The file at "%s" is larger than the configured limit of %d bytes.',
                        $url,
                        $this->maxFileSize,
                    ));
                }

                if (\strlen($content) !== \fwrite($handle, $content)) {
                    // A short write means the temp volume is full. Counting the chunk as
                    // written anyway would hand MediaManager a truncated image.
                    throw new MediaDownloadException('Could not write the download to the temporary file.');
                }
            }
        } catch (HttpClientExceptionInterface $e) {
            throw new MediaSourceUnreachableException(\sprintf('Could not download "%s": %s', $url, $e->getMessage()), 0, $e);
        } finally {
            \fclose($handle);
        }

        if (0 === $size) {
            throw new MediaDownloadException(\sprintf('The response from "%s" was empty.', $url));
        }

        return $size;
    }

    /**
     * Redirects are followed here rather than by the client, because `max_redirects` would
     * check only the first URL: an allow-listed host could then redirect anywhere and the
     * bytes would be fetched from a host the operator never named. Every hop instead goes
     * through assertFetchable() and through a fresh request(), and the fresh request is also
     * what re-runs NoPrivateNetworkHttpClient's own check on the new address.
     *
     * @throws MediaDownloadException
     */
    private function requestFollowingRedirects(string $url): ResponseInterface
    {
        // max_duration is per request(), so handing each hop the full budget would let a chain
        // of redirects hold a worker for MAX_REDIRECTS + 1 times as long as the documented
        // limit. One deadline is shared out instead.
        $deadline = \microtime(true) + self::MAX_DURATION;

        for ($hop = 0;; ++$hop) {
            $remaining = $deadline - \microtime(true);

            if ($remaining <= 0) {
                throw new MediaSourceUnreachableException(\sprintf('Downloading "%s" took longer than %d seconds.', $url, self::MAX_DURATION));
            }

            $response = $this->httpClient->request('GET', $url, [
                'max_redirects' => 0,
                'timeout' => \min(self::IDLE_TIMEOUT, $remaining),
                'max_duration' => $remaining,
                'headers' => ['Accept' => 'image/*,*/*;q=0.8'],
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode < 300 || $statusCode >= 400) {
                if (200 !== $statusCode) {
                    // Named after the hop that actually answered, which is not the URL the
                    // caller passed once a redirect has been followed.
                    throw new MediaSourceUnreachableException(\sprintf('Downloading "%s" returned HTTP %d.', $url, $statusCode));
                }

                return $response;
            }

            if ($hop >= self::MAX_REDIRECTS) {
                throw new MediaSourceUnreachableException(\sprintf('Downloading "%s" exceeded %d redirects.', $url, self::MAX_REDIRECTS));
            }

            $location = $response->getHeaders(false)['location'][0] ?? null;

            if (!\is_string($location) || '' === $location) {
                throw new MediaSourceUnreachableException(\sprintf('Downloading "%s" returned HTTP %d without a Location.', $url, $statusCode));
            }

            $url = self::resolveLocation($url, $location);
            $this->assertFetchable($url);
        }
    }

    /**
     * A Location may be absolute, protocol-relative, root-relative or relative to the current
     * document (RFC 7231 permits all four), and the result has to be absolute before it can be
     * checked against the host rules.
     */
    private static function resolveLocation(string $base, string $location): string
    {
        if (null !== \parse_url($location, \PHP_URL_SCHEME)) {
            return $location;
        }

        $baseParts = \parse_url($base);
        $scheme = \is_array($baseParts) ? ($baseParts['scheme'] ?? '') : '';
        $host = \is_array($baseParts) ? ($baseParts['host'] ?? '') : '';
        $port = \is_array($baseParts) && isset($baseParts['port']) ? ':' . $baseParts['port'] : '';
        $basePath = \is_array($baseParts) ? ($baseParts['path'] ?? '/') : '/';

        if (\str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }

        $origin = $scheme . '://' . $host . $port;

        if (\str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $lastSlash = \strrpos($basePath, '/');

        return $origin . ($lastSlash > 0 ? \substr($basePath, 0, $lastSlash) : '') . '/' . $location;
    }

    /**
     * @throws MediaDownloadException
     */
    private function assertFetchable(string $url): void
    {
        $scheme = \parse_url($url, \PHP_URL_SCHEME);
        $host = \parse_url($url, \PHP_URL_HOST);

        if (!\is_string($scheme) || !\in_array(\strtolower($scheme), self::ALLOWED_SCHEMES, true)) {
            throw new MediaDownloadException(\sprintf('Only http and https URLs can be downloaded, got "%s".', $url));
        }

        if (!\is_string($host) || '' === $host) {
            throw new MediaDownloadException(\sprintf('The URL "%s" names no host.', $url));
        }

        $literalIp = \trim($host, '[]');

        if (false !== \filter_var($literalIp, \FILTER_VALIDATE_IP)
            && false === \filter_var($literalIp, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE)
        ) {
            throw new MediaDownloadException(\sprintf('The URL "%s" points at a private or reserved address.', $url));
        }

        if ([] !== $this->allowedHosts && !\in_array(\strtolower($host), $this->allowedHosts, true)) {
            throw new MediaDownloadException(\sprintf(
                'The host "%s" is not in sulu_mcp.media_upload.allowed_hosts.',
                $host,
            ));
        }
    }
}
