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

namespace Sulu\Mcp\Tests\Unit\Infrastructure\Mcp;

use Mcp\Capability\Discovery\DiscoveryState;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Page;
use Mcp\Schema\Tool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Mcp\Application\Security\ToolPermissionChecker;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Application\Security\ToolVisibilityResolver;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Infrastructure\Mcp\FilteredRegistry;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\Tests\Application\TestBundle\Metadata\TestGroupProvider;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

#[CoversClass(FilteredRegistry::class)]
final class FilteredRegistryTest extends TestCase
{
    private RegistryInterface&MockObject $inner;

    protected function setUp(): void
    {
        $this->inner = $this->createMock(RegistryInterface::class);
    }

    private function tool(string $name): Tool
    {
        return new Tool($name, ['type' => 'object', 'properties' => [], 'required' => null], null, null);
    }

    /**
     * @param array<string, array{name: string, requirements: list<array{context: string, permission: string}>, contextArgument: ?string, contextResolver: ?string, objectResolved: bool, discoveryContexts: list<string>}> $map
     */
    private function visibilityResolver(array $map, ToolPermissionCheckerInterface&MockObject $checker): ToolVisibilityResolver
    {
        $webspaceManager = $this->createMock(WebspaceManagerInterface::class);
        $innerChecker = new ToolPermissionChecker(
            $this->createMock(SecurityCheckerInterface::class),
            $this->createMock(TokenStorageInterface::class),
        );

        return new ToolVisibilityResolver(
            $map,
            $checker,
            new WebspacePermissionResolver($webspaceManager, $innerChecker),
            new ArticleSecurityContextResolver(TestGroupProvider::singleGroup()),
            [],
            ['sulu_ping', 'sulu_get_context'],
        );
    }

    public function testGetToolsExcludesHiddenAndIncludesPermittedAndAllowlisted(): void
    {
        $this->inner->method('getTools')->with(null, null)->willReturn(new Page([
            'sulu_ping' => $this->tool('sulu_ping'),
            'sulu_tag_create' => $this->tool('sulu_tag_create'),
            'sulu_tag_list' => $this->tool('sulu_tag_list'),
        ], null));

        $checker = $this->createMock(ToolPermissionCheckerInterface::class);
        $checker->method('has')->willReturnCallback(
            static fn (string $context, string $permission): bool => 'sulu.settings.tags' === $context && PermissionTypes::VIEW === $permission,
        );

        $map = [
            'sulu_tag_create' => [
                'name' => 'sulu_tag_create',
                'requirements' => [['context' => 'sulu.settings.tags', 'permission' => PermissionTypes::ADD]],
                'contextArgument' => null, 'contextResolver' => null,
                'objectResolved' => false, 'discoveryContexts' => [],
            ],
            'sulu_tag_list' => [
                'name' => 'sulu_tag_list',
                'requirements' => [['context' => 'sulu.settings.tags', 'permission' => PermissionTypes::VIEW]],
                'contextArgument' => null, 'contextResolver' => null,
                'objectResolved' => false, 'discoveryContexts' => [],
            ],
        ];

        $registry = new FilteredRegistry($this->inner, $this->visibilityResolver($map, $checker));

        $names = array_keys((array) $registry->getTools(null, null)->getArrayCopy());

        self::assertContains('sulu_ping', $names);
        self::assertContains('sulu_tag_list', $names);
        self::assertNotContains('sulu_tag_create', $names);
    }

    public function testGetToolsPaginatesFilteredResults(): void
    {
        $this->inner->method('getTools')->with(null, null)->willReturn(new Page([
            'sulu_ping' => $this->tool('sulu_ping'),
            'sulu_get_context' => $this->tool('sulu_get_context'),
            'sulu_tag_create' => $this->tool('sulu_tag_create'),
        ], null));

        // No permission map entry for sulu_tag_create => hidden; only the two
        // allowlisted tools survive filtering.
        $checker = $this->createMock(ToolPermissionCheckerInterface::class);
        $registry = new FilteredRegistry($this->inner, $this->visibilityResolver([], $checker));

        $firstPage = $registry->getTools(1, null);
        self::assertCount(1, $firstPage->references);
        self::assertNotNull($firstPage->nextCursor);

        $secondPage = $registry->getTools(1, $firstPage->nextCursor);
        self::assertCount(1, $secondPage->references);
        self::assertNull($secondPage->nextCursor);

        $collected = [...array_values($firstPage->references), ...array_values($secondPage->references)];
        $collectedNames = array_map(static fn (Tool $tool): string => $tool->name, $collected);
        sort($collectedNames);
        self::assertSame(['sulu_get_context', 'sulu_ping'], $collectedNames);
    }

    public function testGetToolIsNotFilteredByVisibility(): void
    {
        $toolReference = new ToolReference($this->tool('sulu_tag_create'), static fn () => null);
        $this->inner->method('getTool')->with('sulu_tag_create')->willReturn($toolReference);

        $checker = $this->createMock(ToolPermissionCheckerInterface::class);
        $registry = new FilteredRegistry($this->inner, $this->visibilityResolver([], $checker));

        self::assertSame($toolReference, $registry->getTool('sulu_tag_create'));
    }

    public function testGetToolIsNotFilteredByDisabledToolNames(): void
    {
        $toolReference = new ToolReference($this->tool('sulu_dangerous'), static fn () => null);
        $this->inner->method('getTool')->with('sulu_dangerous')->willReturn($toolReference);

        $checker = $this->createMock(ToolPermissionCheckerInterface::class);
        $registry = new FilteredRegistry($this->inner, $this->visibilityResolver([], $checker), ['sulu_dangerous']);

        self::assertSame($toolReference, $registry->getTool('sulu_dangerous'));
    }

    public function testRegisterToolSkipsDisabledToolNames(): void
    {
        $this->inner->expects(self::never())->method('registerTool');

        $checker = $this->createMock(ToolPermissionCheckerInterface::class);
        $registry = new FilteredRegistry($this->inner, $this->visibilityResolver([], $checker), ['sulu_dangerous']);

        $registry->registerTool($this->tool('sulu_dangerous'), static fn () => null);
    }

    public function testRegisterToolForwardsNonDisabledTool(): void
    {
        $tool = $this->tool('sulu_safe');
        $this->inner->expects(self::once())->method('registerTool')->with($tool);

        $checker = $this->createMock(ToolPermissionCheckerInterface::class);
        $registry = new FilteredRegistry($this->inner, $this->visibilityResolver([], $checker), ['sulu_dangerous']);

        $registry->registerTool($tool, static fn () => null);
    }

    public function testSetDiscoveryStateStripsDisabledToolNames(): void
    {
        $dangerousRef = new ToolReference($this->tool('sulu_dangerous'), static fn () => null);
        $safeRef = new ToolReference($this->tool('sulu_safe'), static fn () => null);

        $state = new DiscoveryState(tools: ['sulu_dangerous' => $dangerousRef, 'sulu_safe' => $safeRef]);

        $this->inner->expects(self::once())->method('setDiscoveryState')->with(
            self::callback(static function (DiscoveryState $passed) {
                $tools = $passed->getTools();

                return !isset($tools['sulu_dangerous']) && isset($tools['sulu_safe']);
            }),
        );

        $checker = $this->createMock(ToolPermissionCheckerInterface::class);
        $registry = new FilteredRegistry($this->inner, $this->visibilityResolver([], $checker), ['sulu_dangerous']);

        $registry->setDiscoveryState($state);
    }
}
