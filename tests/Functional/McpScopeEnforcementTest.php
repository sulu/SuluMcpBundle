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

namespace Sulu\Mcp\Tests\Functional;

use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/**
 * Drives the firewall, league's resource server and the scope listener with a
 * real access token, which no unit test can do.
 */
#[CoversNothing]
final class McpScopeEnforcementTest extends FunctionalTestCase
{
    private const PRM_URL = 'https://sulu-mcp-server.test/.well-known/oauth-protected-resource/admin/mcp';

    public function testRequestWithoutTokenIsChallengedWithTheResourceMetadata(): void
    {
        $response = $this->mcpRequest(null, '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}');

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString(
            'resource_metadata="' . self::PRM_URL . '"',
            (string) $response->headers->get('WWW-Authenticate'),
        );
    }

    public function testRejectedTokenIsReportedAsInvalidToken(): void
    {
        $response = $this->mcpRequest('garbage', '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}');

        self::assertSame(401, $response->getStatusCode());

        $challenge = (string) $response->headers->get('WWW-Authenticate');
        self::assertStringContainsString('error="invalid_token"', $challenge);
        self::assertStringContainsString('resource_metadata="' . self::PRM_URL . '"', $challenge);
    }

    public function testTokenWithoutTheResourceScopeCannotReadResources(): void
    {
        $response = $this->mcpRequest(
            $this->accessToken(),
            '{"jsonrpc":"2.0","id":1,"method":"resources/read","params":{"uri":"x"}}',
        );

        self::assertSame(403, $response->getStatusCode());

        $challenge = (string) $response->headers->get('WWW-Authenticate');
        self::assertStringContainsString('error="insufficient_scope"', $challenge);
        self::assertStringContainsString('scope="mcp:resources"', $challenge);

        $body = \json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertSame(-32003, $body['error']['code']);
        self::assertSame(1, $body['id']);
    }

    public function testTokenWithTheToolScopePassesTheHandshake(): void
    {
        $response = $this->mcpRequest(
            $this->accessToken(),
            '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"test","version":"1.0"}}}',
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    /**
     * A client_credentials token carrying `mcp:tools` only.
     */
    private function accessToken(): string
    {
        $container = self::getContainer();

        /** @var PasswordHasherInterface $hasher */
        $hasher = $container->get('league.oauth2_server.password_hasher');
        /** @var ClientManagerInterface $clientManager */
        $clientManager = $container->get(ClientManagerInterface::class);

        $identifier = 'mcp-scope-test';
        $secret = 'mcp-scope-test-secret';

        $client = new Client('MCP scope test', $identifier, $hasher->hash($secret));
        $client->setGrants(new Grant('client_credentials'));
        $client->setScopes(new Scope('mcp:tools'));
        $client->setActive(true);
        $clientManager->save($client);

        $response = $this->handle(Request::create('/admin/mcp/token', 'POST', [
            'grant_type' => 'client_credentials',
            'client_id' => $identifier,
            'client_secret' => $secret,
            'scope' => 'mcp:tools',
        ]));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $body = \json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertIsString($body['access_token']);

        return $body['access_token'];
    }

    private function mcpRequest(?string $token, string $body): Response
    {
        $request = Request::create('/admin/mcp', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ], content: $body);

        if (null !== $token) {
            $request->headers->set('Authorization', 'Bearer ' . $token);
        }

        return $this->handle($request);
    }

    private function handle(Request $request): Response
    {
        /** @var HttpKernelInterface $kernel */
        $kernel = self::getContainer()->get('http_kernel');

        return $kernel->handle($request);
    }
}
