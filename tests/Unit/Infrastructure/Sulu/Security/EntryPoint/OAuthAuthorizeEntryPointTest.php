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

namespace Sulu\Mcp\Tests\Unit\Infrastructure\Sulu\Security\EntryPoint;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sulu\Mcp\Infrastructure\Sulu\Security\EntryPoint\OAuthAuthorizeEntryPoint;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

#[CoversClass(OAuthAuthorizeEntryPoint::class)]
final class OAuthAuthorizeEntryPointTest extends TestCase
{
    public function testRedirectsToAdminLoginOnAuthorizePath(): void
    {
        $inner = new RecordingAuthenticationEntryPoint();

        $entryPoint = new OAuthAuthorizeEntryPoint($inner, $this->urlGenerator());
        $response = $entryPoint->start(Request::create('/admin/mcp/authorize'));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/admin/', $response->getTargetUrl());
        $this->assertSame(0, $inner->startCallCount);
    }

    public function testDelegatesToInnerEntryPointForOtherAdminPaths(): void
    {
        $innerResponse = new Response('inner');
        $inner = new RecordingAuthenticationEntryPoint($innerResponse);

        $entryPoint = new OAuthAuthorizeEntryPoint($inner, $this->urlGenerator());
        $response = $entryPoint->start(Request::create('/admin'));

        $this->assertSame($innerResponse, $response);
        $this->assertSame(1, $inner->startCallCount);
    }

    public function testDelegatesToInnerEntryPointForPathMerelyContainingAuthorizeFragment(): void
    {
        $innerResponse = new Response('inner');
        $inner = new RecordingAuthenticationEntryPoint($innerResponse);

        $entryPoint = new OAuthAuthorizeEntryPoint($inner, $this->urlGenerator());
        $response = $entryPoint->start(Request::create('/evil/mcp/authorize'));

        $this->assertSame($innerResponse, $response);
        $this->assertSame(1, $inner->startCallCount);
    }

    public function testDelegatesToInnerEntryPointForAdjacentPathSharingPrefix(): void
    {
        $innerResponse = new Response('inner');
        $inner = new RecordingAuthenticationEntryPoint($innerResponse);

        $entryPoint = new OAuthAuthorizeEntryPoint($inner, $this->urlGenerator());
        $response = $entryPoint->start(Request::create('/admin/mcp/authorize-not-really'));

        $this->assertSame($innerResponse, $response);
        $this->assertSame(1, $inner->startCallCount);
    }

    private function urlGenerator(): UrlGeneratorInterface
    {
        $routes = new RouteCollection();
        $routes->add('sulu_mcp_oauth_authorize', new Route('/admin/mcp/authorize'));
        $routes->add('sulu_admin', new Route('/admin/'));

        return new UrlGenerator($routes, new RequestContext());
    }
}

final class RecordingAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    public int $startCallCount = 0;

    public function __construct(private readonly Response $response = new Response())
    {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        ++$this->startCallCount;

        return $this->response;
    }
}
