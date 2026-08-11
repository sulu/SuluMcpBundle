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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Controller\Admin;

use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\ClientInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sulu\Mcp\UserInterface\Controller\Admin\DynamicClientRegistrationController;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(DynamicClientRegistrationController::class)]
final class DynamicClientRegistrationControllerTest extends TestCase
{
    private ClientManagerInterface&MockObject $clientManager;
    private DynamicClientRegistrationController $controller;

    protected function setUp(): void
    {
        $this->clientManager = $this->createMock(ClientManagerInterface::class);
        $this->controller = new DynamicClientRegistrationController(
            $this->clientManager,
            ['mcp:tools'],
        );
    }

    public function testRegisterPersistsClientWithValidatedMetadata(): void
    {
        $capturedClient = null;
        $this->clientManager->expects($this->once())
            ->method('save')
            ->willReturnCallback(static function (ClientInterface $client) use (&$capturedClient): void {
                $capturedClient = $client;
            });

        $response = $this->controller->register($this->jsonRequest([
            'client_name' => 'Claude Code',
            'redirect_uris' => ['http://127.0.0.1:12345/callback'],
            'grant_types' => ['authorization_code'],
            'scope' => 'mcp:tools',
            'token_endpoint_auth_method' => 'client_secret_basic',
        ]));

        self::assertSame(201, $response->getStatusCode());
        self::assertInstanceOf(ClientInterface::class, $capturedClient);
        self::assertSame('Claude Code', $capturedClient->getName());
        self::assertSame(['http://127.0.0.1:12345/callback'], $this->stringValues($capturedClient->getRedirectUris()));
        self::assertSame(['authorization_code', 'refresh_token'], $this->stringValues($capturedClient->getGrants()));
        self::assertSame(['mcp:tools'], $this->stringValues($capturedClient->getScopes()));

        $body = $this->json($response->getContent());
        self::assertSame('client_secret_basic', $body['token_endpoint_auth_method']);
        self::assertSame('mcp:tools', $body['scope']);
    }

    public function testRegisterRejectsInvalidJson(): void
    {
        $this->clientManager->expects($this->never())->method('save');

        $response = $this->controller->register(Request::create('/admin/mcp/register', 'POST', [], [], [], [], '{'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_client_metadata', $this->json($response->getContent())['error']);
    }

    public function testRegisterRejectsUnsafeRedirectUri(): void
    {
        $this->clientManager->expects($this->never())->method('save');

        $response = $this->controller->register($this->jsonRequest([
            'redirect_uris' => ['http://example.com/callback'],
        ]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_client_metadata', $this->json($response->getContent())['error']);
    }

    public function testRegisterRejectsUnsupportedGrantType(): void
    {
        $this->clientManager->expects($this->never())->method('save');

        $response = $this->controller->register($this->jsonRequest([
            'redirect_uris' => ['https://client.example.com/callback'],
            'grant_types' => ['password'],
        ]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_client_metadata', $this->json($response->getContent())['error']);
    }

    public function testRegisterRegistersPublicClientWithoutSecret(): void
    {
        $capturedClient = null;
        $this->clientManager->expects($this->once())
            ->method('save')
            ->willReturnCallback(static function (ClientInterface $client) use (&$capturedClient): void {
                $capturedClient = $client;
            });

        $response = $this->controller->register($this->jsonRequest([
            'client_name' => 'Codex',
            'redirect_uris' => ['http://127.0.0.1:1455/auth/callback'],
            'token_endpoint_auth_method' => 'none',
        ]));

        self::assertSame(201, $response->getStatusCode());
        self::assertInstanceOf(ClientInterface::class, $capturedClient);
        self::assertNull($capturedClient->getSecret());
        self::assertFalse($capturedClient->isConfidential());

        $body = $this->json($response->getContent());
        self::assertSame('none', $body['token_endpoint_auth_method']);
        self::assertArrayNotHasKey('client_secret', $body);
    }

    public function testRegisterAcceptsPrivateUseSchemeRedirectUri(): void
    {
        $capturedClient = null;
        $this->clientManager->expects($this->once())
            ->method('save')
            ->willReturnCallback(static function (ClientInterface $client) use (&$capturedClient): void {
                $capturedClient = $client;
            });

        $response = $this->controller->register($this->jsonRequest([
            'client_name' => 'Native App',
            'redirect_uris' => ['com.example.app:/oauth/callback'],
            'token_endpoint_auth_method' => 'none',
        ]));

        self::assertSame(201, $response->getStatusCode());
        self::assertInstanceOf(ClientInterface::class, $capturedClient);
        self::assertSame(['com.example.app:/oauth/callback'], $this->stringValues($capturedClient->getRedirectUris()));
    }

    public function testRegisterGrantsIntersectionOfRequestedScopes(): void
    {
        $capturedClient = null;
        $this->clientManager->expects($this->once())
            ->method('save')
            ->willReturnCallback(static function (ClientInterface $client) use (&$capturedClient): void {
                $capturedClient = $client;
            });

        $response = $this->controller->register($this->jsonRequest([
            'redirect_uris' => ['https://client.example.com/callback'],
            'scope' => 'openid mcp:tools profile',
        ]));

        self::assertSame(201, $response->getStatusCode());
        self::assertInstanceOf(ClientInterface::class, $capturedClient);
        self::assertSame(['mcp:tools'], $this->stringValues($capturedClient->getScopes()));
        self::assertSame('mcp:tools', $this->json($response->getContent())['scope']);
    }

    public function testRegisterRejectsScopesWhenNoneOverlap(): void
    {
        $this->clientManager->expects($this->never())->method('save');

        $response = $this->controller->register($this->jsonRequest([
            'redirect_uris' => ['https://client.example.com/callback'],
            'scope' => 'openid profile',
        ]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_client_metadata', $this->json($response->getContent())['error']);
    }

    public function testRegisterRejectsDangerousPrivateSchemeRedirectUri(): void
    {
        $this->clientManager->expects($this->never())->method('save');

        $response = $this->controller->register($this->jsonRequest([
            'redirect_uris' => ['file:/tmp/callback'],
            'token_endpoint_auth_method' => 'none',
        ]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_client_metadata', $this->json($response->getContent())['error']);
    }

    public function testRegisterAcceptsIpv6LoopbackRedirectUri(): void
    {
        $capturedClient = null;
        $this->clientManager->expects($this->once())
            ->method('save')
            ->willReturnCallback(static function (ClientInterface $client) use (&$capturedClient): void {
                $capturedClient = $client;
            });

        $response = $this->controller->register($this->jsonRequest([
            'redirect_uris' => ['http://[::1]:1455/auth/callback'],
            'token_endpoint_auth_method' => 'none',
        ]));

        self::assertSame(201, $response->getStatusCode());
        self::assertInstanceOf(ClientInterface::class, $capturedClient);
        self::assertSame(['http://[::1]:1455/auth/callback'], $this->stringValues($capturedClient->getRedirectUris()));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(array $body): Request
    {
        $content = json_encode($body, \JSON_THROW_ON_ERROR);
        self::assertIsString($content);

        return Request::create('/admin/mcp/register', 'POST', [], [], [], [], $content);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string|false $content): array
    {
        self::assertIsString($content);
        $data = json_decode($content, true);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @param list<\Stringable> $values
     *
     * @return list<string>
     */
    private function stringValues(array $values): array
    {
        return array_map(static fn (\Stringable $value): string => (string) $value, $values);
    }
}
