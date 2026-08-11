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

use Mcp\Capability\Registry\ReferenceHandler;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Builds a service locator over all mcp.tool-tagged services keyed by class, since
 * ReferenceHandler::getClassInstance() looks the container up by class name, and
 * injects it into a ReferenceHandler so PermissionAwareCallToolHandler can delegate
 * to a fully-wired inner handler. Mirrors mcp-bundle's McpPass.
 *
 * @internal
 */
final class ToolReferenceHandlerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $references = [];
        foreach (array_keys($container->findTaggedServiceIds('mcp.tool')) as $serviceId) {
            $class = $container->getDefinition($serviceId)->getClass() ?? $serviceId;
            if (isset($references[$class])) {
                continue;
            }
            $references[$class] = new Reference($serviceId);
        }

        $locator = ServiceLocatorTagPass::register($container, $references);

        $container->setDefinition(
            'sulu_mcp.reference_handler',
            (new Definition(ReferenceHandler::class))->setArguments([$locator]),
        );
    }
}
