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

/**
 * Resolves an `https://` client_id as an OAuth Client ID Metadata Document, and hands every
 * other identifier to the decorated manager.
 *
 * The tokens and authorization codes of league/oauth2-server-bundle reference
 * `oauth2_client.identifier`, a 32 character column, so a URL cannot be stored as it is. The
 * client is persisted as a public client under a stable 32 character identifier derived from
 * the URL; League only needs that identifier to be the same on every call. Name and redirect
 * URIs are refreshed from the document once the cache entry has expired.
 *
 * @internal
 */
class CimdClientManager implements ClientManagerInterface
{
    public const FAILURE_TTL = 60;

    private const CACHE_PREFIX = 'sulu_mcp_cimd_';

    /**
     * @param list<string> $scopes
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
     * The identifier a metadata URL is stored under.
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

        // The deadline is kept in the value rather than in the cache expiry: it is the
        // document's own cache lifetime, and it stays testable without a real clock.
        if (\is_array($entry) && $entry['expires'] > $this->time()) {
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
        } catch (ClientMetadataException $e) {
            $this->logger->warning('Rejected OAuth client metadata document.', ['client_id' => $identifier, 'reason' => $e->getMessage()]);

            // Remembered briefly, so unauthenticated requests cannot make the server fetch
            // the same URL over and over.
            $this->remember($item, false, self::FAILURE_TTL);

            return null;
        }

        $client = $this->store($storedIdentifier, $metadata);
        if (!$client instanceof ClientInterface) {
            return null;
        }

        $this->remember($item, true, $fetched->ttl);

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

    private function remember(CacheItemInterface $item, bool $valid, int $ttl): void
    {
        $this->cache->save($item->set(['valid' => $valid, 'expires' => $this->time() + $ttl])->expiresAfter(ClientMetadataFetcher::MAX_TTL));
    }

    private function time(): int
    {
        return null === $this->now ? \time() : ($this->now)();
    }

    private function store(string $storedIdentifier, ClientMetadata $metadata): ?ClientInterface
    {
        $client = $this->inner->find($storedIdentifier);

        if (null !== $client && $client->isConfidential()) {
            // Not ours: a metadata document must never take over a client that has a secret.
            return null;
        }

        if (null === $client) {
            // Never given a secret: a client that identifies itself by URL is public, and the
            // authorization server demands PKCE from every public client.
            $client = new Client($metadata->clientName, $storedIdentifier, null);
        }

        if ($client instanceof AbstractClient) {
            $client->setName($metadata->clientName);
        }

        $client->setRedirectUris(...\array_map(static fn (string $uri): RedirectUri => new RedirectUri($uri), $metadata->redirectUris));
        $client->setGrants(new Grant('authorization_code'), new Grant('refresh_token'));
        $client->setScopes(...\array_map(static fn (string $scope): Scope => new Scope($scope), $this->scopes));
        $client->setAllowPlainTextPkce(false);

        // `active` is left alone: an administrator who deactivated the client keeps it off
        // however often the document is fetched again.
        $this->inner->save($client);

        return $client;
    }
}
