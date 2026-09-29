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
 * Removes the `mcp.tool` tags of dangerous tools whose category is not enabled in bundle
 * configuration, and a service with no MCP tag left. Must run before symfony/mcp-bundle's
 * McpPass so the removed tools are absent from the registry.
 *
 * Categories come from each tool's `#[DangerousTool]`, so other bundles can gate their
 * own tools. The disabled names also go to `FilteredRegistry`; they are collected here
 * because `loadExtension()` runs before the tools are tagged.
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

        /** @var array<string, list<array{serviceId: string, tagIndex: int, toolName: string}>> $toolsByCategory */
        $toolsByCategory = [];
        foreach ($container->findTaggedServiceIds('mcp.tool') as $serviceId => $tags) {
            $definition = $container->getDefinition($serviceId);
            if ($definition->isAbstract()) {
                continue;
            }

            $class = $container->getParameterBag()->resolveValue($definition->getClass() ?? $serviceId);
            if (!\is_string($class) || !\class_exists($class)) {
                throw new \LogicException(\sprintf('The MCP service "%s" is tagged "mcp.tool" but maps to a class that does not exist.', $serviceId));
            }

            foreach (\array_values($tags) as $tagIndex => $tagAttributes) {
                $method = \is_array($tagAttributes) ? ($tagAttributes['method'] ?? '__invoke') : '__invoke';
                if (!\is_string($method) || !\method_exists($class, $method)) {
                    continue;
                }

                $dangerous = self::readAttribute($class, $method, DangerousTool::class);
                if (null === $dangerous) {
                    continue;
                }

                $tool = self::readAttribute($class, $method, McpTool::class);
                if (null === $tool?->name) {
                    throw new \LogicException(\sprintf('Tool %s::%s() declares #[DangerousTool] without an explicit #[McpTool] name.', $class, $method));
                }

                $toolsByCategory[$dangerous->category][] = ['serviceId' => $serviceId, 'tagIndex' => $tagIndex, 'toolName' => $tool->name];
            }
        }

        $unknownCategories = \array_diff(\array_keys($config), \array_keys($toolsByCategory));
        if ([] !== $unknownCategories) {
            throw new \LogicException(\sprintf(
                'Unknown dangerous_tools categor%s "%s": no tool declares #[DangerousTool] for %s.',
                1 === \count($unknownCategories) ? 'y' : 'ies',
                \implode('", "', $unknownCategories),
                1 === \count($unknownCategories) ? 'it' : 'them',
            ));
        }

        $disabledToolNames = [];
        /** @var array<string, list<int>> $tagIndexesToRemove */
        $tagIndexesToRemove = [];
        foreach ($toolsByCategory as $category => $tools) {
            if (true === ($config[$category] ?? false)) {
                continue;
            }

            foreach ($tools as $tool) {
                $tagIndexesToRemove[$tool['serviceId']][] = $tool['tagIndex'];
                $disabledToolNames[] = $tool['toolName'];
            }
        }

        foreach ($tagIndexesToRemove as $serviceId => $tagIndexes) {
            $definition = $container->getDefinition($serviceId);
            $tags = $definition->getTags();
            $remaining = [];
            foreach (\array_values($definition->getTag('mcp.tool')) as $tagIndex => $tagAttributes) {
                if (!\in_array($tagIndex, $tagIndexes, true)) {
                    $remaining[] = $tagAttributes;
                }
            }

            if ([] !== $remaining) {
                $tags['mcp.tool'] = $remaining;
                $definition->setTags($tags);

                continue;
            }

            unset($tags['mcp.tool']);
            if ([] !== \array_filter(\array_keys($tags), static fn (string $tag): bool => \str_starts_with($tag, 'mcp.'))) {
                $definition->setTags($tags);

                continue;
            }

            $container->removeDefinition($serviceId);
        }

        $container->setParameter('sulu_mcp.disabled_tool_names', $disabledToolNames);
    }

    /**
     * @template T of object
     *
     * @param class-string $class
     * @param class-string<T> $attributeClass
     *
     * @return T|null
     */
    private static function readAttribute(string $class, string $method, string $attributeClass): ?object
    {
        $reflection = new \ReflectionMethod($class, $method);

        $attributes = $reflection->getAttributes($attributeClass, \ReflectionAttribute::IS_INSTANCEOF);
        if ([] === $attributes) {
            $attributes = $reflection->getDeclaringClass()->getAttributes($attributeClass, \ReflectionAttribute::IS_INSTANCEOF);
        }

        return [] !== $attributes ? $attributes[0]->newInstance() : null;
    }
}
