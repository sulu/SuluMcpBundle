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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
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
use Symfony\Component\DependencyInjection\Definition;

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
        $container->setParameter('sulu_mcp.dangerous_tools.delete', false);
        $container->setParameter('sulu_mcp.dangerous_tools.publish', true);
        $container->setParameter('sulu_mcp.dangerous_tools.block_remove', true);
        $container->setParameter('sulu_mcp.dangerous_tools.media_upload', true);

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
    }

    public function testProcessRemovesOnlyPublishCategoryWhenPublishDisabled(): void
    {
        $container = $this->containerWithGatedDefinitions();
        $container->setParameter('sulu_mcp.dangerous_tools.delete', true);
        $container->setParameter('sulu_mcp.dangerous_tools.publish', false);
        $container->setParameter('sulu_mcp.dangerous_tools.block_remove', true);
        $container->setParameter('sulu_mcp.dangerous_tools.media_upload', true);

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
        $container->setParameter('sulu_mcp.dangerous_tools.delete', true);
        $container->setParameter('sulu_mcp.dangerous_tools.publish', true);
        $container->setParameter('sulu_mcp.dangerous_tools.block_remove', false);
        $container->setParameter('sulu_mcp.dangerous_tools.media_upload', true);

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
        $container->setParameter('sulu_mcp.dangerous_tools.delete', true);
        $container->setParameter('sulu_mcp.dangerous_tools.publish', true);
        $container->setParameter('sulu_mcp.dangerous_tools.block_remove', true);
        $container->setParameter('sulu_mcp.dangerous_tools.media_upload', false);

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
        $container->setParameter('sulu_mcp.dangerous_tools.delete', true);
        $container->setParameter('sulu_mcp.dangerous_tools.publish', true);
        $container->setParameter('sulu_mcp.dangerous_tools.block_remove', true);
        $container->setParameter('sulu_mcp.dangerous_tools.media_upload', true);

        (new DangerousToolsPass())->process($container);

        $this->assertDefinitionsPresent($container, self::ALL_GATED_CLASSES);
    }

    public function testProcessIsNoOpWhenParametersAreAbsent(): void
    {
        $container = $this->containerWithGatedDefinitions();

        (new DangerousToolsPass())->process($container);

        $this->assertDefinitionsPresent($container, self::ALL_GATED_CLASSES);
    }

    public function testResolveDisabledToolNamesReturnsAllToolsWhenAllFalse(): void
    {
        $names = DangerousToolsPass::resolveDisabledToolNames([
            'delete' => false,
            'publish' => false,
            'block_remove' => false,
            'media_upload' => false,
        ]);

        self::assertSame([
            'sulu_content_delete',
            'sulu_tag_delete',
            'sulu_category_delete',
            'sulu_content_publish',
            'sulu_content_unpublish',
            'sulu_preview_link_revoke',
            'sulu_page_move',
            'sulu_page_reorder',
            'sulu_block_remove',
            'sulu_media_upload',
        ], $names);
    }

    public function testResolveDisabledToolNamesReturnsEmptyListWhenAllTrue(): void
    {
        $names = DangerousToolsPass::resolveDisabledToolNames([
            'delete' => true,
            'publish' => true,
            'block_remove' => true,
            'media_upload' => true,
        ]);

        self::assertSame([], $names);
    }

    public function testResolveDisabledToolNamesReturnsOnlyDisabledCategoriesForMixedConfig(): void
    {
        $names = DangerousToolsPass::resolveDisabledToolNames([
            'delete' => true,
            'publish' => false,
            'block_remove' => true,
            'media_upload' => true,
        ]);

        self::assertSame([
            'sulu_content_publish',
            'sulu_content_unpublish',
            'sulu_preview_link_revoke',
            'sulu_page_move',
            'sulu_page_reorder',
        ], $names);
    }

    public function testResolveDisabledToolNamesDefaultsMissingKeysToDisabled(): void
    {
        $names = DangerousToolsPass::resolveDisabledToolNames([]);

        self::assertSame([
            'sulu_content_delete',
            'sulu_tag_delete',
            'sulu_category_delete',
            'sulu_content_publish',
            'sulu_content_unpublish',
            'sulu_preview_link_revoke',
            'sulu_page_move',
            'sulu_page_reorder',
            'sulu_block_remove',
            'sulu_media_upload',
        ], $names);
    }

    private function containerWithGatedDefinitions(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        foreach (self::ALL_GATED_CLASSES as $class) {
            $container->setDefinition($class, new Definition($class));
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
