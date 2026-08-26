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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sulu\Mcp\Infrastructure\Symfony\HttpKernel\SuluMcpBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;

#[CoversClass(SuluMcpBundle::class)]
final class SuluMcpBundleTest extends TestCase
{
    public function testExtensionIsRegisteredUnderTheSuluMcpAlias(): void
    {
        $extension = (new SuluMcpBundle())->getContainerExtension();

        self::assertNotNull($extension);
        self::assertSame('sulu_mcp', $extension->getAlias());
    }

    public function testPrependWiresMcpAndLeagueOAuthConfiguration(): void
    {
        $container = $this->container();
        $container->registerExtension($this->extension('mcp'));
        $container->registerExtension($this->extension('league_oauth2_server'));

        $extension = (new SuluMcpBundle())->getContainerExtension();
        self::assertInstanceOf(PrependExtensionInterface::class, $extension);
        $container->registerExtension($extension);

        $container->loadFromExtension('sulu_mcp', [
            'server_url' => 'https://sulu.example.com',
            'mcp_path' => '/admin/custom-mcp',
        ]);

        $extension->prepend($container);

        self::assertSame(
            [
                [
                    'client_transports' => ['http' => true],
                    'http' => ['path' => '/admin/custom-mcp'],
                ],
            ],
            $container->getExtensionConfig('mcp'),
        );

        self::assertSame(
            [
                [
                    'scopes' => [
                        'available' => ['mcp:tools', 'mcp:resources'],
                    ],
                ],
            ],
            $container->getExtensionConfig('league_oauth2_server'),
        );
    }

    public function testLoadSetsDisabledToolNamesFromDangerousToolsConfig(): void
    {
        $container = $this->container();

        $extension = (new SuluMcpBundle())->getContainerExtension();
        self::assertNotNull($extension);

        $extension->load([
            [
                'server_url' => 'https://sulu.example.com',
                'dangerous_tools' => [
                    'delete' => true,
                    'publish' => false,
                    'block_remove' => true,
                ],
            ],
        ], $container);

        self::assertSame(
            ['sulu_content_publish', 'sulu_content_unpublish', 'sulu_preview_link_revoke', 'sulu_page_move', 'sulu_page_reorder'],
            $container->getParameter('sulu_mcp.disabled_tool_names'),
        );
    }

    public function testLoadAppliesConfigurationDefaults(): void
    {
        $container = $this->container();

        $extension = (new SuluMcpBundle())->getContainerExtension();
        self::assertNotNull($extension);

        $extension->load([['server_url' => 'https://sulu.example.com']], $container);

        self::assertSame('/admin/mcp', $container->getParameter('sulu_mcp.mcp_path'));
        self::assertSame(['mcp:tools', 'mcp:resources'], $container->getParameter('sulu_mcp.oauth.scopes'));
        self::assertFalse($container->getParameter('sulu_mcp.dangerous_tools.delete'));
    }

    #[DataProvider('provideInvalidMcpPaths')]
    public function testRejectsMcpPathTheRouterCanNeverProduce(string $mcpPath): void
    {
        $container = $this->container();

        $extension = (new SuluMcpBundle())->getContainerExtension();
        self::assertNotNull($extension);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/must be a literal path/');

        $extension->load([['server_url' => 'https://sulu.example.com', 'mcp_path' => $mcpPath]], $container);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidMcpPaths(): iterable
    {
        yield 'no leading slash' => ['admin/mcp'];
        yield 'trailing slash' => ['/admin/mcp/'];
        yield 'empty' => [''];
        yield 'route placeholder' => ['/admin/{transport}'];
        yield 'query string' => ['/admin/mcp?x'];
    }

    /**
     * BundleExtension routes load()/prepend() through a ContainerConfigurator, which reads
     * these kernel parameters. A bare ContainerBuilder does not define them.
     */
    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', \sys_get_temp_dir());

        return $container;
    }

    private function extension(string $alias): Extension
    {
        return new class($alias) extends Extension {
            public function __construct(
                private readonly string $alias,
            ) {
            }

            public function getAlias(): string
            {
                return $this->alias;
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }
        };
    }
}
