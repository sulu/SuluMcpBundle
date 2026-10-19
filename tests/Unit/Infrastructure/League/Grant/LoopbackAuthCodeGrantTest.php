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

namespace Sulu\Mcp\Tests\Unit\Infrastructure\League\Grant;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Sulu\Mcp\Infrastructure\League\Grant\LoopbackAuthCodeGrant;

#[CoversClass(LoopbackAuthCodeGrant::class)]
final class LoopbackAuthCodeGrantTest extends TestCase
{
    /**
     * @param list<string> $registered
     */
    #[DataProvider('provideAcceptedRedirectUris')]
    public function testAcceptsRedirectUri(string $requested, array $registered): void
    {
        $this->validate($requested, $registered);

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideAcceptedRedirectUris(): iterable
    {
        yield 'localhost on a random port' => ['http://localhost:60020/callback', ['http://localhost/callback', 'http://127.0.0.1/callback']];
        yield 'localhost on the default port' => ['http://localhost/callback', ['http://localhost/callback']];
        yield 'registered with a port' => ['http://localhost:60020/callback', ['http://localhost:1234/callback']];
        yield '127.0.0.1 keeps league behaviour' => ['http://127.0.0.1:60020/callback', ['http://127.0.0.1/callback']];
        yield 'exact https match' => ['https://app.example.com/callback', ['https://app.example.com/callback']];
    }

    /**
     * @param list<string> $registered
     */
    #[DataProvider('provideRejectedRedirectUris')]
    public function testRejectsRedirectUri(string $requested, array $registered): void
    {
        $this->expectException(OAuthServerException::class);
        $this->expectExceptionMessage('Client authentication failed');

        $this->validate($requested, $registered);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideRejectedRedirectUris(): iterable
    {
        yield 'other path' => ['http://localhost:60020/other', ['http://localhost/callback']];
        yield 'other query' => ['http://localhost:60020/callback?x=1', ['http://localhost/callback']];
        yield 'https localhost against http' => ['https://localhost:60020/callback', ['http://localhost/callback']];
        yield 'http localhost against https' => ['http://localhost:60020/callback', ['https://localhost/callback']];
        yield 'other host with the same path' => ['http://localhost.evil.test:60020/callback', ['http://localhost/callback']];
        yield 'userinfo on the host' => ['http://localhost:60020@evil.test/callback', ['http://localhost/callback']];
        yield 'non loopback host on a port' => ['https://app.example.com:8443/callback', ['https://app.example.com/callback']];
        yield 'not a uri' => ['not a uri', ['http://localhost/callback']];
    }

    /**
     * @param list<string> $registered
     */
    private function validate(string $requested, array $registered): void
    {
        $grant = new LoopbackAuthCodeGrant(
            $this->createStub(AuthCodeRepositoryInterface::class),
            $this->createStub(RefreshTokenRepositoryInterface::class),
            new \DateInterval('PT10M'),
        );

        $client = $this->createStub(ClientEntityInterface::class);
        $client->method('getRedirectUri')->willReturn($registered);

        $method = new \ReflectionMethod($grant, 'validateRedirectUri');
        $method->invoke($grant, $requested, $client, $this->createStub(ServerRequestInterface::class));
    }
}
