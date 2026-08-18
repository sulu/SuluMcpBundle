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

namespace Sulu\Mcp\Tests\Unit\Domain\Model;

use League\Bundle\OAuth2ServerBundle\Event\AuthorizationRequestResolveEvent;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Mcp\Domain\Model\OAuthConsentRequest;
use Sulu\Mcp\Infrastructure\Symfony\Security\OAuthConsentStore;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\User\UserInterface;

#[CoversClass(OAuthConsentRequest::class)]
#[CoversClass(OAuthConsentStore::class)]
final class OAuthConsentStoreTest extends TestCase
{
    use ProphecyTrait;

    public function testCreateStoresAuthorizationRequestMetadata(): void
    {
        $store = new OAuthConsentStore();
        $request = $this->request('/admin/mcp/authorize?response_type=code&client_id=client-1&state=state-1');
        $event = $this->event(['mcp:tools', 'mcp:resources']);

        $consentRequest = $store->create($request, $event);

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $consentRequest->getId());
        self::assertSame('/admin/mcp/authorize?response_type=code&client_id=client-1&state=state-1', $consentRequest->getAuthorizationUrl());
        self::assertStringStartsWith('/admin/mcp/authorize?', $consentRequest->getContinuationUrl());
        self::assertStringContainsString('sulu_mcp_consent=' . $consentRequest->getId(), $consentRequest->getContinuationUrl());
        self::assertStringContainsString('client_id=client-1', $consentRequest->getContinuationUrl());
        self::assertSame('client-1', $consentRequest->getClientId());
        self::assertSame('Claude Code', $consentRequest->getClientName());
        self::assertSame('https://client.example.com/callback', $consentRequest->getRedirectUri());
        self::assertSame(['mcp:tools', 'mcp:resources'], $consentRequest->getScopes());
        self::assertSame('state-1', $consentRequest->getState());
        self::assertNull($consentRequest->getApproved());

        self::assertEquals($consentRequest, $store->get($request, $consentRequest->getId()));
    }

    public function testDecideStoresDecisionAndConsumeDecisionRemovesRequest(): void
    {
        $store = new OAuthConsentStore();
        $request = $this->request('/admin/mcp/authorize?response_type=code&client_id=client-1');
        $consentRequest = $store->create($request, $this->event(['mcp:tools']));

        $decided = $store->decide($request, $consentRequest->getId(), false);

        self::assertInstanceOf(OAuthConsentRequest::class, $decided);
        self::assertFalse($decided->getApproved());
        self::assertFalse($store->consumeDecision($request, $consentRequest->getId()));
        self::assertNull($store->get($request, $consentRequest->getId()));
    }

    public function testCreatePreservesOriginalAuthorizeQueryEncoding(): void
    {
        $store = new OAuthConsentStore();
        $request = $this->request('/admin/mcp/authorize?redirect_uri=https%3A%2F%2Fclient.example%2Fcallback%3Fx%3Da%2Bb&scope=mcp%3Atools+mcp%3Aresources&sulu_mcp_consent=old');

        $consentRequest = $store->create($request, $this->event(['mcp:tools']));

        self::assertSame(
            '/admin/mcp/authorize?redirect_uri=https%3A%2F%2Fclient.example%2Fcallback%3Fx%3Da%2Bb&scope=mcp%3Atools+mcp%3Aresources',
            $consentRequest->getAuthorizationUrl(),
        );
        self::assertSame(
            $consentRequest->getAuthorizationUrl() . '&sulu_mcp_consent=' . $consentRequest->getId(),
            $consentRequest->getContinuationUrl(),
        );
    }

    public function testMissingRequestReturnsNull(): void
    {
        $store = new OAuthConsentStore();
        $request = $this->request('/admin/mcp/authorize');

        self::assertNull($store->get($request, 'missing'));
        self::assertNull($store->decide($request, 'missing', true));
        self::assertNull($store->consumeDecision($request, 'missing'));
    }

    private function request(string $uri): Request
    {
        $request = Request::create($uri);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    /**
     * @param list<string> $scopes
     */
    private function event(array $scopes): AuthorizationRequestResolveEvent
    {
        $authorizationRequest = $this->prophesize(AuthorizationRequestInterface::class);
        $authorizationRequest->getRedirectUri(Argument::cetera())->willReturn('https://client.example.com/callback');
        $authorizationRequest->getState(Argument::cetera())->willReturn('state-1');

        return new AuthorizationRequestResolveEvent(
            $authorizationRequest->reveal(),
            \array_map(static fn (string $scope): Scope => new Scope($scope), $scopes),
            new Client('Claude Code', 'client-1', null),
            new class() implements UserInterface {
                public function getRoles(): array
                {
                    return [];
                }

                public function eraseCredentials(): void
                {
                }

                public function getUserIdentifier(): string
                {
                    return 'admin';
                }
            },
        );
    }
}
