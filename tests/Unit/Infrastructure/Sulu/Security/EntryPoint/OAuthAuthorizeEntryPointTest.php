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
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

#[CoversClass(OAuthAuthorizeEntryPoint::class)]
final class OAuthAuthorizeEntryPointTest extends TestCase
{
    public function testRedirectsToAdminLoginOnAuthorizePath(): void
    {
        $inner = $this->createMock(AuthenticationEntryPointInterface::class);
        $inner->expects(self::never())->method('start');

        $entryPoint = new OAuthAuthorizeEntryPoint($inner);
        $response = $entryPoint->start(Request::create('/admin/mcp/authorize'));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/admin/', $response->getTargetUrl());
    }

    public function testDelegatesToInnerEntryPointForOtherAdminPaths(): void
    {
        $innerResponse = new Response('inner');
        $inner = $this->createMock(AuthenticationEntryPointInterface::class);
        $inner->expects(self::once())->method('start')->willReturn($innerResponse);

        $entryPoint = new OAuthAuthorizeEntryPoint($inner);
        $response = $entryPoint->start(Request::create('/admin'));

        $this->assertSame($innerResponse, $response);
    }

    public function testDelegatesToInnerEntryPointForPathMerelyContainingAuthorizeFragment(): void
    {
        $innerResponse = new Response('inner');
        $inner = $this->createMock(AuthenticationEntryPointInterface::class);
        $inner->expects(self::once())->method('start')->willReturn($innerResponse);

        $entryPoint = new OAuthAuthorizeEntryPoint($inner);
        $response = $entryPoint->start(Request::create('/evil/mcp/authorize'));

        $this->assertSame($innerResponse, $response);
    }

    public function testDelegatesToInnerEntryPointForAdjacentPathSharingPrefix(): void
    {
        $innerResponse = new Response('inner');
        $inner = $this->createMock(AuthenticationEntryPointInterface::class);
        $inner->expects(self::once())->method('start')->willReturn($innerResponse);

        $entryPoint = new OAuthAuthorizeEntryPoint($inner);
        $response = $entryPoint->start(Request::create('/admin/mcp/authorize-not-really'));

        $this->assertSame($innerResponse, $response);
    }
}
