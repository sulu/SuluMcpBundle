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

use Mcp\Capability\Attribute\McpTool;
use Sulu\Mcp\Domain\Security\DangerousTool;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Removes tool service definitions for dangerous categories that are not enabled
 * in bundle configuration. Must run before symfony/mcp-bundle's McpPass so the
 * removed services are absent from the `mcp.tool` tagged-service iterator.
 *
 * Categories are read from every `mcp.tool`-tagged service's `#[DangerousTool]`
 * attribute rather than a fixed map, so a bundle other than this one can gate its
 * own tools the same way. The disabled tool NAMES are additionally published as a
 * container parameter and consumed by `FilteredRegistry`, which refuses the same
 * tools at registration time -- covering any path that reaches the registry
 * without going through DI. Computing that list here rather than in the bundle's
 * `loadExtension()` is deliberate: `#[DangerousTool]` categories are only known
 * once tool services are tagged, which happens after `loadExtension` runs.
 *
 * @internal
 */
final class DangerousToolsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $config = $container->hasParameter('sulu_mcp.dangerous_tools')
            ? $container->getParameter('sulu_mcp.dangerous_tools')
            : [];
        \assert(\is_array($config));

        /** @var array<string, array<string, string>> $serviceIdsByCategory class-string|service id => tool name, keyed by category */
        $serviceIdsByCategory = [];
        foreach (\array_keys($container->findTaggedServiceIds('mcp.tool')) as $serviceId) {
            $definition = $container->getDefinition($serviceId);
            $class = $definition->getClass() ?? $serviceId;
            if (!\class_exists($class)) {
                continue;
            }

            foreach (self::extractDangerousTools($class) as $category => $toolName) {
                $serviceIdsByCategory[$category][$serviceId] = $toolName;
            }
        }

        $unknownCategories = \array_diff(\array_keys($config), \array_keys($serviceIdsByCategory));
        if ([] !== $unknownCategories) {
            throw new \LogicException(\sprintf(
                'Unknown dangerous_tools categor%s "%s": no tool declares #[DangerousTool] for %s.',
                1 === \count($unknownCategories) ? 'y' : 'ies',
                \implode('", "', $unknownCategories),
                1 === \count($unknownCategories) ? 'it' : 'them',
            ));
        }

        $disabledToolNames = [];
        foreach ($serviceIdsByCategory as $category => $tools) {
            if (true === ($config[$category] ?? false)) {
                continue;
            }

            foreach ($tools as $serviceId => $toolName) {
                if ($container->hasDefinition($serviceId)) {
                    $container->removeDefinition($serviceId);
                }
                $disabledToolNames[] = $toolName;
            }
        }

        $container->setParameter('sulu_mcp.disabled_tool_names', $disabledToolNames);
    }

    /**
     * @param class-string $class
     *
     * @return iterable<string, string> tool name, keyed by category
     */
    private static function extractDangerousTools(string $class): iterable
    {
        $reflection = new \ReflectionClass($class);
        foreach ($reflection->getMethods() as $method) {
            $toolAttrs = $method->getAttributes(McpTool::class);
            $dangerousAttrs = $method->getAttributes(DangerousTool::class);
            if ([] === $toolAttrs || [] === $dangerousAttrs) {
                continue;
            }

            $tool = $toolAttrs[0]->newInstance();
            $dangerous = $dangerousAttrs[0]->newInstance();

            if (null === $tool->name) {
                throw new \LogicException(\sprintf('Tool method in %s declares #[McpTool] without an explicit name.', $class));
            }

            yield $dangerous->category => $tool->name;
        }
    }
}
