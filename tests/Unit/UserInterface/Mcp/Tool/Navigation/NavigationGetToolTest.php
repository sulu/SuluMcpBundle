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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\Navigation;

use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceCollection;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Component\Webspace\Webspace;
use Sulu\Mcp\Application\Security\ToolPermissionChecker;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Domain\Security\RequiresPermission;
use Sulu\Mcp\UserInterface\Mcp\Tool\Navigation\NavigationGetTool;
use Sulu\Page\Domain\Repository\NavigationRepositoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

#[CoversClass(NavigationGetTool::class)]
final class NavigationGetToolTest extends TestCase
{
    private NavigationRepositoryInterface&MockObject $navigationRepository;

    protected function setUp(): void
    {
        $this->navigationRepository = $this->createMock(NavigationRepositoryInterface::class);
    }

    /**
     * @param list<string> $grantedWebspaceKeys
     */
    private function tool(array $grantedWebspaceKeys): NavigationGetTool
    {
        $webspaces = [];
        foreach (['website', 'intranet'] as $key) {
            $webspace = new Webspace();
            $webspace->setKey($key);
            $webspaces[$key] = $webspace;
        }

        $webspaceManager = $this->createMock(WebspaceManagerInterface::class);
        $webspaceManager->method('getWebspaceCollection')->willReturn(new WebspaceCollection($webspaces));

        $securityChecker = $this->createMock(SecurityCheckerInterface::class);
        $securityChecker->method('hasPermission')->willReturnCallback(
            static fn ($condition, string $permission): bool => \in_array(
                \str_replace('sulu.webspaces.', '', $condition->getSecurityContext()),
                $grantedWebspaceKeys,
                true,
            ),
        );

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($this->createMock(UserInterface::class));
        $tokenStorage->method('getToken')->willReturn($token);

        $checker = new ToolPermissionChecker($securityChecker, $tokenStorage);

        return new NavigationGetTool(
            $this->navigationRepository,
            new WebspacePermissionResolver($webspaceManager, $checker),
        );
    }

    public function testGetNavigationRequestsRealPropertyPathsAndReturnsTree(): void
    {
        $tree = [
            ['title' => 'Home', 'url' => '/', 'targetType' => 'page', 'children' => []],
            ['title' => 'About', 'url' => '/about', 'targetType' => 'page', 'children' => []],
        ];

        // The property map values MUST name real content property paths (core's
        // NavigationTwigExtension default map). Empty values make Sulu's content
        // resolver return no "nav" group at all and NavigationRepository crashes
        // with 'Undefined array key "nav"'.
        $this->navigationRepository->expects($this->once())
            ->method('getNavigationTree')
            ->with('main', 'en', 'website', null, 2, ['title' => 'title', 'url' => 'url', 'targetType' => 'object.linkData[provider]'])
            ->willReturn($tree);

        $result = $this->tool(['website'])->getNavigation('website', 'en', 'main', 2);

        $this->assertSame($tree, $result['navigation']);
        $this->assertSame('website', $result['webspace']);
        $this->assertSame('en', $result['locale']);
        $this->assertSame('main', $result['context']);
    }

    public function testGetNavigationDeniesUnpermittedWebspace(): void
    {
        $this->navigationRepository->expects($this->never())->method('getNavigationTree');

        $result = $this->tool(['intranet'])->getNavigation('website', 'en');

        $this->assertArrayHasKey('hint', $result);
        $this->assertSame([], $result['navigation']);
    }

    public function testGetNavigationReturnsErrorOnException(): void
    {
        $this->navigationRepository->method('getNavigationTree')
            ->willThrowException(new \RuntimeException('Invalid webspace'));

        $result = $this->tool(['website'])->getNavigation('website', 'en');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('website', $result['error']);
    }

    public function testMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(NavigationGetTool::class, 'getNavigation');
        $attributes = $reflection->getAttributes(McpTool::class);
        $this->assertCount(1, $attributes);
        $this->assertSame('sulu_navigation_get', $attributes[0]->newInstance()->name);
    }

    public function testMethodDeclaresWebspaceViewPermission(): void
    {
        $reflection = new \ReflectionMethod(NavigationGetTool::class, 'getNavigation');
        $attributes = $reflection->getAttributes(RequiresPermission::class);
        $this->assertCount(1, $attributes);

        $permission = $attributes[0]->newInstance();
        $this->assertTrue($permission->objectResolved);
        $this->assertSame([WebspacePermissionResolver::ANY_WEBSPACE_CONTEXT], $permission->discoveryContexts);
        $this->assertSame('sulu.webspaces.#context#', $permission->requirements[0]->contextTemplate);
        $this->assertSame(PermissionTypes::VIEW, $permission->requirements[0]->permissionType);
    }
}
