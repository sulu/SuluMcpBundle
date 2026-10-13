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

namespace Sulu\Mcp\Infrastructure\League\Manager;

use League\Bundle\OAuth2ServerBundle\Manager\ClientFilter;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\AbstractClient;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\Model\ClientInterface;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Sulu\Mcp\Application\OAuth\ClientMetadata;
use Sulu\Mcp\Application\OAuth\ClientMetadataFetcher;
use Sulu\Mcp\Application\OAuth\ClientMetadataValidator;
use Sulu\Mcp\Domain\Exception\ClientMetadataException;
use Sulu\Mcp\Domain\Exception\ClientMetadataUnavailableException;

/**
 * League's tokens and codes reference `oauth2_client.identifier`, a 32 character column, so a URL cannot be
 * stored as it is. The client is persisted under a stable 32 character identifier derived from the URL.
 * Every other client_id goes to the decorated manager.
 *
 * @internal
 */
class CimdClientManager implements ClientManagerInterface
{
    public const FAILURE_TTL = 60;

    private const CACHE_PREFIX = 'sulu_mcp_cimd_';

    /**
     * @param list<non-empty-string> $scopes
     * @param (\Closure(): int)|null $now
     */
    public function __construct(
        private readonly ClientManagerInterface $inner,
        private readonly ClientMetadataFetcher $fetcher,
        private readonly ClientMetadataValidator $validator,
        private readonly CacheItemPoolInterface $cache,
        private readonly array $scopes,
        private readonly bool $enabled = true,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?\Closure $now = null,
    ) {
    }

    /**
     * @return non-empty-string
     */
    public static function storedIdentifier(string $clientIdUrl): string
    {
        return \substr(\hash('sha256', $clientIdUrl), 0, 32);
    }

    public static function isMetadataUrl(string $identifier): bool
    {
        return \str_starts_with($identifier, 'https://');
    }

    public function find(string $identifier): ?ClientInterface
    {
        if (!$this->enabled || !self::isMetadataUrl($identifier)) {
            return $this->inner->find($identifier);
        }

        $storedIdentifier = self::storedIdentifier($identifier);
        $item = $this->cache->getItem(self::CACHE_PREFIX . $storedIdentifier);

        $entry = $item->isHit() ? $item->get() : null;
        $now = $this->time();

        // the deadline lives in the value, not the cache expiry: it is the document's own lifetime and stays testable
        if (\is_array($entry) && $entry['expires'] > $now) {
            if (!$entry['valid']) {
                return null;
            }

            $client = $this->inner->find($storedIdentifier);
            if (null !== $client) {
                return $client;
            }
        }

        try {
            $fetched = $this->fetcher->fetch($identifier);
            $metadata = $this->validator->validate($identifier, $fetched->body);
        } catch (ClientMetadataUnavailableException $e) {
            $this->logger->warning('OAuth client metadata document is unavailable.', ['client_id' => $identifier, 'reason' => $e->getMessage()]);

            return $this->staleClient($item, $entry, $storedIdentifier);
        } catch (ClientMetadataException $e) {
            $this->logger->warning('Rejected OAuth client metadata document.', ['client_id' => $identifier, 'reason' => $e->getMessage()]);

            // remembered briefly, so requests cannot make the server fetch the same URL over and over
            $this->remember($item, false, self::FAILURE_TTL, $now + self::FAILURE_TTL);

            return null;
        }

        $client = $this->store($storedIdentifier, $metadata);
        if (!$client instanceof ClientInterface) {
            return null;
        }

        $this->remember($item, true, $fetched->ttl, $now + ClientMetadataFetcher::MAX_TTL);

        return $client;
    }

    public function save(ClientInterface $client): void
    {
        $this->inner->save($client);
    }

    public function remove(ClientInterface $client): void
    {
        $this->inner->remove($client);
    }

    public function list(?ClientFilter $clientFilter): array
    {
        return $this->inner->list($clientFilter);
    }

    /**
     * Keeps a client working on its last fetched data while its host is down, as long as the entry is kept.
     * Without it every token refresh of the client would fail during an outage.
     */
    private function staleClient(CacheItemInterface $item, mixed $entry, string $storedIdentifier): ?ClientInterface
    {
        $now = $this->time();
        $keepUntil = \is_array($entry) && true === $entry['valid'] && \is_int($entry['keepUntil'] ?? null) ? $entry['keepUntil'] : 0;

        $client = $keepUntil > $now ? $this->inner->find($storedIdentifier) : null;
        if (!$client instanceof ClientInterface) {
            $this->remember($item, false, self::FAILURE_TTL, $now + self::FAILURE_TTL);

            return null;
        }

        $this->remember($item, true, self::FAILURE_TTL, $keepUntil);

        return $client;
    }

    /**
     * @param int $keepUntil timestamp after which the entry is dropped from the cache
     */
    private function remember(CacheItemInterface $item, bool $valid, int $ttl, int $keepUntil): void
    {
        $now = $this->time();

        $this->cache->save($item->set(['valid' => $valid, 'expires' => $now + $ttl, 'keepUntil' => $keepUntil])->expiresAfter(\max(1, $keepUntil - $now)));
    }

    private function time(): int
    {
        return null === $this->now ? \time() : ($this->now)();
    }

    /**
     * @param non-empty-string $storedIdentifier
     */
    private function store(string $storedIdentifier, ClientMetadata $metadata): ?ClientInterface
    {
        $client = $this->inner->find($storedIdentifier);

        if (null !== $client && $client->isConfidential()) {
            // a metadata document must never take over a client that has a secret
            return null;
        }

        if (null === $client) {
            // never given a secret: a client identifying itself by URL is public
            $client = new Client($metadata->clientName, $storedIdentifier, null);
        }

        if ($client instanceof AbstractClient) {
            $client->setName($metadata->clientName);
        }

        $client->setRedirectUris(...\array_map(static fn (string $uri): RedirectUri => new RedirectUri($uri), $metadata->redirectUris));
        $client->setGrants(new Grant('authorization_code'), new Grant('refresh_token'));
        $client->setScopes(...\array_map(static fn (string $scope): Scope => new Scope($scope), $this->scopes));
        $client->setAllowPlainTextPkce(false);

        // `active` is left alone: a client an administrator deactivated stays off
        $this->inner->save($client);

        return $client;
    }
}
