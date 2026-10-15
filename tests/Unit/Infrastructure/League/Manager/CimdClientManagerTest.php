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

namespace Sulu\Mcp\Tests\Unit\Infrastructure\League\Manager;

use League\Bundle\OAuth2ServerBundle\Manager\InMemory\ClientManager;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Sulu\Mcp\Application\OAuth\ClientMetadataFetcher;
use Sulu\Mcp\Application\OAuth\ClientMetadataValidator;
use Sulu\Mcp\Infrastructure\League\Manager\CimdClientManager;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

#[CoversClass(CimdClientManager::class)]
final class CimdClientManagerTest extends TestCase
{
    private const URL = 'https://client.example/oauth/metadata.json';

    private MockClock $clock;
    private ClientManager $inner;

    /** @var list<string> */
    private array $requested = [];

    /** @var list<MockResponse> */
    private array $responses = [];

    /** @var list<string> */
    private array $addresses = ['93.184.216.34'];

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-01-01 00:00:00');
        $this->inner = new ClientManager(new EventDispatcher());
    }

    public function testStoresAPublicClientUnderAShortIdentifier(): void
    {
        $this->respond($this->document());

        $client = $this->manager()->find(self::URL);

        self::assertNotNull($client);
        self::assertSame(CimdClientManager::storedIdentifier(self::URL), $client->getIdentifier());
        self::assertSame(32, \strlen($client->getIdentifier()));
        self::assertFalse($client->isConfidential());
        self::assertNull($client->getSecret());
        self::assertFalse($client->isPlainTextPkceAllowed());
        self::assertSame(['https://client.example/callback'], \array_map(\strval(...), $client->getRedirectUris()));
        self::assertSame(['authorization_code', 'refresh_token'], \array_map(\strval(...), $client->getGrants()));
        self::assertSame(['mcp:tools', 'mcp:resources'], \array_map(\strval(...), $client->getScopes()));
        self::assertSame('Example Client', $client instanceof Client ? $client->getName() : null);
        self::assertSame($client, $this->inner->find($client->getIdentifier()), 'The client must be persisted for the token tables to reference it.');
    }

    public function testServesFromCacheUntilTheDocumentExpires(): void
    {
        $this->respond($this->document(), 'Cache-Control: max-age=600');
        $manager = $this->manager();

        $manager->find(self::URL);
        $this->clock->sleep(599);
        $manager->find(self::URL);

        self::assertCount(1, $this->requested);
    }

    public function testRefetchesAnExpiredDocumentAndUpdatesTheClient(): void
    {
        $this->respond($this->document(), 'Cache-Control: max-age=600');
        $this->respond($this->document(['client_name' => 'Renamed', 'redirect_uris' => ['https://client.example/new']]));
        $manager = $this->manager();

        $first = $manager->find(self::URL);
        $this->clock->sleep(601);
        $second = $manager->find(self::URL);

        self::assertCount(2, $this->requested);
        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame($first->getIdentifier(), $second->getIdentifier());
        self::assertSame(['https://client.example/new'], \array_map(\strval(...), $second->getRedirectUris()));
        self::assertCount(1, $this->inner->list(null));
    }

    public function testDoesNotReactivateAClientAnAdministratorDisabled(): void
    {
        $this->respond($this->document(), 'Cache-Control: max-age=300');
        $this->respond($this->document());
        $manager = $this->manager();

        $client = $manager->find(self::URL);
        self::assertNotNull($client);
        $client->setActive(false);
        $this->clock->sleep(301);

        $client = $manager->find(self::URL);

        self::assertNotNull($client);
        self::assertFalse($client->isActive());
    }

    public function testRejectsAClientIdThatDoesNotMatchTheDocument(): void
    {
        $this->respond($this->document(['client_id' => 'https://other.example/metadata.json']));

        self::assertNull($this->manager()->find(self::URL));
        self::assertSame([], $this->inner->list(null));
    }

    public function testRejectsInvalidRedirectUris(): void
    {
        $this->respond($this->document(['redirect_uris' => ['http://evil.example/cb']]));

        self::assertNull($this->manager()->find(self::URL));
        self::assertSame([], $this->inner->list(null));
    }

    public function testRejectsAHostResolvingToAnInternalAddress(): void
    {
        $this->addresses = ['169.254.169.254'];

        self::assertNull($this->manager()->find(self::URL));
        self::assertSame([], $this->requested);
    }

    public function testRejectsARedirect(): void
    {
        $this->responses[] = new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: https://127.0.0.1/x']]);

        self::assertNull($this->manager()->find(self::URL));
        self::assertCount(1, $this->requested);
    }

    public function testRejectsAnOversizedDocument(): void
    {
        $this->respond($this->document(['client_name' => \str_repeat('a', ClientMetadataFetcher::MAX_BODY_SIZE)]));

        self::assertNull($this->manager()->find(self::URL));
    }

    public function testRemembersAFailureBrieflyAndThenTriesAgain(): void
    {
        $this->responses[] = new MockResponse('', ['http_code' => 500]);
        $this->respond($this->document());
        $manager = $this->manager();

        self::assertNull($manager->find(self::URL));
        self::assertNull($manager->find(self::URL));
        self::assertCount(1, $this->requested, 'A failed URL must not be fetched on every request.');

        $this->clock->sleep(CimdClientManager::FAILURE_TTL + 1);

        self::assertNotNull($manager->find(self::URL));
        self::assertCount(2, $this->requested);
    }

    public function testKeepsServingTheStoredClientWhileItsHostIsDown(): void
    {
        $this->respond($this->document(), 'Cache-Control: max-age=600');
        $this->responses[] = new MockResponse('', ['http_code' => 503]);
        $manager = $this->manager();

        $first = $manager->find(self::URL);
        $this->clock->sleep(601);
        $second = $manager->find(self::URL);

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame($first->getIdentifier(), $second->getIdentifier());
        self::assertCount(2, $this->requested);

        $manager->find(self::URL);
        self::assertCount(2, $this->requested, 'An outage must not be fetched again on every request.');
    }

    public function testStopsServingTheStoredClientAfterTheOutageOutlastsTheKeptEntry(): void
    {
        $this->respond($this->document(), 'Cache-Control: max-age=600');
        $this->responses[] = new MockResponse('', ['http_code' => 503]);
        $manager = $this->manager();

        $manager->find(self::URL);
        $this->clock->sleep(ClientMetadataFetcher::MAX_TTL + 1);

        self::assertNull($manager->find(self::URL));
    }

    public function testRejectsTheStoredClientWhenItsDocumentBecomesInvalid(): void
    {
        $this->respond($this->document(), 'Cache-Control: max-age=600');
        $this->respond($this->document(['client_id' => 'https://client.example/other']));
        $manager = $this->manager();

        $manager->find(self::URL);
        $this->clock->sleep(601);

        self::assertNull($manager->find(self::URL), 'Only an unreachable host keeps the old data; a rejected document does not.');
    }

    public function testDoesNotFetchOrStoreAClientForAnUnauthenticatedCaller(): void
    {
        $this->respond($this->document());

        self::assertNull($this->manager(signedIn: false)->find(self::URL));
        self::assertSame([], $this->requested, 'The token endpoint is public and must not make the server fetch a URL.');
        self::assertSame([], $this->inner->list(null));
    }

    public function testServesAStoredClientWithoutFetchingForAnUnauthenticatedCaller(): void
    {
        $this->respond($this->document(), 'Cache-Control: max-age=600');
        $first = $this->manager()->find(self::URL);
        $this->clock->sleep(ClientMetadataFetcher::MAX_TTL * 3);

        $second = $this->manager(signedIn: false)->find(self::URL);

        self::assertNotNull($first);
        self::assertSame($first->getIdentifier(), $second?->getIdentifier(), 'A refresh token must keep working at the token endpoint however old the document is.');
        self::assertCount(1, $this->requested);
    }

    public function testLogsAClientIdWithoutControlCharacters(): void
    {
        $logger = new class() extends AbstractLogger {
            /** @var list<array<string, mixed>> */
            public array $contexts = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->contexts[] = $context;
            }
        };

        $this->manager(logger: $logger)->find("https://client.example/x\n[fake] forged entry");

        self::assertSame('https://client.example/x?[fake] forged entry', $logger->contexts[0]['client_id'] ?? null);
    }

    public function testNeverTakesOverAClientThatHasASecret(): void
    {
        $identifier = CimdClientManager::storedIdentifier(self::URL);
        $this->inner->save(new Client('Secret client', $identifier, 'hashed'));
        $this->respond($this->document());

        self::assertNull($this->manager()->find(self::URL));
        self::assertSame('hashed', $this->inner->find($identifier)?->getSecret());
    }

    public function testPassesOtherIdentifiersThrough(): void
    {
        $this->inner->save(new Client('Registered', 'abc123', null));

        $client = $this->manager()->find('abc123');

        self::assertSame('abc123', $client?->getIdentifier());
        self::assertNull($this->manager()->find('unknown'));
        self::assertNull($this->manager()->find('http://client.example/metadata.json'));
        self::assertSame([], $this->requested);
    }

    public function testDoesNothingWhenDisabled(): void
    {
        $this->respond($this->document());

        self::assertNull($this->manager(enabled: false)->find(self::URL));
        self::assertSame([], $this->requested);
    }

    private function manager(bool $enabled = true, bool $signedIn = true, ?LoggerInterface $logger = null): CimdClientManager
    {
        $tokenStorage = new TokenStorage();
        if ($signedIn) {
            $tokenStorage->setToken(new UsernamePasswordToken(new InMemoryUser('admin', null), 'admin'));
        }

        $client = new MockHttpClient(function(string $method, string $url): MockResponse {
            $this->requested[] = $url;

            return \array_shift($this->responses) ?? new MockResponse('', ['http_code' => 500]);
        });

        return new CimdClientManager(
            $this->inner,
            new ClientMetadataFetcher($client, fn (): array => $this->addresses),
            new ClientMetadataValidator(),
            $this->cache ??= new ArrayAdapter(),
            $tokenStorage,
            ['mcp:tools', 'mcp:resources'],
            $enabled,
            logger: $logger ?? new NullLogger(),
            now: fn (): int => $this->clock->now()->getTimestamp(),
        );
    }

    private ?ArrayAdapter $cache = null;

    /**
     * @param array<string, mixed> $overrides
     */
    private function document(array $overrides = []): string
    {
        return \json_encode(\array_merge([
            'client_id' => self::URL,
            'client_name' => 'Example Client',
            'redirect_uris' => ['https://client.example/callback'],
        ], $overrides), \JSON_THROW_ON_ERROR);
    }

    private function respond(string $body, string $cacheControl = 'Cache-Control: max-age=3600'): void
    {
        $this->responses[] = new MockResponse($body, ['response_headers' => ['Content-Type: application/json', $cacheControl]]);
    }
}
