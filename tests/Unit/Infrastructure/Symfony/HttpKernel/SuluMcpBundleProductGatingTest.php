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

namespace Sulu\Mcp\Tests\Unit\Infrastructure\Symfony\HttpKernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sulu\Mcp\Application\Content\ContentTypeResolver;
use Sulu\Mcp\Infrastructure\Symfony\HttpKernel\SuluMcpBundle;
use Sulu\Mcp\UserInterface\Mcp\Tool\PingTool;
use Sulu\Product\Infrastructure\Symfony\HttpKernel\SuluProductBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * SuluProductBundle is optional, and both branches are asserted from the bundle list
 * alone, so this runs whether or not the bundle is installed.
 */
#[CoversClass(SuluMcpBundle::class)]
final class SuluMcpBundleProductGatingTest extends TestCase
{
    public function testProductServicesAreNotRegisteredWithoutTheProductBundle(): void
    {
        $builder = $this->loadExtensionWithBundles([]);

        $services = $this->productServiceIds();
        self::assertNotEmpty($services, 'The product configuration yielded no service ids, so the assertions below would pass vacuously.');

        foreach ($services as $id) {
            self::assertFalse(
                $builder->hasDefinition($id),
                \sprintf('%s must not be registered without SuluProductBundle.', $id),
            );
        }
    }

    public function testProductServicesAreRegisteredWithTheProductBundle(): void
    {
        $builder = $this->loadExtensionWithBundles(['SuluProductBundle' => SuluProductBundle::class]);

        foreach ($this->productServiceIds() as $id) {
            self::assertTrue(
                $builder->hasDefinition($id),
                \sprintf('%s must be registered with SuluProductBundle.', $id),
            );
        }
    }

    /**
     * The service ids the product configuration adds, taken as the difference between the two
     * containers rather than read from the configuration file, so that this stays true whatever
     * shape the configuration takes.
     *
     * @return list<string>
     */
    private function productServiceIds(): array
    {
        $withoutProductBundle = $this->loadExtensionWithBundles([]);
        $withProductBundle = $this->loadExtensionWithBundles(['SuluProductBundle' => SuluProductBundle::class]);

        $ids = \array_values(\array_diff(
            \array_keys($withProductBundle->getDefinitions()),
            \array_keys($withoutProductBundle->getDefinitions()),
        ));

        \sort($ids);

        return $ids;
    }

    public function testContentTypeResolverGetsNoProductRepositoryWithoutTheProductBundle(): void
    {
        $builder = $this->loadExtensionWithBundles([]);

        $definition = $builder->getDefinition(ContentTypeResolver::class);

        self::assertNull($definition->getArgument('$productRepository'));
    }

    public function testContentTypeResolverGetsTheProductRepositoryWithTheProductBundle(): void
    {
        $builder = $this->loadExtensionWithBundles(['SuluProductBundle' => SuluProductBundle::class]);

        $definition = $builder->getDefinition(ContentTypeResolver::class);

        self::assertNotNull($definition->getArgument('$productRepository'));
    }

    public function testCoreToolsAreRegisteredRegardlessOfTheProductBundle(): void
    {
        foreach ([[], ['SuluProductBundle' => SuluProductBundle::class]] as $bundles) {
            $builder = $this->loadExtensionWithBundles($bundles);

            self::assertTrue($builder->hasDefinition(ContentTypeResolver::class));
            self::assertTrue($builder->hasDefinition(PingTool::class));
        }
    }

    /**
     * @param array<string, class-string> $bundles
     */
    private function loadExtensionWithBundles(array $bundles): ContainerBuilder
    {
        // BundleExtension routes load() through a ContainerConfigurator, which reads these
        // kernel parameters; a bare ContainerBuilder does not define them.
        $builder = new ContainerBuilder();
        $builder->setParameter('kernel.environment', 'test');
        $builder->setParameter('kernel.build_dir', \sys_get_temp_dir());
        $builder->setParameter('kernel.bundles', $bundles);

        $extension = (new SuluMcpBundle())->getContainerExtension();
        self::assertNotNull($extension);

        $extension->load([['server_url' => 'https://example.test']], $builder);

        return $builder;
    }
}
