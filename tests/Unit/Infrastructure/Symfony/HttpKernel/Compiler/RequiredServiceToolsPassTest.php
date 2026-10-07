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
use Sulu\Mcp\Infrastructure\Symfony\HttpKernel\Compiler\RequiredServiceToolsPass;
use Sulu\Mcp\Infrastructure\Symfony\HttpKernel\SuluMcpBundle;
use Sulu\Mcp\UserInterface\Mcp\Tool\AdminLink\AdminLinkGenerateTool;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

#[CoversClass(RequiredServiceToolsPass::class)]
#[CoversClass(SuluMcpBundle::class)]
final class RequiredServiceToolsPassTest extends TestCase
{
    private const GENERATOR = 'sulu_admin.resource_view_url_generator';

    public function testToolIsRemovedWhenTheServiceIsMissing(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(AdminLinkGenerateTool::class, new Definition(AdminLinkGenerateTool::class));

        $this->process($container);

        self::assertFalse($container->hasDefinition(AdminLinkGenerateTool::class));
    }

    public function testToolStaysWhenTheServiceExists(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(AdminLinkGenerateTool::class, new Definition(AdminLinkGenerateTool::class));
        $container->setDefinition(self::GENERATOR, new Definition(\stdClass::class));

        $this->process($container);

        self::assertTrue($container->hasDefinition(AdminLinkGenerateTool::class));
    }

    public function testToolStaysWhenTheServiceIsAnAlias(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(AdminLinkGenerateTool::class, new Definition(AdminLinkGenerateTool::class));
        $container->setDefinition('some.generator', new Definition(\stdClass::class));
        $container->setAlias(self::GENERATOR, 'some.generator');

        $this->process($container);

        self::assertTrue($container->hasDefinition(AdminLinkGenerateTool::class));
    }

    public function testBundleRegistersThePassForTheAdminLinkTool(): void
    {
        $container = new ContainerBuilder();

        (new SuluMcpBundle())->build($container);

        $passes = $container->getCompilerPassConfig()->getBeforeOptimizationPasses();
        $registered = \array_values(\array_filter($passes, static fn (object $pass): bool => $pass instanceof RequiredServiceToolsPass));
        self::assertCount(1, $registered);

        $container->setDefinition(AdminLinkGenerateTool::class, new Definition(AdminLinkGenerateTool::class));
        $registered[0]->process($container);
        self::assertFalse($container->hasDefinition(AdminLinkGenerateTool::class));
    }

    private function process(ContainerBuilder $container): void
    {
        (new RequiredServiceToolsPass([AdminLinkGenerateTool::class => self::GENERATOR]))->process($container);
    }
}
