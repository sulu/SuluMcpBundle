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
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Component\Localization\Localization;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceCollection;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Component\Webspace\Webspace;
use Sulu\Mcp\Application\Security\ToolPermissionChecker;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Tests\Unit\Fixture\TestUser;
use Sulu\Mcp\UserInterface\Mcp\Tool\PingTool;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

#[CoversClass(PingTool::class)]
final class PingToolTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<WebspaceManagerInterface> */
    private ObjectProphecy $webspaceManager;

    private TokenStorageInterface $tokenStorage;
    private PingTool $pingTool;

    protected function setUp(): void
    {
        $this->webspaceManager = $this->prophesize(WebspaceManagerInterface::class);
        $this->tokenStorage = new TokenStorage();

        // Real WebspacePermissionResolver granting EDIT everywhere, so existing tests see
        // every webspace unless they build their own PingTool with a narrower resolver.
        $this->pingTool = new PingTool($this->webspaceManager->reveal(), $this->tokenStorage, $this->grantAllResolver());
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

        $securityChecker = $this->prophesize(SecurityCheckerInterface::class);
        $securityChecker->hasPermission(Argument::cetera())->will(
            static fn (array $args): bool => 'sulu.webspaces.example' === $args[0]->getSecurityContext(),
        );
        $tokenStorage = (new TestUser())->inTokenStorage();

        $resolver = new WebspacePermissionResolver(
            $this->webspaceManager->reveal(),
            new ToolPermissionChecker($securityChecker->reveal(), $tokenStorage),
        );

        $pingTool = new PingTool($this->webspaceManager->reveal(), $this->tokenStorage, $resolver);

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
        $securityChecker = $this->prophesize(SecurityCheckerInterface::class);
        $securityChecker->hasPermission(Argument::cetera())->willReturn(true);
        $tokenStorage = (new TestUser())->inTokenStorage();

        return new WebspacePermissionResolver(
            $this->webspaceManager->reveal(),
            new ToolPermissionChecker($securityChecker->reveal(), $tokenStorage),
        );
    }

    private function setupTokenWithUser(string $username): void
    {
        $this->tokenStorage->setToken(new UsernamePasswordToken(new TestUser(1, 'en', $username), 'admin'));
    }

    /**
     * @param array<string, list<string>> $webspacesWithLocales
     */
    private function setupWebspaceCollection(array $webspacesWithLocales): void
    {
        $webspaces = [];
        foreach ($webspacesWithLocales as $key => $locales) {
            $localizations = \array_map(
                static fn (string $locale): Localization => new Localization($locale),
                $locales,
            );

            $ws = new Webspace();
            $ws->setKey($key);
            $ws->setName($key);
            $ws->setLocalizations($localizations);
            $webspaces[$key] = $ws;
        }

        // A real WebspaceCollection (not a mock): WebspacePermissionResolver
        // iterates the collection itself (IteratorAggregate), not just getWebspaces().
        $this->webspaceManager->getWebspaceCollection()->willReturn(new WebspaceCollection($webspaces));
    }
}
