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

use Mcp\Capability\Registry\ToolReference;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Page;
use Mcp\Schema\Tool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Mcp\Application\Security\ToolPermissionChecker;
use Sulu\Mcp\Application\Security\ToolVisibilityResolver;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Infrastructure\Mcp\FilteredRegistry;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\Tests\Application\TestBundle\Metadata\TestGroupProvider;
use Sulu\Mcp\Tests\Unit\Fixture\FakeToolPermissionChecker;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

#[CoversClass(FilteredRegistry::class)]
final class FilteredRegistryTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<RegistryInterface> */
    private ObjectProphecy $inner;

    protected function setUp(): void
    {
        $this->inner = $this->prophesize(RegistryInterface::class);
    }

    private function tool(string $name): Tool
    {
        return new Tool(
            name: $name,
            title: null,
            inputSchema: ['type' => 'object', 'properties' => [], 'required' => null],
            description: null,
            annotations: null,
        );
    }

    /**
     * @param array<string, array{name: string, requirements: list<array{context: string, permission: string}>, contextArgument: ?string, contextResolver: ?string, objectResolved: bool, discoveryContexts: list<string>}> $map
     */
    private function visibilityResolver(array $map, FakeToolPermissionChecker $checker): ToolVisibilityResolver
    {
        // Never invoked: every requirement in these tests is a literal context, so
        // ToolVisibilityResolver never asks the webspace resolver, which in turn
        // never reaches the security checker (a real, tokenless TokenStorage makes
        // ToolPermissionChecker::has() fail closed before it gets that far).
        $webspaceManager = $this->prophesize(WebspaceManagerInterface::class)->reveal();
        $securityChecker = $this->prophesize(SecurityCheckerInterface::class)->reveal();
        $innerChecker = new ToolPermissionChecker($securityChecker, new TokenStorage());

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
        $this->inner->getTools(null, null)->willReturn(new Page([
            'sulu_ping' => $this->tool('sulu_ping'),
            'sulu_tag_create' => $this->tool('sulu_tag_create'),
            'sulu_tag_list' => $this->tool('sulu_tag_list'),
        ], null));

        $checker = FakeToolPermissionChecker::grantingAll();
        $checker->grantingNoneExcept()->grant('sulu.settings.tags', PermissionTypes::VIEW);

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

        $registry = new FilteredRegistry($this->inner->reveal(), $this->visibilityResolver($map, $checker));

        $names = \array_keys((array) $registry->getTools(null, null)->getArrayCopy());

        self::assertContains('sulu_ping', $names);
        self::assertContains('sulu_tag_list', $names);
        self::assertNotContains('sulu_tag_create', $names);
    }

    public function testGetToolsPaginatesFilteredResults(): void
    {
        $this->inner->getTools(null, null)->willReturn(new Page([
            'sulu_ping' => $this->tool('sulu_ping'),
            'sulu_get_context' => $this->tool('sulu_get_context'),
            'sulu_tag_create' => $this->tool('sulu_tag_create'),
        ], null));

        // No permission map entry for sulu_tag_create => hidden; only the two
        // allowlisted tools survive filtering.
        $checker = FakeToolPermissionChecker::grantingAll();
        $registry = new FilteredRegistry($this->inner->reveal(), $this->visibilityResolver([], $checker));

        $firstPage = $registry->getTools(1, null);
        self::assertCount(1, $firstPage->references);
        self::assertNotNull($firstPage->nextCursor);

        $secondPage = $registry->getTools(1, $firstPage->nextCursor);
        self::assertCount(1, $secondPage->references);
        self::assertNull($secondPage->nextCursor);

        $collected = [...\array_values($firstPage->references), ...\array_values($secondPage->references)];
        $collectedNames = \array_map(static fn (Tool $tool): string => $tool->name, $collected);
        \sort($collectedNames);
        self::assertSame(['sulu_get_context', 'sulu_ping'], $collectedNames);
    }

    public function testGetToolIsNotFilteredByVisibility(): void
    {
        $toolReference = new ToolReference($this->tool('sulu_tag_create'), static fn () => null);
        $this->inner->getTool('sulu_tag_create')->willReturn($toolReference);

        $checker = FakeToolPermissionChecker::grantingAll();
        $registry = new FilteredRegistry($this->inner->reveal(), $this->visibilityResolver([], $checker));

        self::assertSame($toolReference, $registry->getTool('sulu_tag_create'));
    }

    public function testGetToolIsNotFilteredByDisabledToolNames(): void
    {
        $toolReference = new ToolReference($this->tool('sulu_dangerous'), static fn () => null);
        $this->inner->getTool('sulu_dangerous')->willReturn($toolReference);

        $checker = FakeToolPermissionChecker::grantingAll();
        $registry = new FilteredRegistry($this->inner->reveal(), $this->visibilityResolver([], $checker), ['sulu_dangerous']);

        self::assertSame($toolReference, $registry->getTool('sulu_dangerous'));
    }

    public function testRegisterToolSkipsDisabledToolNames(): void
    {
        $this->inner->registerTool(Argument::cetera())->shouldNotBeCalled();

        $checker = FakeToolPermissionChecker::grantingAll();
        $registry = new FilteredRegistry($this->inner->reveal(), $this->visibilityResolver([], $checker), ['sulu_dangerous']);

        $registry->registerTool($this->tool('sulu_dangerous'), static fn () => null);
    }

    public function testRegisterToolForwardsNonDisabledTool(): void
    {
        $tool = $this->tool('sulu_safe');
        $reference = new ToolReference($tool, static fn () => null);
        $this->inner->registerTool($tool, Argument::cetera())->willReturn($reference)->shouldBeCalledOnce();

        $checker = FakeToolPermissionChecker::grantingAll();
        $registry = new FilteredRegistry($this->inner->reveal(), $this->visibilityResolver([], $checker), ['sulu_dangerous']);

        self::assertSame($reference, $registry->registerTool($tool, static fn () => null));
    }

    public function testRegisterToolReturnsADetachedReferenceForDisabledToolNames(): void
    {
        $this->inner->registerTool(Argument::cetera())->shouldNotBeCalled();

        $tool = $this->tool('sulu_dangerous');
        $checker = FakeToolPermissionChecker::grantingAll();
        $registry = new FilteredRegistry($this->inner->reveal(), $this->visibilityResolver([], $checker), ['sulu_dangerous']);

        self::assertSame($tool, $registry->registerTool($tool, static fn () => null)->tool);
    }
}
