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

namespace Sulu\Bundle\McpBundle\Infrastructure\Symfony\HttpKernel;

use Sulu\Bundle\McpBundle\Infrastructure\Symfony\HttpKernel\Compiler\DangerousToolsPass;
use Sulu\Bundle\McpBundle\Infrastructure\Symfony\HttpKernel\Compiler\McpDiscoveryPathPass;
use Sulu\Bundle\McpBundle\Infrastructure\Symfony\HttpKernel\Compiler\ToolPermissionMapPass;
use Sulu\Bundle\McpBundle\Infrastructure\Symfony\HttpKernel\Compiler\ToolReferenceHandlerPass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class SuluMcpBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__, 4);
    }

    /**
     * Symfony resolves the extension by convention as
     * `<BundleNamespace>\DependencyInjection\<Name>Extension`. This bundle keeps the
     * extension next to the bundle in the HttpKernel layer instead, so the lookup is
     * done explicitly rather than reintroducing a DependencyInjection directory.
     */
    public function getContainerExtension(): ?ExtensionInterface
    {
        return $this->extension ??= new SuluMcpExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Priority 100 ensures this runs before symfony/mcp-bundle's McpPass
        // (which scans `mcp.tool`-tagged services in BEFORE_OPTIMIZATION).
        $container->addCompilerPass(new DangerousToolsPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 100);
        $container->addCompilerPass(new ToolPermissionMapPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 90);
        $container->addCompilerPass(new ToolReferenceHandlerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 80);
        $container->addCompilerPass(new McpDiscoveryPathPass());
    }
}
