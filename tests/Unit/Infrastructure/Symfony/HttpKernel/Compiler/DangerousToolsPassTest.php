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
        $container->register(ThirdPartyDangerousToolStub::class, ThirdPartyDangerousToolStub::class)
            ->addTag('mcp.tool');
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

    private function containerWithGatedDefinitions(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        foreach (self::ALL_GATED_CLASSES as $class) {
            $container->register($class, $class)->addTag('mcp.tool');
        }

        return $container;
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
