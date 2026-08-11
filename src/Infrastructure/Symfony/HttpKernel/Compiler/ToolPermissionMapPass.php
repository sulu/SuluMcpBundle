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
use Sulu\Mcp\Domain\Security\RequiresPermission;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Extracts #[RequiresPermission] declarations from every mcp.tool-tagged service
 * class at compile time into the `sulu_mcp.tool_permissions` parameter.
 * Compile-time (not runtime) because tools are absent from the MCP registry until
 * Builder::build().
 *
 * @internal
 */
final class ToolPermissionMapPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $map = [];
        foreach (array_keys($container->findTaggedServiceIds('mcp.tool')) as $serviceId) {
            $definition = $container->getDefinition($serviceId);
            $class = $definition->getClass() ?? $serviceId;
            if (!class_exists($class)) {
                continue;
            }
            $entry = self::extract($class);
            if (null !== $entry) {
                $map[$entry['name']] = $entry;
            }
        }

        $container->setParameter('sulu_mcp.tool_permissions', $map);
    }

    /**
     * @return array{name: string, requirements: list<array{context: string, permission: string}>, contextArgument: ?string, contextResolver: ?string, objectResolved: bool, discoveryContexts: list<string>}|null
     */
    public static function extract(string $class): ?array
    {
        $reflection = new \ReflectionClass($class);
        foreach ($reflection->getMethods() as $method) {
            $toolAttrs = $method->getAttributes(McpTool::class);
            $permAttrs = $method->getAttributes(RequiresPermission::class);
            if ([] === $toolAttrs || [] === $permAttrs) {
                continue;
            }

            $tool = $toolAttrs[0]->newInstance();
            $perm = $permAttrs[0]->newInstance();

            if ([] === $perm->requirements) {
                throw new \LogicException(\sprintf('Tool %s declares #[RequiresPermission] with no requirements.', $class));
            }

            $requirements = [];
            foreach ($perm->requirements as $requirement) {
                $requirements[] = [
                    'context' => $requirement->contextTemplate,
                    'permission' => $requirement->permissionType,
                ];
            }

            return [
                'name' => $tool->name,
                'requirements' => $requirements,
                'contextArgument' => $perm->contextArgument,
                'contextResolver' => $perm->contextResolver,
                'objectResolved' => $perm->objectResolved,
                'discoveryContexts' => $perm->discoveryContexts,
            ];
        }

        return null;
    }
}
