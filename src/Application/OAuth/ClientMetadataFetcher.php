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

namespace Sulu\Mcp\Application\OAuth;

use Sulu\Mcp\Domain\Exception\ClientMetadataException;
use Sulu\Mcp\Domain\Exception\ClientMetadataUnavailableException;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches an OAuth Client ID Metadata Document from a URL the client supplied.
 *
 * The URL is attacker-controlled, so the request is constrained: https only, no credentials
 * or fragment in the URL, no IP literals, no redirects, a short timeout and a small size cap.
 * The hostname is resolved here and every address must be public; the request is then pinned
 * to the address that was checked, so DNS cannot answer differently the second time. The
 * injected client is expected to be a NoPrivateNetworkHttpClient as a second lock.
 *
 * @internal
 */
class ClientMetadataFetcher
{
    public const MAX_BODY_SIZE = 16384;
    public const MIN_TTL = 300;
    public const MAX_TTL = 86400;
    public const DEFAULT_TTL = 3600;

    private const IDLE_TIMEOUT = 3.0;
    private const MAX_DURATION = 5.0;

    /**
     * Ranges IpUtils::PRIVATE_SUBNETS does not name, or names only from a later Symfony release
     * on: the lowest supported version lacks the carrier-grade NAT and the tunnelling ranges.
     */
    private const EXTRA_BLOCKED_SUBNETS = [
        '100.64.0.0/10',  // carrier-grade NAT, cloud metadata services live here
        '192.0.0.0/24',   // IETF protocol assignments
        '192.0.2.0/24',   // documentation
        '192.88.99.0/24', // 6to4 relay anycast
        '198.18.0.0/15',  // benchmarking
        '198.51.100.0/24', // documentation
        '203.0.113.0/24', // documentation
        '224.0.0.0/4',    // multicast
        '255.255.255.255/32',
        '::/96',          // IPv4-compatible
        '64:ff9b::/96',   // NAT64, embeds an IPv4 address
        '64:ff9b:1::/48', // NAT64 local use
        '2001::/32',      // Teredo, embeds an IPv4 address
        '2001:db8::/32',  // documentation
        '2002::/16',      // 6to4, embeds an IPv4 address
        'ff00::/8',       // multicast
    ];

    /** @var \Closure(string): list<string> */
    private readonly \Closure $resolver;

    /**
     * @param (\Closure(string): list<string>)|null $resolver returns the IP addresses of a host
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        ?\Closure $resolver = null,
    ) {
        $this->resolver = $resolver ?? self::resolveHost(...);
    }

    /**
     * @throws ClientMetadataException
     */
    public function fetch(string $url): FetchedClientMetadata
    {
        $host = $this->assertFetchable($url);
        $ip = $this->resolvePublicAddress($host);

        try {
            $response = $this->httpClient->request('GET', $url, [
                'max_redirects' => 0,
                'timeout' => self::IDLE_TIMEOUT,
                'max_duration' => self::MAX_DURATION,
                'resolve' => [$host => $ip],
                'headers' => ['Accept' => 'application/json'],
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 300 && $statusCode < 400) {
                throw new ClientMetadataException(\sprintf('The metadata document at "%s" redirects, which is not followed.', $url));
            }

            if ($statusCode >= 500) {
                throw new ClientMetadataUnavailableException(\sprintf('The metadata document at "%s" returned HTTP %d.', $url, $statusCode));
            }

            if (200 !== $statusCode) {
                throw new ClientMetadataException(\sprintf('The metadata document at "%s" returned HTTP %d.', $url, $statusCode));
            }

            $headers = $response->getHeaders(false);

            if (!self::isJson($headers['content-type'][0] ?? '')) {
                throw new ClientMetadataException(\sprintf('The metadata document at "%s" is not served as application/json.', $url));
            }

            $declared = $headers['content-length'][0] ?? null;
            if (null !== $declared && (int) $declared > self::MAX_BODY_SIZE) {
                throw new ClientMetadataException(self::tooLarge($url));
            }

            $body = '';
            foreach ($this->httpClient->stream($response) as $chunk) {
                if ($chunk->isTimeout()) {
                    throw new ClientMetadataUnavailableException(\sprintf('The metadata document at "%s" timed out.', $url));
                }

                $body .= $chunk->getContent();

                // Counted on what arrived: Content-Length is the remote's to understate.
                if (\strlen($body) > self::MAX_BODY_SIZE) {
                    $response->cancel();

                    throw new ClientMetadataException(self::tooLarge($url));
                }
            }
        } catch (HttpClientExceptionInterface $e) {
            throw new ClientMetadataUnavailableException(\sprintf('Could not fetch the metadata document at "%s": %s', $url, $e->getMessage()), 0, $e);
        }

        return new FetchedClientMetadata($body, self::lifetime($headers));
    }

    /**
     * @return string the lower-cased host
     *
     * @throws ClientMetadataException
     */
    private function assertFetchable(string $url): string
    {
        $parts = \parse_url($url);

        if (false === $parts || 'https' !== \strtolower($parts['scheme'] ?? '')) {
            throw new ClientMetadataException('The client_id URL must use https.');
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new ClientMetadataException('The client_id URL must not contain credentials or a fragment.');
        }

        if (isset($parts['port']) && 443 !== $parts['port']) {
            throw new ClientMetadataException('The client_id URL must use the default https port.');
        }

        $path = $parts['path'] ?? '';
        if ('' === $path || '/' === $path) {
            throw new ClientMetadataException('The client_id URL must have a path.');
        }

        $host = \strtolower(\trim($parts['host'] ?? '', '[]'));
        if ('' === $host || false !== \filter_var($host, \FILTER_VALIDATE_IP) || \str_contains($host, ':')) {
            throw new ClientMetadataException('The client_id URL must use a host name, not an IP address.');
        }

        return $host;
    }

    /**
     * @throws ClientMetadataException
     */
    private function resolvePublicAddress(string $host): string
    {
        $addresses = ($this->resolver)($host);

        if ([] === $addresses) {
            throw new ClientMetadataUnavailableException(\sprintf('The host "%s" does not resolve.', $host));
        }

        // All of them, not just the one connected to: a host that mixes public and internal
        // records is treated as internal.
        foreach ($addresses as $address) {
            if (self::isInternal($address)) {
                throw new ClientMetadataException(\sprintf('The host "%s" resolves to a non-public address.', $host));
            }
        }

        return $addresses[0];
    }

    private static function isInternal(string $address): bool
    {
        if (false === \filter_var($address, \FILTER_VALIDATE_IP)) {
            return true;
        }

        return IpUtils::checkIp($address, [...IpUtils::PRIVATE_SUBNETS, ...self::EXTRA_BLOCKED_SUBNETS]);
    }

    private static function isJson(string $contentType): bool
    {
        $type = \strtolower(\trim(\explode(';', $contentType)[0]));

        return 'application/json' === $type || \str_ends_with($type, '+json');
    }

    private static function tooLarge(string $url): string
    {
        return \sprintf('The metadata document at "%s" is larger than %d bytes.', $url, self::MAX_BODY_SIZE);
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private static function lifetime(array $headers): int
    {
        $ttl = self::DEFAULT_TTL;
        $cacheControl = \strtolower(\implode(',', $headers['cache-control'] ?? []));

        if (1 === \preg_match('/(?:^|[,\s])max-age\s*=\s*"?(\d+)/', $cacheControl, $matches)) {
            $ttl = (int) $matches[1];
        } elseif (\str_contains($cacheControl, 'no-store') || \str_contains($cacheControl, 'no-cache')) {
            $ttl = 0;
        } elseif (isset($headers['expires'][0]) && false !== ($expires = \strtotime($headers['expires'][0]))) {
            $ttl = $expires - \time();
        }

        return \max(self::MIN_TTL, \min(self::MAX_TTL, $ttl));
    }

    /**
     * @return list<string>
     */
    private static function resolveHost(string $host): array
    {
        $addresses = [];

        foreach (@\dns_get_record($host, \DNS_A | \DNS_AAAA) ?: [] as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (\is_string($address)) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }
}
