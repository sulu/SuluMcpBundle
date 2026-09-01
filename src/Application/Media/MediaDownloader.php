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

use Sulu\Mcp\Domain\Exception\MediaDownloadException;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches a model-supplied URL into a temporary file.
 *
 * This is the one place in the bundle where the server issues an outbound request to an
 * address it was told rather than one it configured, so it is also where that request is
 * constrained: only http(s), only hosts the operator allows, a bounded number of redirects,
 * a bounded duration and a bounded response size.
 *
 * Blocking private and loopback addresses is the injected client's job -- it is wired as a
 * NoPrivateNetworkHttpClient, which re-checks the resolved IP after every redirect rather
 * than trusting the hostname once. The literal-IP check here is a second lock on the same
 * door, so a misconfigured client cannot silently turn the tool into a port scanner.
 *
 * @internal
 */
class MediaDownloader
{
    private const ALLOWED_SCHEMES = ['http', 'https'];
    private const MAX_REDIRECTS = 3;

    /**
     * Seconds of inactivity before the transfer is abandoned.
     */
    private const IDLE_TIMEOUT = 10.0;

    /**
     * Seconds the whole transfer may take, redirects included.
     */
    private const MAX_DURATION = 30.0;

    private const FALLBACK_FILE_NAME = 'image';

    /**
     * @param int<1, max> $maxFileSize in bytes
     * @param list<string> $allowedHosts empty allows any host the client will talk to
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly int $maxFileSize,
        private readonly array $allowedHosts = [],
    ) {
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

            if (null === $mimeType || !\str_starts_with($mimeType, 'image/')) {
                throw new MediaDownloadException(\sprintf(
                    'The response from "%s" is not an image (detected "%s").',
                    $url,
                    $mimeType ?? 'unknown',
                ));
            }

            return new DownloadedFile($path, $this->fileNameFor($url, $mimeType), $mimeType, $size);
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
            $response = $this->httpClient->request('GET', $url, [
                'max_redirects' => self::MAX_REDIRECTS,
                'timeout' => self::IDLE_TIMEOUT,
                'max_duration' => self::MAX_DURATION,
                'headers' => ['Accept' => 'image/*,*/*;q=0.8'],
            ]);

            $statusCode = $response->getStatusCode();

            if (200 !== $statusCode) {
                throw new MediaDownloadException(\sprintf('Downloading "%s" returned HTTP %d.', $url, $statusCode));
            }

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

                \fwrite($handle, $content);
            }
        } catch (HttpClientExceptionInterface $e) {
            throw new MediaDownloadException(\sprintf('Could not download "%s": %s', $url, $e->getMessage()), 0, $e);
        } finally {
            \fclose($handle);
        }

        if (0 === $size) {
            throw new MediaDownloadException(\sprintf('The response from "%s" was empty.', $url));
        }

        return $size;
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

    /**
     * @param non-empty-string $mimeType
     *
     * @return non-empty-string
     */
    private function fileNameFor(string $url, string $mimeType): string
    {
        $path = \parse_url($url, \PHP_URL_PATH);
        $name = \is_string($path) ? \basename(\rawurldecode($path)) : '';

        // Anything the remote could use to escape the collection directory is dropped;
        // MediaManager cleans the name further, but not before it has been trusted here.
        $name = \trim(\str_replace(['/', '\\', "\0"], '', $name));

        if ('' === $name || '.' === $name) {
            $name = self::FALLBACK_FILE_NAME;
        }

        $extensions = MimeTypes::getDefault()->getExtensions($mimeType);
        $canonical = $extensions[0] ?? null;
        $extension = \strtolower(\pathinfo($name, \PATHINFO_EXTENSION));

        if (null === $canonical || \in_array($extension, $extensions, true)) {
            return $name;
        }

        // The name is renamed to match what the bytes actually are, so a ".php" served as
        // an image, or a URL with no extension at all, still lands as an image file.
        return \pathinfo($name, \PATHINFO_FILENAME) . '.' . $canonical;
    }
}
