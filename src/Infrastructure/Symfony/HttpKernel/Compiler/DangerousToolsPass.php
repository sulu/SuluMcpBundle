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

namespace Sulu\Mcp\Infrastructure\Symfony\HttpKernel\Compiler;

use Sulu\Mcp\UserInterface\Mcp\Tool\Block\BlockRemoveTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Content\ContentDeleteTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Content\ContentPublishTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Content\ContentUnpublishTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Preview\PreviewLinkRevokeTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Taxonomy\CategoryDeleteTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Taxonomy\TagDeleteTool;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Removes tool service definitions for dangerous categories that are not enabled
 * in bundle configuration. Must run before symfony/mcp-bundle's McpPass so the
 * removed services are absent from the `mcp.tool` tagged-service iterator.
 *
 * Mcp also performs runtime attribute discovery that registers every
 * `#[McpTool]`-tagged class regardless of DI -- removing the service alone is
 * not enough. The pass therefore also publishes the disabled tool NAMES as a
 * container parameter, consumed by `FilteredRegistry` to drop the same tools
 * from the discovery state at runtime.
 *
 * @internal
 */
final class DangerousToolsPass implements CompilerPassInterface
{
    /**
     * Map of dangerous-tools category -> [class-string => mcp tool name].
     * The tool name matches each class's `#[McpTool(name: ...)]` attribute.
     *
     * @var array<string, array<class-string, string>>
     */
    private const TOOLS_BY_CATEGORY = [
        'delete' => [
            ContentDeleteTool::class => 'sulu_content_delete',
            TagDeleteTool::class => 'sulu_tag_delete',
            CategoryDeleteTool::class => 'sulu_category_delete',
        ],
        'publish' => [
            ContentPublishTool::class => 'sulu_content_publish',
            ContentUnpublishTool::class => 'sulu_content_unpublish',
            PreviewLinkRevokeTool::class => 'sulu_preview_link_revoke',
        ],
        'block_remove' => [
            BlockRemoveTool::class => 'sulu_block_remove',
        ],
    ];

    public function process(ContainerBuilder $container): void
    {
        foreach (self::TOOLS_BY_CATEGORY as $category => $tools) {
            $parameter = \sprintf('sulu_mcp.dangerous_tools.%s', $category);
            if (!$container->hasParameter($parameter) || true === $container->getParameter($parameter)) {
                continue;
            }

            foreach (\array_keys($tools) as $class) {
                if ($container->hasDefinition($class)) {
                    $container->removeDefinition($class);
                }
            }
        }
    }

    /**
     * Resolve the list of MCP tool names that must be hidden given the bundle's
     * `dangerous_tools` configuration. Called from the bundle extension to
     * populate the `sulu_mcp.disabled_tool_names` parameter used by
     * `FilteredRegistry`.
     *
     * @param array<string, bool> $dangerousToolsConfig
     *
     * @return list<string>
     */
    public static function resolveDisabledToolNames(array $dangerousToolsConfig): array
    {
        $names = [];
        foreach (self::TOOLS_BY_CATEGORY as $category => $tools) {
            if (true === ($dangerousToolsConfig[$category] ?? false)) {
                continue;
            }

            foreach ($tools as $toolName) {
                $names[] = $toolName;
            }
        }

        return $names;
    }
}
