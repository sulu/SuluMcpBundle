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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sulu\Component\Localization\Localization;
use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceCollection;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Component\Webspace\Webspace;
use Sulu\Mcp\Application\Security\ToolPermissionChecker;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\UserInterface\Mcp\Tool\PingTool;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

#[CoversClass(PingTool::class)]
final class PingToolTest extends TestCase
{
    private WebspaceManagerInterface&MockObject $webspaceManager;
    private TokenStorageInterface&MockObject $tokenStorage;
    private PingTool $pingTool;

    protected function setUp(): void
    {
        $this->webspaceManager = $this->createMock(WebspaceManagerInterface::class);
        $this->tokenStorage = $this->createMock(TokenStorageInterface::class);

        // Real WebspacePermissionResolver granting EDIT everywhere, so existing tests see
        // every webspace unless they build their own PingTool with a narrower resolver.
        $this->pingTool = new PingTool($this->webspaceManager, $this->tokenStorage, $this->grantAllResolver());
    }

    public function testPingReturnsStatusOkWithWebspaceList(): void
    {
        $this->setupTokenWithUser('admin');
        $this->setupWebspaceCollection(['example' => ['en', 'de']]);

        $result = $this->pingTool->ping();

        $this->assertSame('ok', $result['status']);
        $this->assertSame('sulu-mcp-server', $result['server']);
        $this->assertSame('admin', $result['user']);
        $this->assertCount(1, $result['webspaces']);
        $this->assertSame('example', $result['webspaces'][0]['key']);
        $this->assertSame(['en', 'de'], $result['webspaces'][0]['locales']);
    }

    public function testPingReturnsNullUserWhenUnauthenticated(): void
    {
        $this->tokenStorage->method('getToken')->willReturn(null);
        $this->setupWebspaceCollection([]);

        $result = $this->pingTool->ping();

        $this->assertNull($result['user']);
    }

    public function testPingMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(PingTool::class, 'ping');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes, 'ping() method must have exactly one #[McpTool] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_ping', $instance->name);
    }

    public function testPingFiltersWebspacesToPermittedOnly(): void
    {
        $this->setupTokenWithUser('admin');
        $this->setupWebspaceCollection(['example' => ['en'], 'blog' => ['en']]);

        $securityChecker = $this->createMock(SecurityCheckerInterface::class);
        $securityChecker->method('hasPermission')->willReturnCallback(
            static fn ($condition, string $permission): bool => 'sulu.webspaces.example' === $condition->getSecurityContext(),
        );
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($this->createMock(UserInterface::class));
        $tokenStorage->method('getToken')->willReturn($token);

        $resolver = new WebspacePermissionResolver(
            $this->webspaceManager,
            new ToolPermissionChecker($securityChecker, $tokenStorage),
        );

        $pingTool = new PingTool($this->webspaceManager, $this->tokenStorage, $resolver);

        $result = $pingTool->ping();

        $this->assertCount(1, $result['webspaces']);
        $this->assertSame('example', $result['webspaces'][0]['key']);
    }

    /**
     * A WebspacePermissionResolver whose ToolPermissionChecker grants every
     * context, keeping unrelated tests seeing the full webspace list.
     */
    private function grantAllResolver(): WebspacePermissionResolver
    {
        $securityChecker = $this->createMock(SecurityCheckerInterface::class);
        $securityChecker->method('hasPermission')->willReturn(true);
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($this->createMock(UserInterface::class));
        $tokenStorage->method('getToken')->willReturn($token);

        return new WebspacePermissionResolver(
            $this->webspaceManager,
            new ToolPermissionChecker($securityChecker, $tokenStorage),
        );
    }

    private function setupTokenWithUser(string $username): void
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUserIdentifier')->willReturn($username);
        $this->tokenStorage->method('getToken')->willReturn($token);
    }

    /**
     * @param array<string, list<string>> $webspacesWithLocales
     */
    private function setupWebspaceCollection(array $webspacesWithLocales): void
    {
        $webspaces = [];
        foreach ($webspacesWithLocales as $key => $locales) {
            $localizations = \array_map(function(string $locale) {
                $localization = $this->createMock(Localization::class);
                $localization->method('getLocale')->willReturn($locale);

                return $localization;
            }, $locales);

            $ws = $this->createMock(Webspace::class);
            $ws->method('getKey')->willReturn($key);
            $ws->method('getName')->willReturn($key);
            $ws->method('getAllLocalizations')->willReturn($localizations);
            $webspaces[$key] = $ws;
        }

        // A real WebspaceCollection (not a mock): WebspacePermissionResolver
        // iterates the collection itself (IteratorAggregate), not just getWebspaces().
        $this->webspaceManager->method('getWebspaceCollection')->willReturn(new WebspaceCollection($webspaces));
    }
}
