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

namespace Sulu\Mcp\Tests\Unit\Infrastructure\League\EventListener;

use League\Bundle\OAuth2ServerBundle\Event\AuthorizationRequestResolveEvent;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sulu\Mcp\Domain\Model\OAuthConsentRequest;
use Sulu\Mcp\Infrastructure\League\EventListener\OAuthAuthorizationListener;
use Sulu\Mcp\Infrastructure\Symfony\Security\OAuthConsentStore;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[CoversClass(OAuthAuthorizationListener::class)]
#[CoversClass(OAuthConsentRequest::class)]
#[CoversClass(OAuthConsentStore::class)]
final class OAuthAuthorizationListenerTest extends TestCase
{
    public function testRedirectsAuthorizationRequestToConsentView(): void
    {
        $store = new OAuthConsentStore();
        $request = $this->request('/admin/mcp/authorize?response_type=code&client_id=client-1&state=state-1');
        $event = $this->event();

        $this->listener($store, $request)($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertMatchesRegularExpression('~^/admin/#/mcp/authorize/[a-f0-9]{32}$~', $response->getTargetUrl());
        self::assertFalse($event->getAuthorizationResolution());

        $requestId = \basename($response->getTargetUrl());
        $consentRequest = $store->get($request, $requestId);
        self::assertNotNull($consentRequest);
        self::assertSame('/admin/mcp/authorize?response_type=code&client_id=client-1&state=state-1', $consentRequest->getAuthorizationUrl());
        self::assertStringContainsString('sulu_mcp_consent=' . $requestId, $consentRequest->getContinuationUrl());
    }

    public function testConsumesApprovalAndApprovesAuthorization(): void
    {
        $store = new OAuthConsentStore();
        $request = $this->request('/admin/mcp/authorize?response_type=code&client_id=client-1&state=state-1');
        $consentRequest = $store->create($request, $this->event());
        $store->decide($request, $consentRequest->getId(), true);

        $event = $this->event();
        $this->listener($store, $this->request($consentRequest->getContinuationUrl(), $request->getSession()))($event);

        self::assertNull($event->getResponse());
        self::assertTrue($event->getAuthorizationResolution());
        self::assertNull($store->get($request, $consentRequest->getId()));
    }

    public function testConsumesDenialAndDeniesAuthorization(): void
    {
        $store = new OAuthConsentStore();
        $request = $this->request('/admin/mcp/authorize?response_type=code&client_id=client-1&state=state-1');
        $consentRequest = $store->create($request, $this->event());
        $store->decide($request, $consentRequest->getId(), false);

        $event = $this->event();
        $this->listener($store, $this->request($consentRequest->getContinuationUrl(), $request->getSession()))($event);

        self::assertNull($event->getResponse());
        self::assertFalse($event->getAuthorizationResolution());
        self::assertNull($store->get($request, $consentRequest->getId()));
    }

    public function testRedirectsBackToExistingConsentViewWhenDecisionIsMissing(): void
    {
        $store = new OAuthConsentStore();
        $request = $this->request('/admin/mcp/authorize?response_type=code&client_id=client-1&state=state-1');
        $consentRequest = $store->create($request, $this->event());

        $event = $this->event();
        $this->listener($store, $this->request($consentRequest->getContinuationUrl(), $request->getSession()))($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/#/mcp/authorize/' . $consentRequest->getId(), $response->getTargetUrl());
        self::assertNotNull($store->get($request, $consentRequest->getId()));
    }

    private function listener(OAuthConsentStore $store, Request $request): OAuthAuthorizationListener
    {
        $requestStack = new RequestStack();
        $requestStack->push($request);

        return new OAuthAuthorizationListener($requestStack, $store, $this->urlGenerator());
    }

    private function urlGenerator(): UrlGeneratorInterface
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $name): string => 'sulu_admin' === $name
                ? '/admin/'
                : self::fail('Unexpected route "' . $name . '".'),
        );

        return $urlGenerator;
    }

    private function request(string $uri, ?Session $session = null): Request
    {
        $request = Request::create($uri);
        $request->setSession($session ?? new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function event(): AuthorizationRequestResolveEvent
    {
        $authorizationRequest = $this->createMock(AuthorizationRequestInterface::class);
        $authorizationRequest->method('getRedirectUri')->willReturn('https://chatgpt.com/oauth/callback');
        $authorizationRequest->method('getState')->willReturn('state-1');

        return new AuthorizationRequestResolveEvent(
            $authorizationRequest,
            [new Scope('mcp:tools')],
            new Client('ChatGPT', 'client-1', 'secret'),
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
