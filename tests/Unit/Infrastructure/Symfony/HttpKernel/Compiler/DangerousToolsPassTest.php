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

namespace Sulu\Mcp\Tests\Unit\Infrastructure\Symfony\HttpKernel\Compiler;

use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sulu\Mcp\Domain\Security\DangerousTool;
use Sulu\Mcp\Infrastructure\Symfony\HttpKernel\Compiler\DangerousToolsPass;
use Sulu\Mcp\UserInterface\Mcp\Tool\Block\BlockRemoveTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Content\ContentDeleteTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Content\ContentPublishTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Content\ContentUnpublishTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Media\MediaUploadTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Page\PageMoveTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Page\PageReorderTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Preview\PreviewLinkRevokeTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Taxonomy\CategoryDeleteTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Taxonomy\TagDeleteTool;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(DangerousToolsPass::class)]
final class DangerousToolsPassTest extends TestCase
{
    /**
     * @var list<class-string>
     */
    private const ALL_GATED_CLASSES = [
        ContentDeleteTool::class,
        TagDeleteTool::class,
        CategoryDeleteTool::class,
        ContentPublishTool::class,
        ContentUnpublishTool::class,
        PreviewLinkRevokeTool::class,
        PageMoveTool::class,
        PageReorderTool::class,
        BlockRemoveTool::class,
        MediaUploadTool::class,
    ];

    public function testProcessRemovesOnlyDeleteCategoryWhenDeleteDisabled(): void
    {
        $container = $this->containerWithGatedDefinitions();
        $container->setParameter('sulu_mcp.dangerous_tools', [
            'delete' => false,
            'publish' => true,
            'block_remove' => true,
            'media_upload' => true,
        ]);

        (new DangerousToolsPass())->process($container);

        $this->assertDefinitionsRemoved($container, [
            ContentDeleteTool::class,
            TagDeleteTool::class,
            CategoryDeleteTool::class,
        ]);
        $this->assertDefinitionsPresent($container, [
            ContentPublishTool::class,
            ContentUnpublishTool::class,
            PreviewLinkRevokeTool::class,
            PageMoveTool::class,
            PageReorderTool::class,
            BlockRemoveTool::class,
            MediaUploadTool::class,
        ]);
        self::assertSame([
            'sulu_content_delete',
            'sulu_tag_delete',
            'sulu_category_delete',
        ], $container->getParameter('sulu_mcp.disabled_tool_names'));
    }

    public function testProcessRemovesOnlyPublishCategoryWhenPublishDisabled(): void
    {
        $container = $this->containerWithGatedDefinitions();
        $container->setParameter('sulu_mcp.dangerous_tools', [
            'delete' => true,
            'publish' => false,
            'block_remove' => true,
            'media_upload' => true,
        ]);

        (new DangerousToolsPass())->process($container);

        $this->assertDefinitionsRemoved($container, [
            ContentPublishTool::class,
            ContentUnpublishTool::class,
            PreviewLinkRevokeTool::class,
            PageMoveTool::class,
            PageReorderTool::class,
        ]);
        $this->assertDefinitionsPresent($container, [
            ContentDeleteTool::class,
            TagDeleteTool::class,
            CategoryDeleteTool::class,
            BlockRemoveTool::class,
            MediaUploadTool::class,
        ]);
    }

    public function testProcessRemovesOnlyBlockRemoveCategoryWhenBlockRemoveDisabled(): void
    {
        $container = $this->containerWithGatedDefinitions();
        $container->setParameter('sulu_mcp.dangerous_tools', [
            'delete' => true,
            'publish' => true,
            'block_remove' => false,
            'media_upload' => true,
        ]);

        (new DangerousToolsPass())->process($container);

        $this->assertDefinitionsRemoved($container, [
            BlockRemoveTool::class,
        ]);
        $this->assertDefinitionsPresent($container, [
            ContentDeleteTool::class,
            TagDeleteTool::class,
            CategoryDeleteTool::class,
            ContentPublishTool::class,
            ContentUnpublishTool::class,
            PreviewLinkRevokeTool::class,
            PageMoveTool::class,
            PageReorderTool::class,
            MediaUploadTool::class,
        ]);
    }

    public function testProcessRemovesOnlyMediaUploadCategoryWhenMediaUploadDisabled(): void
    {
        $container = $this->containerWithGatedDefinitions();
        $container->setParameter('sulu_mcp.dangerous_tools', [
            'delete' => true,
            'publish' => true,
            'block_remove' => true,
            'media_upload' => false,
        ]);

        (new DangerousToolsPass())->process($container);

        $this->assertDefinitionsRemoved($container, [
            MediaUploadTool::class,
        ]);
        $this->assertDefinitionsPresent($container, [
            ContentDeleteTool::class,
            TagDeleteTool::class,
            CategoryDeleteTool::class,
            ContentPublishTool::class,
            ContentUnpublishTool::class,
            PreviewLinkRevokeTool::class,
            PageMoveTool::class,
            PageReorderTool::class,
            BlockRemoveTool::class,
        ]);
    }

    public function testProcessKeepsAllDefinitionsWhenAllFlagsTrue(): void
    {
        $container = $this->containerWithGatedDefinitions();
        $container->setParameter('sulu_mcp.dangerous_tools', [
            'delete' => true,
            'publish' => true,
            'block_remove' => true,
            'media_upload' => true,
        ]);

        (new DangerousToolsPass())->process($container);

        $this->assertDefinitionsPresent($container, self::ALL_GATED_CLASSES);
        self::assertSame([], $container->getParameter('sulu_mcp.disabled_tool_names'));
    }

    public function testProcessDisablesEveryCategoryWhenTheParameterIsAbsent(): void
    {
        $container = $this->containerWithGatedDefinitions();

        (new DangerousToolsPass())->process($container);

        foreach (self::ALL_GATED_CLASSES as $class) {
            self::assertFalse($container->hasDefinition($class), \sprintf('Expected "%s" to have been removed.', $class));
        }
    }

    public function testProcessGatesAThirdPartyToolByItsOwnCategory(): void
    {
        $container = $this->containerWithGatedDefinitions();
        $this->registerTool($container, ThirdPartyDangerousToolStub::class, ThirdPartyDangerousToolStub::class);
        $container->setParameter('sulu_mcp.dangerous_tools', [
            'delete' => true,
            'publish' => true,
            'block_remove' => true,
            'media_upload' => true,
            'third_party_category' => false,
        ]);

        (new DangerousToolsPass())->process($container);

        self::assertFalse($container->hasDefinition(ThirdPartyDangerousToolStub::class));
        self::assertSame(['third_party_tool'], $container->getParameter('sulu_mcp.disabled_tool_names'));
    }

    public function testProcessRejectsAConfiguredCategoryNoToolDeclares(): void
    {
        $container = $this->containerWithGatedDefinitions();
        $container->setParameter('sulu_mcp.dangerous_tools', ['does_not_exist' => false]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('does_not_exist');

        (new DangerousToolsPass())->process($container);
    }

    public function testProcessGatesAnInvokableToolDeclaredOnTheClass(): void
    {
        $container = new ContainerBuilder();
        $this->registerTool($container, InvokableDangerousToolStub::class, InvokableDangerousToolStub::class);
        $container->setParameter('sulu_mcp.dangerous_tools', ['invokable_category' => false]);

        (new DangerousToolsPass())->process($container);

        self::assertFalse($container->hasDefinition(InvokableDangerousToolStub::class));
        self::assertSame(['invokable_tool'], $container->getParameter('sulu_mcp.disabled_tool_names'));
    }

    public function testProcessGatesANamedMethodToolDeclaredOnTheClass(): void
    {
        $container = new ContainerBuilder();
        $this->registerTool($container, ClassGatedNamedMethodToolStub::class, ClassGatedNamedMethodToolStub::class);
        $container->setParameter('sulu_mcp.dangerous_tools', ['named_method_category' => false]);

        (new DangerousToolsPass())->process($container);

        self::assertFalse($container->hasDefinition(ClassGatedNamedMethodToolStub::class));
        self::assertSame(['named_method_tool'], $container->getParameter('sulu_mcp.disabled_tool_names'));
    }

    public function testProcessGatesAnInheritedToolByTheChildClassAttribute(): void
    {
        $container = new ContainerBuilder();
        $this->registerTool($container, ChildGatedInheritedToolStub::class, ChildGatedInheritedToolStub::class);
        $container->setParameter('sulu_mcp.dangerous_tools', ['inherited_category' => false]);

        (new DangerousToolsPass())->process($container);

        self::assertFalse($container->hasDefinition(ChildGatedInheritedToolStub::class));
        self::assertSame(['inherited_tool'], $container->getParameter('sulu_mcp.disabled_tool_names'));
    }

    public function testProcessResolvesAClassGivenAsAParameter(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('third_party.tool.class', ThirdPartyDangerousToolStub::class);
        $container->register('third_party.tool', '%third_party.tool.class%')
            ->addTag('mcp.tool', ['method' => 'call']);
        $container->setParameter('sulu_mcp.dangerous_tools', ['third_party_category' => false]);

        (new DangerousToolsPass())->process($container);

        self::assertFalse($container->hasDefinition('third_party.tool'));
        self::assertSame(['third_party_tool'], $container->getParameter('sulu_mcp.disabled_tool_names'));
    }

    public function testProcessThrowsWhenAToolClassDoesNotExist(): void
    {
        $container = new ContainerBuilder();
        $container->register('missing.tool', 'Not\\A\\Real\\Class')->addTag('mcp.tool', ['method' => 'call']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('missing.tool');

        (new DangerousToolsPass())->process($container);
    }

    public function testProcessCollectsEveryGatedMethodOfOneClass(): void
    {
        $container = new ContainerBuilder();
        $this->registerTool($container, MixedToolsStub::class, MixedToolsStub::class);
        $container->setParameter('sulu_mcp.dangerous_tools', ['first' => false, 'second' => false]);

        (new DangerousToolsPass())->process($container);

        self::assertSame(['gated_first', 'gated_second'], $container->getParameter('sulu_mcp.disabled_tool_names'));
    }

    public function testProcessKeepsTheUngatedToolsOfAClassWithAGatedMethod(): void
    {
        $container = new ContainerBuilder();
        $this->registerTool($container, MixedToolsStub::class, MixedToolsStub::class);
        $container->setParameter('sulu_mcp.dangerous_tools', ['first' => false, 'second' => true]);

        (new DangerousToolsPass())->process($container);

        self::assertTrue($container->hasDefinition(MixedToolsStub::class));
        $methods = \array_column($container->getDefinition(MixedToolsStub::class)->getTag('mcp.tool'), 'method');
        self::assertEqualsCanonicalizing(['open', 'gatedSecond'], $methods);
        self::assertSame(['gated_first'], $container->getParameter('sulu_mcp.disabled_tool_names'));
    }

    private function containerWithGatedDefinitions(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        foreach (self::ALL_GATED_CLASSES as $class) {
            $this->registerTool($container, $class, $class);
        }

        return $container;
    }

    /**
     * Tags the service the way attribute autoconfiguration does: one `mcp.tool` tag per method.
     */
    private function registerTool(ContainerBuilder $container, string $id, string $class): void
    {
        $definition = $container->register($id, $class);
        foreach ((new \ReflectionClass($class))->getMethods() as $method) {
            if ([] !== $method->getAttributes(McpTool::class)) {
                $definition->addTag('mcp.tool', ['method' => $method->getName()]);
            }
        }

        if ([] !== (new \ReflectionClass($class))->getAttributes(McpTool::class)) {
            $definition->addTag('mcp.tool', ['method' => '__invoke']);
        }
    }

    /**
     * @param list<class-string> $classes
     */
    private function assertDefinitionsRemoved(ContainerBuilder $container, array $classes): void
    {
        foreach ($classes as $class) {
            self::assertFalse($container->hasDefinition($class), \sprintf('Expected "%s" to have been removed.', $class));
        }
    }

    /**
     * @param list<class-string> $classes
     */
    private function assertDefinitionsPresent(ContainerBuilder $container, array $classes): void
    {
        foreach ($classes as $class) {
            self::assertTrue($container->hasDefinition($class), \sprintf('Expected "%s" to still be defined.', $class));
        }
    }
}

/**
 * Stand-in for a tool declared by another bundle entirely, gated by a category this
 * package knows nothing about.
 */
final class ThirdPartyDangerousToolStub
{
    #[McpTool(name: 'third_party_tool', description: 'test double')]
    #[DangerousTool('third_party_category')]
    public function call(): array
    {
        return [];
    }
}

#[McpTool(name: 'invokable_tool', description: 'test double')]
#[DangerousTool('invokable_category')]
final class InvokableDangerousToolStub
{
    public function __invoke(): array
    {
        return [];
    }
}

/**
 * A class-level #[DangerousTool] on a class whose tool is a named method, not __invoke().
 */
#[DangerousTool('named_method_category')]
final class ClassGatedNamedMethodToolStub
{
    #[McpTool(name: 'named_method_tool', description: 'test double')]
    public function doSomething(): array
    {
        return [];
    }
}

abstract class InheritedToolParentStub
{
    #[McpTool(name: 'inherited_tool', description: 'test double')]
    public function doSomething(): array
    {
        return [];
    }
}

#[DangerousTool('inherited_category')]
final class ChildGatedInheritedToolStub extends InheritedToolParentStub
{
}

final class MixedToolsStub
{
    #[McpTool(name: 'open_tool', description: 'test double')]
    public function open(): array
    {
        return [];
    }

    #[McpTool(name: 'gated_first', description: 'test double')]
    #[DangerousTool('first')]
    public function gatedFirst(): array
    {
        return [];
    }

    #[McpTool(name: 'gated_second', description: 'test double')]
    #[DangerousTool('second')]
    public function gatedSecond(): array
    {
        return [];
    }
}
