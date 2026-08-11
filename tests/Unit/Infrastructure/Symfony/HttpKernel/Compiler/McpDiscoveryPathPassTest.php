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
use Sulu\Mcp\Infrastructure\Symfony\HttpKernel\Compiler\McpDiscoveryPathPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(McpDiscoveryPathPass::class)]
final class McpDiscoveryPathPassTest extends TestCase
{
    public function testAppendsBundleSrcDirRelativeToProjectDir(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', \dirname(__DIR__, 6).'/tests/Application');
        $container->setParameter('mcp.discovery.scan_dirs', ['src']);

        (new McpDiscoveryPathPass())->process($container);

        self::assertSame(['src', '../../src'], $container->getParameter('mcp.discovery.scan_dirs'));
    }

    public function testIsNoOpWhenScanDirsParameterIsMissing(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', '/tmp/project');

        (new McpDiscoveryPathPass())->process($container);

        self::assertFalse($container->hasParameter('mcp.discovery.scan_dirs'));
    }

    public function testDoesNotDuplicateAnAlreadyPresentEntry(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', \dirname(__DIR__, 6).'/tests/Application');
        $container->setParameter('mcp.discovery.scan_dirs', ['src', '../../src']);

        (new McpDiscoveryPathPass())->process($container);

        self::assertSame(['src', '../../src'], $container->getParameter('mcp.discovery.scan_dirs'));
    }
}
