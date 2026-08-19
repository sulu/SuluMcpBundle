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

namespace Sulu\Mcp\Tests\Unit\Infrastructure\Symfony\Security\EntryPoint;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Mcp\Infrastructure\Symfony\Security\EntryPoint\McpAuthenticationEntryPoint;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(McpAuthenticationEntryPoint::class)]
final class McpAuthenticationEntryPointTest extends TestCase
{
    use ProphecyTrait;

    private const PRM_URL = 'https://sulu.example.com/.well-known/oauth-protected-resource/_mcp';

    private McpAuthenticationEntryPoint $entryPoint;

    protected function setUp(): void
    {
        $this->entryPoint = new McpAuthenticationEntryPoint('https://sulu.example.com', '/_mcp');
    }

    public function testResourceMetadataUrlPointsAtThePrmDocument(): void
    {
        self::assertSame(self::PRM_URL, $this->entryPoint->resourceMetadataUrl());
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function provideCompletableChallenges(): iterable
    {
        yield 'no challenge' => [null];
        yield 'league bare scheme' => ['Bearer'];
        yield 'lowercase scheme' => ['bearer'];
    }

    #[DataProvider('provideCompletableChallenges')]
    public function testCompletesTheChallengeOfARejectedBearer(?string $challenge): void
    {
        $request = Request::create('/_mcp', 'POST');
        $request->headers->set('Authorization', 'Bearer expired-token');
        $response = new Response('', 401, null === $challenge ? [] : ['WWW-Authenticate' => $challenge]);

        $event = $this->responseEvent($request, $response);
        $this->entryPoint->onKernelResponse($event);

        self::assertSame(401, $event->getResponse()->getStatusCode());

        $completed = (string) $event->getResponse()->headers->get('WWW-Authenticate');
        self::assertStringContainsString('error="invalid_token"', $completed);
        self::assertStringContainsString('resource_metadata="' . self::PRM_URL . '"', $completed);
    }

    public function testLeavesResponseThatAlreadyCarriesResourceMetadataUntouched(): void
    {
        $original = $this->entryPoint->start(Request::create('/_mcp', 'POST'));
        $event = $this->responseEvent(Request::create('/_mcp', 'POST'), $original);

        $this->entryPoint->onKernelResponse($event);

        self::assertSame($original, $event->getResponse());
    }

    public function testLeavesNonBearerChallengeUntouched(): void
    {
        $response = new Response('', 401, ['WWW-Authenticate' => 'Basic realm="Sulu"']);
        $event = $this->responseEvent(Request::create('/_mcp', 'POST'), $response);

        $this->entryPoint->onKernelResponse($event);

        self::assertSame($response, $event->getResponse());
        self::assertSame('Basic realm="Sulu"', $response->headers->get('WWW-Authenticate'));
    }

    public function testLeavesUnauthorizedOnAnotherPathUntouched(): void
    {
        $response = new Response('', 401);
        $event = $this->responseEvent(Request::create('/admin', 'POST'), $response);

        $this->entryPoint->onKernelResponse($event);

        self::assertSame($response, $event->getResponse());
        self::assertFalse($response->headers->has('WWW-Authenticate'));
    }

    public function testLeavesSuccessfulResponseUntouched(): void
    {
        $response = new JsonResponse(['jsonrpc' => '2.0', 'result' => [], 'id' => 1]);
        $event = $this->responseEvent(Request::create('/_mcp', 'POST'), $response);

        $this->entryPoint->onKernelResponse($event);

        self::assertSame($response, $event->getResponse());
        self::assertFalse($response->headers->has('WWW-Authenticate'));
    }

    public function testCompletesUnauthorizedOnPercentEncodedMcpPath(): void
    {
        $event = $this->responseEvent(Request::create('/_m%63p', 'POST'), new Response('', 401));

        $this->entryPoint->onKernelResponse($event);

        self::assertSame(
            \sprintf('Bearer resource_metadata="%s", scope="mcp:tools mcp:resources"', self::PRM_URL),
            (string) $event->getResponse()->headers->get('WWW-Authenticate'),
        );
    }

    public function testStartWithoutBearerLeavesTheErrorCodeOut(): void
    {
        $response = $this->entryPoint->start(Request::create('/_mcp', 'POST'));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(
            \sprintf('Bearer resource_metadata="%s", scope="mcp:tools mcp:resources"', self::PRM_URL),
            (string) $response->headers->get('WWW-Authenticate'),
        );

        $body = \json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('2.0', $body['jsonrpc']);
        self::assertSame(-32001, $body['error']['code']);
        self::assertNull($body['id']);
    }

    public function testStartWithBearerReportsAnInvalidToken(): void
    {
        $request = Request::create('/_mcp', 'POST');
        $request->headers->set('Authorization', 'bearer expired-token');

        $challenge = (string) $this->entryPoint->start($request)->headers->get('WWW-Authenticate');

        self::assertSame(
            \sprintf('Bearer error="invalid_token", resource_metadata="%s", scope="mcp:tools mcp:resources"', self::PRM_URL),
            $challenge,
        );
    }

    private function responseEvent(Request $request, Response $response): ResponseEvent
    {
        return new ResponseEvent(
            $this->prophesize(HttpKernelInterface::class)->reveal(),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );
    }
}
