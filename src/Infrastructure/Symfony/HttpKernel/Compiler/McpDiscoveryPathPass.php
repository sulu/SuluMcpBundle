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
use Symfony\Component\Filesystem\Path;

/**
 * Appends this bundle's own `src` directory to the `mcp.discovery.scan_dirs`
 * parameter so symfony/mcp-bundle's attribute discovery finds Sulu's MCP tools.
 *
 * A compiler pass is used instead of prepending to the `mcp` extension config
 * because `discovery.scan_dirs` is a prototyped array node: host-project config
 * REPLACES a prepended value rather than merging with it. Appending to the
 * already-merged container parameter guarantees this bundle's tools are always
 * discovered, regardless of what the host project configures.
 *
 * @internal
 */
final class McpDiscoveryPathPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('mcp.discovery.scan_dirs')) {
            return;
        }

        $bundleSrcDir = \dirname(__DIR__, 5).'/src';
        $scanDir = Path::makeRelative($bundleSrcDir, $container->getParameter('kernel.project_dir'));

        /** @var list<string> $scanDirs */
        $scanDirs = $container->getParameter('mcp.discovery.scan_dirs');
        if (\in_array($scanDir, $scanDirs, true)) {
            return;
        }

        $scanDirs[] = $scanDir;
        $container->setParameter('mcp.discovery.scan_dirs', $scanDirs);
    }
}
