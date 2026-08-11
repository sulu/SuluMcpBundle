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

use Mcp\Capability\Registry\ReferenceHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sulu\Mcp\Infrastructure\Symfony\HttpKernel\Compiler\ToolReferenceHandlerPass;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(ToolReferenceHandlerPass::class)]
final class ToolReferenceHandlerPassTest extends TestCase
{
    public function testProcessRegistersReferenceHandlerBackedByServiceLocator(): void
    {
        $container = new ContainerBuilder();
        $container->register('tool.a', 'App\ToolA')->addTag('mcp.tool');
        $container->register('tool.b', 'App\ToolB')->addTag('mcp.tool');

        (new ToolReferenceHandlerPass())->process($container);

        self::assertTrue($container->hasDefinition('sulu_mcp.reference_handler'));
        $definition = $container->getDefinition('sulu_mcp.reference_handler');
        self::assertSame(ReferenceHandler::class, $definition->getClass());

        self::assertSame(
            ['App\ToolA' => 'tool.a', 'App\ToolB' => 'tool.b'],
            $this->serviceLocatorMap($container, $definition),
        );
    }

    public function testProcessKeepsOnlyFirstServiceWhenClassIsSharedByMultipleTags(): void
    {
        $container = new ContainerBuilder();
        $container->register('tool.a1', 'App\SharedTool')->addTag('mcp.tool');
        $container->register('tool.a2', 'App\SharedTool')->addTag('mcp.tool');

        (new ToolReferenceHandlerPass())->process($container);

        $definition = $container->getDefinition('sulu_mcp.reference_handler');

        self::assertSame(
            ['App\SharedTool' => 'tool.a1'],
            $this->serviceLocatorMap($container, $definition),
        );
    }

    public function testProcessFallsBackToServiceIdWhenDefinitionHasNoClass(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('app.tool_without_class', new Definition())->addTag('mcp.tool');

        (new ToolReferenceHandlerPass())->process($container);

        $definition = $container->getDefinition('sulu_mcp.reference_handler');

        self::assertSame(
            ['app.tool_without_class' => 'app.tool_without_class'],
            $this->serviceLocatorMap($container, $definition),
        );
    }

    public function testProcessBuildsEmptyLocatorWhenNoToolsAreTagged(): void
    {
        $container = new ContainerBuilder();

        (new ToolReferenceHandlerPass())->process($container);

        $definition = $container->getDefinition('sulu_mcp.reference_handler');

        self::assertSame([], $this->serviceLocatorMap($container, $definition));
    }

    /**
     * @return array<string, string> class name => backing service id
     */
    private function serviceLocatorMap(ContainerBuilder $container, Definition $referenceHandlerDefinition): array
    {
        $locatorReference = $referenceHandlerDefinition->getArgument(0);
        self::assertInstanceOf(Reference::class, $locatorReference);

        $locatorDefinition = $container->getDefinition((string) $locatorReference);
        $services = $locatorDefinition->getArgument(0);
        self::assertIsArray($services);

        $map = [];
        foreach ($services as $class => $closureArgument) {
            self::assertInstanceOf(ServiceClosureArgument::class, $closureArgument);
            $reference = $closureArgument->getValues()[0];
            self::assertInstanceOf(Reference::class, $reference);
            $map[$class] = (string) $reference;
        }

        return $map;
    }
}
