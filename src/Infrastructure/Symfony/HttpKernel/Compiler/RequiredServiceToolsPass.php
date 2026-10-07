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

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Removes tools whose required service is not in the container, so a tool that needs a newer
 * Sulu is absent on older versions instead of failing the build. Must run before
 * DangerousToolsPass and ToolPermissionMapPass so a removed tool leaves no trace in either.
 *
 * @internal
 */
final class RequiredServiceToolsPass implements CompilerPassInterface
{
    /**
     * @param array<string, string> $requiredServices tool service id => id of the service it needs
     */
    public function __construct(
        private readonly array $requiredServices,
    ) {
    }

    public function process(ContainerBuilder $container): void
    {
        foreach ($this->requiredServices as $toolServiceId => $requiredServiceId) {
            if (!$container->has($requiredServiceId)) {
                $container->removeDefinition($toolServiceId);
            }
        }
    }
}
