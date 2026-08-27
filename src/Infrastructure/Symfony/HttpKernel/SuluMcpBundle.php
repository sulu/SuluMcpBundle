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

namespace Sulu\Mcp\Infrastructure\Symfony\HttpKernel;

use Composer\InstalledVersions;
use Sulu\Mcp\Infrastructure\Symfony\HttpKernel\Compiler\DangerousToolsPass;
use Sulu\Mcp\Infrastructure\Symfony\HttpKernel\Compiler\ToolPermissionMapPass;
use Sulu\Mcp\Infrastructure\Symfony\HttpKernel\Compiler\ToolReferenceHandlerPass;
use Sulu\Product\Infrastructure\Symfony\HttpKernel\SuluProductBundle;
use Symfony\Component\Config\Definition\Configuration;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * @phpstan-type SuluMcpConfig array{
 *     server_url: string,
 *     mcp_path: string,
 *     dangerous_tools: array{delete: bool, publish: bool, block_remove: bool},
 * }
 */
class SuluMcpBundle extends AbstractBundle
{
    /**
     * mcp:tools covers the tools/* JSON-RPC methods, mcp:resources the resources/* ones.
     *
     * @var list<string>
     */
    public const SCOPES = ['mcp:tools', 'mcp:resources'];

    /**
     * Reported by sulu_ping when Composer cannot name the installed version.
     */
    private const FALLBACK_VERSION = 'unknown';

    protected string $extensionAlias = 'sulu_mcp';

    public function getPath(): string
    {
        return \dirname(__DIR__, 4); // target the root of the library where config, src, ... is located
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('server_url')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->info('Public base URL of the Sulu installation (e.g., https://sulu.example.com)')
                ->end()
                ->scalarNode('mcp_path')
                    ->defaultValue('/admin/mcp')
                    ->info('MCP endpoint path. Defaults to /admin/mcp so the request is handled by the admin kernel, where Sulu services tagged sulu.context: admin (article preview provider, etc.) are registered. Keep it in sync with the prefix your project imports config/routing_admin.yaml under, and keep the /admin/ prefix unless you have explicitly routed a different path to the admin kernel.')
                    // The listeners compare the request path against this value for equality.
                    ->validate()
                        ->ifTrue(static fn (mixed $path): bool => !\is_string($path)
                            || !\str_starts_with($path, '/')
                            || \str_ends_with($path, '/')
                            || false !== \strpbrk($path, '{}%?#'))
                        ->thenInvalid('sulu_mcp.mcp_path must be a literal path: starting with "/", without a trailing "/" and without "{", "}", "%%", "?" or "#".')
                    ->end()
                ->end()
                ->arrayNode('dangerous_tools')
                    ->addDefaultsIfNotSet()
                    ->info('Opt-in flags for tools with hard-to-reverse side effects. All categories default to false.')
                    ->children()
                        ->booleanNode('delete')
                            ->defaultFalse()
                            ->info('Enable sulu_content_delete, sulu_tag_delete, and sulu_category_delete')
                        ->end()
                        ->booleanNode('publish')
                            ->defaultFalse()
                            ->info('Enable sulu_content_publish, sulu_content_unpublish, sulu_preview_link_revoke, sulu_page_move, and sulu_page_reorder')
                        ->end()
                        ->booleanNode('block_remove')
                            ->defaultFalse()
                            ->info('Enable sulu_block_remove')
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // prependExtension() is not handed the processed config, so the tree from
        // configure() is resolved here.
        $config = $this->processConfig($builder);

        if ($builder->hasExtension('mcp')) {
            $builder->prependExtensionConfig('mcp', [
                'client_transports' => [
                    'http' => true,
                ],
                'http' => [
                    'path' => $config['mcp_path'],
                ],
            ]);
        }

        if ($builder->hasExtension('league_oauth2_server')) {
            // Only `scopes.available`; everything else is the project's to configure.
            $builder->prependExtensionConfig('league_oauth2_server', [
                'scopes' => [
                    'available' => self::SCOPES,
                ],
            ]);
        }
    }

    /**
     * @param SuluMcpConfig $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->setParameter('sulu_mcp.version', self::resolveVersion());
        $builder->setParameter('sulu_mcp.server_url', $config['server_url']);
        $builder->setParameter('sulu_mcp.mcp_path', $config['mcp_path']);
        $builder->setParameter('sulu_mcp.oauth.scopes', self::SCOPES);
        $builder->setParameter('sulu_mcp.dangerous_tools.delete', $config['dangerous_tools']['delete']);
        $builder->setParameter('sulu_mcp.dangerous_tools.publish', $config['dangerous_tools']['publish']);
        $builder->setParameter('sulu_mcp.dangerous_tools.block_remove', $config['dangerous_tools']['block_remove']);

        $builder->setParameter(
            'sulu_mcp.disabled_tool_names',
            DangerousToolsPass::resolveDisabledToolNames($config['dangerous_tools']),
        );

        $container->import(\dirname(__DIR__, 4) . '/config/services.yaml');

        // Tools reach the registry only as mcp.tool-tagged services, so skipping the import
        // is all it takes to keep them out of an installation without SuluProductBundle.
        if (self::isProductBundleLoaded($builder)) {
            $container->import(\dirname(__DIR__, 4) . '/config/services_product.yaml');
        }
    }

    /**
     * The installed version of this package, as Composer recorded it.
     */
    private static function resolveVersion(): string
    {
        if (!InstalledVersions::isInstalled('sulu/mcp-bundle')) {
            return self::FALLBACK_VERSION;
        }

        return InstalledVersions::getPrettyVersion('sulu/mcp-bundle') ?? self::FALLBACK_VERSION;
    }

    /**
     * The bundle list rather than class_exists(): the classes can be installed without the
     * bundle being registered, and then its services do not exist to wire against.
     */
    private static function isProductBundleLoaded(ContainerBuilder $builder): bool
    {
        if (!$builder->hasParameter('kernel.bundles')) {
            return false;
        }

        $bundles = $builder->getParameter('kernel.bundles');

        return \is_array($bundles) && \in_array(SuluProductBundle::class, $bundles, true);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Priority 100 so this runs before symfony/mcp-bundle's McpPass.
        $container->addCompilerPass(new DangerousToolsPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 100);
        $container->addCompilerPass(new ToolPermissionMapPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 90);
        $container->addCompilerPass(new ToolReferenceHandlerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 80);
    }

    /**
     * @return SuluMcpConfig
     */
    private function processConfig(ContainerBuilder $builder): array
    {
        // processConfiguration() only declares `array`; shape guaranteed by configure() above
        /** @var SuluMcpConfig $config */
        $config = (new Processor())->processConfiguration(
            new Configuration($this, $builder, $this->extensionAlias),
            $builder->getExtensionConfig($this->extensionAlias),
        );

        return $config;
    }
}
