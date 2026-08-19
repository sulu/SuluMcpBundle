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

namespace Sulu\Mcp\Tests\Unit\Infrastructure\Symfony\Security\EventListener;

use League\Bundle\OAuth2ServerBundle\Security\Authentication\Token\OAuth2Token;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Mcp\Infrastructure\Symfony\Security\EntryPoint\McpAuthenticationEntryPoint;
use Sulu\Mcp\Infrastructure\Symfony\Security\EventListener\McpScopeListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

#[CoversClass(McpScopeListener::class)]
final class McpScopeListenerTest extends TestCase
{
    use ProphecyTrait;

    private const SCOPES = ['mcp:tools', 'mcp:resources'];

    public function testIgnoresNonMcpPath(): void
    {
        $event = $this->requestEvent($this->postRequest('/admin', ['method' => 'resources/read']));

        $this->listener($this->oauthToken(['email']))->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testIgnoresSubRequest(): void
    {
        $event = $this->requestEvent(
            $this->postRequest('/_mcp', ['method' => 'resources/read']),
            HttpKernelInterface::SUB_REQUEST,
        );

        $this->listener($this->oauthToken(['email']))->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testIgnoresRequestWithoutToken(): void
    {
        $event = $this->requestEvent($this->postRequest('/_mcp', ['method' => 'resources/read']));

        $this->listener(null)->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testIgnoresTokenThatIsNotAnOAuth2Token(): void
    {
        $token = new UsernamePasswordToken($this->user(), 'admin', ['ROLE_USER']);
        $event = $this->requestEvent($this->postRequest('/_mcp', ['method' => 'resources/read']));

        $this->listener($token)->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testAllowsToolsCallWithToolsScope(): void
    {
        $event = $this->requestEvent($this->postRequest('/_mcp', ['method' => 'tools/call', 'id' => 7]));

        $this->listener($this->oauthToken(['mcp:tools']))->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testRejectsResourcesReadWithoutResourcesScope(): void
    {
        $event = $this->requestEvent($this->postRequest('/_mcp', ['method' => 'resources/read', 'id' => 7]));

        $this->listener($this->oauthToken(['mcp:tools']))->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(403, $response->getStatusCode());

        $challenge = (string) $response->headers->get('WWW-Authenticate');
        self::assertStringContainsString('error="insufficient_scope"', $challenge);
        self::assertStringContainsString('scope="mcp:resources"', $challenge);
        self::assertStringContainsString(
            'resource_metadata="https://sulu.example.com/.well-known/oauth-protected-resource/_mcp"',
            $challenge,
        );

        $body = \json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('2.0', $body['jsonrpc']);
        self::assertSame(-32003, $body['error']['code']);
        self::assertSame('Insufficient scope', $body['error']['message']);
        self::assertSame(7, $body['id']);
    }

    public function testRejectsInitializeWhenNoConfiguredScopeIsGranted(): void
    {
        $event = $this->requestEvent($this->postRequest('/_mcp', ['method' => 'initialize']));

        $this->listener($this->oauthToken(['email']))->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString(
            'scope="mcp:tools mcp:resources"',
            (string) $response->headers->get('WWW-Authenticate'),
        );

        $body = \json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertNull($body['id']);
    }

    public function testAllowsInitializeWithAnyConfiguredScope(): void
    {
        $event = $this->requestEvent($this->postRequest('/_mcp', ['method' => 'initialize']));

        $this->listener($this->oauthToken(['mcp:resources']))->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testAllowsGetWithoutBodyWhenAnyConfiguredScopeIsGranted(): void
    {
        $event = $this->requestEvent(Request::create('/_mcp', 'GET'));

        $this->listener($this->oauthToken(['mcp:resources']))->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testMalformedJsonBodyFallsBackToAnyConfiguredScope(): void
    {
        $request = Request::create('/_mcp', 'POST', [], [], [], [], '{not json');

        $event = $this->requestEvent($request);
        $this->listener($this->oauthToken(['mcp:resources']))->onKernelRequest($event);
        self::assertNull($event->getResponse());

        $event = $this->requestEvent($request);
        $this->listener($this->oauthToken(['email']))->onKernelRequest($event);
        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testRejectsBatchWhenOneElementNeedsAScopeTheTokenLacks(): void
    {
        $event = $this->requestEvent($this->postRequest('/_mcp', [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/read'],
        ]));

        $this->listener($this->oauthToken(['mcp:tools']))->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString(
            'scope="mcp:tools mcp:resources"',
            (string) $response->headers->get('WWW-Authenticate'),
        );

        $body = \json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertNull($body['id']);
    }

    public function testAllowsBatchWhenEveryDerivedScopeIsGranted(): void
    {
        $event = $this->requestEvent($this->postRequest('/_mcp', [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/read'],
        ]));

        $this->listener($this->oauthToken(['mcp:tools', 'mcp:resources']))->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testBatchOfUnmappedMethodsFollowsTheAnyScopeRule(): void
    {
        $body = [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'prompts/list'],
        ];

        $event = $this->requestEvent($this->postRequest('/_mcp', $body));
        $this->listener($this->oauthToken(['mcp:resources']))->onKernelRequest($event);
        self::assertNull($event->getResponse());

        $event = $this->requestEvent($this->postRequest('/_mcp', $body));
        $this->listener($this->oauthToken(['email']))->onKernelRequest($event);
        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString(
            'scope="mcp:tools mcp:resources"',
            (string) $response->headers->get('WWW-Authenticate'),
        );
    }

    public function testListOfNonObjectsFallsBackToTheAnyScopeRule(): void
    {
        $event = $this->requestEvent($this->postRequest('/_mcp', ['tools/call', 42]));

        $this->listener($this->oauthToken(['mcp:resources']))->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testRejectsPercentEncodedMcpPath(): void
    {
        $event = $this->requestEvent($this->postRequest('/_m%63p', ['method' => 'resources/read', 'id' => 7]));

        $this->listener($this->oauthToken(['mcp:tools']))->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString(
            'scope="mcp:resources"',
            (string) $response->headers->get('WWW-Authenticate'),
        );
    }

    #[DataProvider('provideLeadingWhitespaceWrappers')]
    public function testRejectsToolsCallSmuggledInAWhitespacePrefixedObjectWrapper(string $content): void
    {
        // The SDK dispatches a whitespace-prefixed object as its values, so the nested tools/call counts.
        $request = Request::create('/_mcp', 'POST', [], [], [], [], $content);
        $event = $this->requestEvent($request);

        $this->listener($this->oauthToken(['mcp:resources']))->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString(
            'scope="mcp:tools"',
            (string) $response->headers->get('WWW-Authenticate'),
        );

        $body = \json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertNull($body['id']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideLeadingWhitespaceWrappers(): iterable
    {
        $message = '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"sulu_page_create"}}';

        yield 'leading newline' => ["\n{\"x\":{$message}}"];
        yield 'leading space' => [" {\"x\":{$message}}"];
    }

    public function testWhitespacePrefixedSingleObjectFallsBackToTheAnyScopeRule(): void
    {
        // The SDK dispatches nothing for this shape, so the any-scope rule applies.
        $content = ' {"jsonrpc":"2.0","id":1,"method":"tools/call"}';

        $event = $this->requestEvent(Request::create('/_mcp', 'POST', [], [], [], [], $content));
        $this->listener($this->oauthToken(['mcp:resources']))->onKernelRequest($event);
        self::assertNull($event->getResponse());

        $event = $this->requestEvent(Request::create('/_mcp', 'POST', [], [], [], [], $content));
        $this->listener($this->oauthToken(['email']))->onKernelRequest($event);
        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString(
            'scope="mcp:tools mcp:resources"',
            (string) $response->headers->get('WWW-Authenticate'),
        );
    }

    private function listener(?TokenInterface $token): McpScopeListener
    {
        $tokenStorage = new TokenStorage();
        if (null !== $token) {
            $tokenStorage->setToken($token);
        }

        return new McpScopeListener(
            $tokenStorage,
            new McpAuthenticationEntryPoint('https://sulu.example.com', '/_mcp', self::SCOPES),
            '/_mcp',
            self::SCOPES,
        );
    }

    /**
     * @param list<string> $scopes
     */
    private function oauthToken(array $scopes): OAuth2Token
    {
        return new OAuth2Token($this->user(), 'access-token-id', 'client-id', $scopes, 'ROLE_OAUTH2_');
    }

    private function user(): InMemoryUser
    {
        return new InMemoryUser('admin', null, ['ROLE_USER']);
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private function postRequest(string $path, array $body): Request
    {
        return Request::create($path, 'POST', [], [], [], [], (string) \json_encode($body));
    }

    private function requestEvent(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        return new RequestEvent($this->prophesize(HttpKernelInterface::class)->reveal(), $request, $type);
    }
}
