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

#[CoversNothing]
final class StatelessProtocolTest extends FunctionalTestCase
{
    private const REVISION = '2026-07-28';

    public function testDiscoverAnswersWithoutASession(): void
    {
        $response = $this->statelessRequest($this->accessToken(), 'server/discover');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertNull($response->headers->get('Mcp-Session-Id'));
        self::assertSame([self::REVISION], $this->decode($response)['result']['supportedVersions']);
    }

    public function testToolCallRunsAsTheTokenUserWithoutASession(): void
    {
        $response = $this->statelessRequest($this->accessToken(), 'tools/call', ['name' => 'sulu_ping', 'arguments' => new \stdClass()], 'sulu_ping');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertNull($response->headers->get('Mcp-Session-Id'));

        $result = $this->decode($response)['result'];
        self::assertFalse($result['isError']);
        self::assertSame('stateless-test', $result['structuredContent']['user']);
    }

    public function testAToolsOnlyTokenCannotReadResources(): void
    {
        $response = $this->statelessRequest($this->accessToken(), 'resources/read', ['uri' => 'x']);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(-32003, $this->decode($response)['error']['code']);
    }

    public function testTheNameHeaderHasToMatchTheBody(): void
    {
        $response = $this->statelessRequest($this->accessToken(), 'tools/call', ['name' => 'sulu_ping', 'arguments' => new \stdClass()], 'other');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(-32020, $this->decode($response)['error']['code']);
    }

    public function testTheRequestHasToCarryItsOwnMeta(): void
    {
        $response = $this->send($this->accessToken(), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => new \stdClass()], [
            'HTTP_MCP_METHOD' => 'tools/list',
            'HTTP_MCP_PROTOCOL_VERSION' => self::REVISION,
        ]);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(-32602, $this->decode($response)['error']['code']);
    }

    public function testABodyThatIsNoJsonIsABadRequestNotAServerError(): void
    {
        $request = Request::create('/admin/mcp', 'POST', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'], content: '{not json');
        $request->headers->set('Authorization', 'Bearer ' . $this->accessToken());

        $response = $this->handle($request);

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(-32700, $this->decode($response)['error']['code']);
    }

    public function testAMethodTheEndpointDoesNotAcceptIsMethodNotAllowed(): void
    {
        $request = Request::create('/admin/mcp', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        $request->headers->set('Authorization', 'Bearer ' . $this->accessToken());

        $response = $this->handle($request);

        self::assertSame(405, $response->getStatusCode(), (string) $response->getContent());
        self::assertNotEmpty($response->headers->get('Allow'));
    }

    public function testTheHandshakeEraStillOpensASession(): void
    {
        $response = $this->send($this->accessToken(), [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1.0']],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertNotEmpty($response->headers->get('Mcp-Session-Id'));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function statelessRequest(string $token, string $method, array $params = [], ?string $name = null): Response
    {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => self::REVISION,
            'io.modelcontextprotocol/clientInfo' => ['name' => 'test', 'version' => '1.0'],
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
        ];

        $server = ['HTTP_MCP_METHOD' => $method, 'HTTP_MCP_PROTOCOL_VERSION' => self::REVISION];
        if (null !== $name) {
            $server['HTTP_MCP_NAME'] = $name;
        }

        return $this->send($token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], $server);
    }

    /**
     * @param array<string, mixed> $message
     * @param array<string, string> $server
     */
    private function send(string $token, array $message, array $server = []): Response
    {
        $request = Request::create('/admin/mcp', 'POST', server: $server + [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ], content: \json_encode($message, \JSON_THROW_ON_ERROR));
        $request->headers->set('Authorization', 'Bearer ' . $token);

        return $this->handle($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $body = \json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);

        return $body;
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

        $client = new Client('stateless-test', 'stateless-test', $hasher->hash('stateless-test-secret'));
        $client->setGrants(new Grant('client_credentials'));
        $client->setScopes(new Scope('mcp:tools'));
        $client->setActive(true);
        $clientManager->save($client);

        $response = $this->handle(Request::create('/admin/mcp/token', 'POST', [
            'grant_type' => 'client_credentials',
            'client_id' => 'stateless-test',
            'client_secret' => 'stateless-test-secret',
            'scope' => 'mcp:tools',
        ]));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $body = $this->decode($response);
        self::assertIsString($body['access_token']);

        return $body['access_token'];
    }

    private function handle(Request $request): Response
    {
        /** @var HttpKernelInterface $kernel */
        $kernel = self::getContainer()->get('http_kernel');

        return $kernel->handle($request);
    }
}
