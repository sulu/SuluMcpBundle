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

namespace Sulu\Mcp\Tests\Unit\Application\OAuth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sulu\Mcp\Application\OAuth\ClientMetadataFetcher;
use Sulu\Mcp\Application\OAuth\FetchedClientMetadata;
use Sulu\Mcp\Domain\Exception\ClientMetadataException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(ClientMetadataFetcher::class)]
#[CoversClass(FetchedClientMetadata::class)]
final class ClientMetadataFetcherTest extends TestCase
{
    private const URL = 'https://client.example/oauth/metadata.json';

    /** @var list<array{string, array<string, mixed>}> */
    private array $requests = [];

    public function testFetchesTheDocumentAndPinsTheCheckedAddress(): void
    {
        $result = $this->fetcher(['93.184.216.34'], new MockResponse('{"a":1}', ['response_headers' => ['Content-Type: application/json; charset=utf-8']]))->fetch(self::URL);

        self::assertSame('{"a":1}', $result->body);
        self::assertSame(ClientMetadataFetcher::DEFAULT_TTL, $result->ttl);
        self::assertCount(1, $this->requests);
        self::assertSame(['client.example' => '93.184.216.34'], $this->requests[0][1]['resolve']);
        self::assertSame(0, $this->requests[0][1]['max_redirects']);
    }

    /**
     * @param array<string, mixed> $info
     */
    #[DataProvider('provideTtl')]
    public function testDerivesTheLifetimeFromCacheHeaders(array $info, int $expected): void
    {
        $response = new MockResponse('{}', ['response_headers' => ['Content-Type: application/json', ...$info]]);

        self::assertSame($expected, $this->fetcher(['93.184.216.34'], $response)->fetch(self::URL)->ttl);
    }

    /**
     * @return iterable<string, array{list<string>, int}>
     */
    public static function provideTtl(): iterable
    {
        yield 'max-age' => [['Cache-Control: public, max-age=1200'], 1200];
        yield 'below the minimum' => [['Cache-Control: max-age=5'], ClientMetadataFetcher::MIN_TTL];
        yield 'above the maximum' => [['Cache-Control: max-age=99999999'], ClientMetadataFetcher::MAX_TTL];
        yield 'no-store' => [['Cache-Control: no-store'], ClientMetadataFetcher::MIN_TTL];
        yield 'none' => [[], ClientMetadataFetcher::DEFAULT_TTL];
    }

    #[DataProvider('provideRejectedUrls')]
    public function testRejectsUrlsThatAreNotFetchable(string $url): void
    {
        $this->expectException(ClientMetadataException::class);

        try {
            $this->fetcher(['93.184.216.34'], new MockResponse('{}'))->fetch($url);
        } finally {
            self::assertSame([], $this->requests);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRejectedUrls(): iterable
    {
        yield 'http' => ['http://client.example/m.json'];
        yield 'no path' => ['https://client.example'];
        yield 'root path' => ['https://client.example/'];
        yield 'credentials' => ['https://user:pw@client.example/m.json'];
        yield 'fragment' => ['https://client.example/m.json#x'];
        yield 'port' => ['https://client.example:8443/m.json'];
        yield 'ipv4 literal' => ['https://93.184.216.34/m.json'];
        yield 'loopback literal' => ['https://127.0.0.1/m.json'];
        yield 'ipv6 literal' => ['https://[::1]/m.json'];
        yield 'metadata literal' => ['https://169.254.169.254/latest/meta-data'];
        yield 'garbage' => ['not a url'];
    }

    /**
     * @param list<string> $addresses
     */
    #[DataProvider('provideInternalAddresses')]
    public function testRejectsHostsResolvingToInternalAddresses(array $addresses): void
    {
        $this->expectException(ClientMetadataException::class);
        $this->expectExceptionMessage('non-public');

        try {
            $this->fetcher($addresses, new MockResponse('{}'))->fetch(self::URL);
        } finally {
            self::assertSame([], $this->requests);
        }
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function provideInternalAddresses(): iterable
    {
        yield 'loopback' => [['127.0.0.1']];
        yield 'private 10/8' => [['10.1.2.3']];
        yield 'private 192.168' => [['192.168.0.5']];
        yield 'cloud metadata' => [['169.254.169.254']];
        yield 'carrier grade nat' => [['100.64.0.1']];
        yield 'ipv6 loopback' => [['::1']];
        yield 'ipv6 unique local' => [['fd00::1']];
        yield 'ipv4 mapped loopback' => [['::ffff:127.0.0.1']];
        yield 'mixed public and internal' => [['93.184.216.34', '10.0.0.1']];
    }

    public function testRejectsAHostThatDoesNotResolve(): void
    {
        $this->expectException(ClientMetadataException::class);
        $this->expectExceptionMessage('does not resolve');

        $this->fetcher([], new MockResponse('{}'))->fetch(self::URL);
    }

    public function testRefusesToFollowARedirect(): void
    {
        $response = new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: https://169.254.169.254/latest/meta-data']]);

        $this->expectException(ClientMetadataException::class);
        $this->expectExceptionMessage('redirects');

        try {
            $this->fetcher(['93.184.216.34'], $response)->fetch(self::URL);
        } finally {
            self::assertCount(1, $this->requests, 'The Location must never be requested.');
        }
    }

    public function testRejectsAnErrorStatus(): void
    {
        $this->expectException(ClientMetadataException::class);
        $this->expectExceptionMessage('HTTP 404');

        $this->fetcher(['93.184.216.34'], new MockResponse('', ['http_code' => 404, 'response_headers' => ['Content-Type: application/json']]))->fetch(self::URL);
    }

    public function testRejectsANonJsonContentType(): void
    {
        $this->expectException(ClientMetadataException::class);
        $this->expectExceptionMessage('application/json');

        $this->fetcher(['93.184.216.34'], new MockResponse('<html>', ['response_headers' => ['Content-Type: text/html']]))->fetch(self::URL);
    }

    public function testRejectsAnOversizedBody(): void
    {
        $body = '{"x":"' . \str_repeat('a', ClientMetadataFetcher::MAX_BODY_SIZE) . '"}';

        $this->expectException(ClientMetadataException::class);
        $this->expectExceptionMessage('larger than');

        $this->fetcher(['93.184.216.34'], new MockResponse($body, ['response_headers' => ['Content-Type: application/json']]))->fetch(self::URL);
    }

    public function testRejectsAnOversizedBodyThatUnderstatesItsLength(): void
    {
        $body = '{"x":"' . \str_repeat('a', ClientMetadataFetcher::MAX_BODY_SIZE) . '"}';
        $response = new MockResponse($body, ['response_headers' => ['Content-Type: application/json', 'Content-Length: 10']]);

        $this->expectException(ClientMetadataException::class);

        $this->fetcher(['93.184.216.34'], $response)->fetch(self::URL);
    }

    /**
     * @param list<string> $addresses
     */
    private function fetcher(array $addresses, MockResponse $response): ClientMetadataFetcher
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            $this->requests[] = [$url, $options];

            return $response;
        });

        return new ClientMetadataFetcher($client, static fn (string $host): array => $addresses);
    }
}
