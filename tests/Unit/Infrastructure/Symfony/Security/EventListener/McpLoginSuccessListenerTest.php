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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sulu\Mcp\Infrastructure\Symfony\Security\EventListener\McpLoginSuccessListener;
use Sulu\Mcp\Tests\Unit\Fixture\TestUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

#[CoversClass(McpLoginSuccessListener::class)]
final class McpLoginSuccessListenerTest extends TestCase
{
    private const TARGET_PATH_KEY = '_security.admin.target_path';
    private const AUTHORIZE_URL = 'https://sulu.example.com/admin/mcp/authorize?response_type=code&client_id=abc&state=xyz';
    private const AUTHORIZE_RELATIVE = '/admin/mcp/authorize?response_type=code&client_id=abc&state=xyz';

    public function testRewritesJsonResponseToRedirectWhenTargetIsAuthorize(): void
    {
        $request = $this->requestWithTargetPath(self::AUTHORIZE_URL);
        $event = $this->event('admin', $request, new JsonResponse(['url' => '/admin/', 'completed' => true]));

        (new McpLoginSuccessListener($this->urlGenerator()))($event);

        $data = $this->json($event->getResponse());
        self::assertSame('redirect', $data['method']);
        self::assertSame(self::AUTHORIZE_RELATIVE, $data['url']);
        self::assertFalse($request->getSession()->has(self::TARGET_PATH_KEY));
    }

    public function testIgnoresNonAdminFirewall(): void
    {
        $request = $this->requestWithTargetPath(self::AUTHORIZE_URL);
        $event = $this->event('website', $request, new JsonResponse(['url' => '/admin/', 'completed' => true]));

        (new McpLoginSuccessListener($this->urlGenerator()))($event);

        $data = $this->json($event->getResponse());
        self::assertSame('/admin/', $data['url']);
        self::assertArrayNotHasKey('method', $data);
        self::assertTrue($request->getSession()->has(self::TARGET_PATH_KEY));
    }

    public function testLeavesResponseUnchangedWhenTargetIsNotAuthorize(): void
    {
        $request = $this->requestWithTargetPath('https://sulu.example.com/admin/#/dashboard');
        $event = $this->event('admin', $request, new JsonResponse(['url' => '/admin/', 'completed' => true]));

        (new McpLoginSuccessListener($this->urlGenerator()))($event);

        $data = $this->json($event->getResponse());
        self::assertSame('/admin/', $data['url']);
        self::assertArrayNotHasKey('method', $data);
    }

    public function testLeavesResponseUnchangedWhenTargetMerelySharesTheAuthorizePrefix(): void
    {
        $request = $this->requestWithTargetPath('https://sulu.example.com/admin/mcp/authorize-not-really');
        $event = $this->event('admin', $request, new JsonResponse(['url' => '/admin/', 'completed' => true]));

        (new McpLoginSuccessListener($this->urlGenerator()))($event);

        $data = $this->json($event->getResponse());
        self::assertSame('/admin/', $data['url']);
        self::assertArrayNotHasKey('method', $data);
    }

    public function testLeavesResponseUnchangedWhenNoTargetPath(): void
    {
        $request = $this->requestWithTargetPath(null);
        $event = $this->event('admin', $request, new JsonResponse(['url' => '/admin/', 'completed' => true]));

        (new McpLoginSuccessListener($this->urlGenerator()))($event);

        $data = $this->json($event->getResponse());
        self::assertSame('/admin/', $data['url']);
        self::assertArrayNotHasKey('method', $data);
    }

    public function testKeepsTargetWhenLoginNotCompleted(): void
    {
        $request = $this->requestWithTargetPath(self::AUTHORIZE_URL);
        $event = $this->event('admin', $request, new JsonResponse(['url' => '/admin/', 'completed' => false]));

        (new McpLoginSuccessListener($this->urlGenerator()))($event);

        $data = $this->json($event->getResponse());
        self::assertArrayNotHasKey('method', $data);
        // Target must survive so the subsequent two-factor step can still resume the authorize flow.
        self::assertTrue($request->getSession()->has(self::TARGET_PATH_KEY));
    }

    public function testRewritesRedirectResponseToRelativeTarget(): void
    {
        $request = $this->requestWithTargetPath(self::AUTHORIZE_URL);
        $event = $this->event('admin', $request, new RedirectResponse('/admin/'));

        (new McpLoginSuccessListener($this->urlGenerator()))($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(self::AUTHORIZE_RELATIVE, $response->getTargetUrl());
        self::assertFalse($request->getSession()->has(self::TARGET_PATH_KEY));
    }

    public function testDoesNothingWhenThereIsNoResponse(): void
    {
        $request = $this->requestWithTargetPath(self::AUTHORIZE_URL);
        $event = $this->event('admin', $request, null);

        (new McpLoginSuccessListener($this->urlGenerator()))($event);

        self::assertNull($event->getResponse());
        self::assertTrue($request->getSession()->has(self::TARGET_PATH_KEY));
    }

    private function requestWithTargetPath(?string $targetPath): Request
    {
        $session = new Session(new MockArraySessionStorage());
        if (null !== $targetPath) {
            $session->set(self::TARGET_PATH_KEY, $targetPath);
        }

        $request = new Request();
        $request->setSession($session);

        return $request;
    }

    private function event(string $firewallName, Request $request, ?Response $response): LoginSuccessEvent
    {
        return new LoginSuccessEvent(
            $this->authenticator(),
            new SelfValidatingPassport(new UserBadge('tester')),
            new UsernamePasswordToken(new TestUser(), $firewallName),
            $request,
            $response,
            $firewallName,
        );
    }

    /**
     * The listener never inspects the authenticator; a minimal real implementation
     * stands in for it.
     */
    private function authenticator(): AuthenticatorInterface
    {
        return new class() implements AuthenticatorInterface {
            public function supports(Request $request): ?bool
            {
                return true;
            }

            public function authenticate(Request $request): Passport
            {
                throw new \LogicException('Not used in tests.');
            }

            public function createToken(Passport $passport, string $firewallName): TokenInterface
            {
                throw new \LogicException('Not used in tests.');
            }

            public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
            {
                return null;
            }

            public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
            {
                return null;
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function json(?Response $response): array
    {
        self::assertInstanceOf(JsonResponse::class, $response);

        $data = \json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);

        return $data;
    }

    private function urlGenerator(): UrlGeneratorInterface
    {
        $routes = new RouteCollection();
        $routes->add('sulu_mcp_oauth_authorize', new Route('/admin/mcp/authorize'));

        return new UrlGenerator($routes, new RequestContext());
    }
}
