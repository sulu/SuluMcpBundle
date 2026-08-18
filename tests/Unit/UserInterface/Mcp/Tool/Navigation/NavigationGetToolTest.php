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
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceCollection;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Component\Webspace\Webspace;
use Sulu\Mcp\Application\Security\ToolPermissionChecker;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Domain\Security\RequiresPermission;
use Sulu\Mcp\Tests\Unit\Fixture\TestUser;
use Sulu\Mcp\UserInterface\Mcp\Tool\Navigation\NavigationGetTool;
use Sulu\Page\Domain\Repository\NavigationRepositoryInterface;

#[CoversClass(NavigationGetTool::class)]
final class NavigationGetToolTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<NavigationRepositoryInterface> */
    private ObjectProphecy $navigationRepository;

    protected function setUp(): void
    {
        $this->navigationRepository = $this->prophesize(NavigationRepositoryInterface::class);
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

        /** @var ObjectProphecy<WebspaceManagerInterface> $webspaceManager */
        $webspaceManager = $this->prophesize(WebspaceManagerInterface::class);
        $webspaceManager->getWebspaceCollection()->willReturn(new WebspaceCollection($webspaces));

        /** @var ObjectProphecy<SecurityCheckerInterface> $securityChecker */
        $securityChecker = $this->prophesize(SecurityCheckerInterface::class);
        $securityChecker->hasPermission(Argument::cetera())->will(
            static function(array $args) use ($grantedWebspaceKeys): bool {
                [$condition, $permission] = $args;

                return \in_array(
                    \str_replace('sulu.webspaces.', '', $condition->getSecurityContext()),
                    $grantedWebspaceKeys,
                    true,
                );
            },
        );

        $tokenStorage = (new TestUser())->inTokenStorage();

        $checker = new ToolPermissionChecker($securityChecker->reveal(), $tokenStorage);

        return new NavigationGetTool(
            $this->navigationRepository->reveal(),
            new WebspacePermissionResolver($webspaceManager->reveal(), $checker),
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
        $this->navigationRepository
            ->getNavigationTree('main', 'en', 'website', null, 2, ['title' => 'title', 'url' => 'url', 'targetType' => 'object.linkData[provider]'])
            ->shouldBeCalledOnce()
            ->willReturn($tree);

        $result = $this->tool(['website'])->getNavigation('website', 'en', 'main', 2);

        $this->assertSame($tree, $result['navigation']);
        $this->assertSame('website', $result['webspace']);
        $this->assertSame('en', $result['locale']);
        $this->assertSame('main', $result['context']);
    }

    public function testGetNavigationDeniesUnpermittedWebspace(): void
    {
        $this->navigationRepository->getNavigationTree(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool(['intranet'])->getNavigation('website', 'en');

        $this->assertArrayHasKey('hint', $result);
        $this->assertSame([], $result['navigation']);
    }

    public function testGetNavigationReturnsErrorOnException(): void
    {
        $this->navigationRepository->getNavigationTree(Argument::cetera())
            ->willThrow(new \RuntimeException('Invalid webspace'));

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
