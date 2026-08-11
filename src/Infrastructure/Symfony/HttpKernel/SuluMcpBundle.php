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

use Sulu\Mcp\Infrastructure\Symfony\HttpKernel\Compiler\DangerousToolsPass;
use Sulu\Mcp\Infrastructure\Symfony\HttpKernel\Compiler\McpDiscoveryPathPass;
use Sulu\Mcp\Infrastructure\Symfony\HttpKernel\Compiler\ToolPermissionMapPass;
use Sulu\Mcp\Infrastructure\Symfony\HttpKernel\Compiler\ToolReferenceHandlerPass;
use Symfony\Component\Config\Definition\Configuration;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class SuluMcpBundle extends AbstractBundle
{
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
                    ->defaultValue('/admin/_mcp')
                    ->info('MCP endpoint path. Defaults to /admin/_mcp so the request is handled by the admin kernel, where Sulu services tagged sulu.context: admin (article preview provider, etc.) are registered. Keep the /admin/ prefix unless you have explicitly routed a different path to the admin kernel.')
                ->end()
                ->arrayNode('oauth')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('access_token_ttl')
                            ->defaultValue(3600)
                            ->min(1)
                            ->info('Access token lifetime in seconds')
                        ->end()
                        ->integerNode('refresh_token_ttl')
                            ->defaultValue(2592000)
                            ->min(1)
                            ->info('Refresh token lifetime in seconds (default: 30 days)')
                        ->end()
                        ->arrayNode('scopes')
                            ->scalarPrototype()->end()
                            ->defaultValue(['mcp:tools', 'mcp:resources'])
                            ->cannotBeEmpty()
                            ->info('OAuth scopes supported by the MCP server')
                        ->end()
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
                            ->info('Enable sulu_content_publish, sulu_content_unpublish, and sulu_preview_link_revoke')
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
        // prependExtension() is not handed the processed config, so the tree defined in
        // configure() is resolved here to reach mcp_path and the OAuth settings.
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
            $builder->prependExtensionConfig('league_oauth2_server', [
                'authorization_server' => [
                    'access_token_ttl' => $this->secondsToDateIntervalSpec($config['oauth']['access_token_ttl']),
                    'refresh_token_ttl' => $this->secondsToDateIntervalSpec($config['oauth']['refresh_token_ttl']),
                    'require_code_challenge_for_public_clients' => true,
                ],
                'scopes' => [
                    'available' => $config['oauth']['scopes'],
                    'default' => $config['oauth']['scopes'],
                ],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->setParameter('sulu_mcp.server_url', $config['server_url']);
        $builder->setParameter('sulu_mcp.mcp_path', $config['mcp_path']);
        $builder->setParameter('sulu_mcp.oauth.scopes', $config['oauth']['scopes']);
        $builder->setParameter('sulu_mcp.dangerous_tools.delete', $config['dangerous_tools']['delete']);
        $builder->setParameter('sulu_mcp.dangerous_tools.publish', $config['dangerous_tools']['publish']);
        $builder->setParameter('sulu_mcp.dangerous_tools.block_remove', $config['dangerous_tools']['block_remove']);
        $builder->setParameter(
            'sulu_mcp.disabled_tool_names',
            DangerousToolsPass::resolveDisabledToolNames($config['dangerous_tools']),
        );

        $container->import(\dirname(__DIR__, 4).'/config/services.yaml');
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

    /**
     * @return array<string, mixed>
     */
    private function processConfig(ContainerBuilder $builder): array
    {
        /** @var array<string, mixed> $config */
        $config = (new Processor())->processConfiguration(
            new Configuration($this, $builder, $this->extensionAlias),
            $builder->getExtensionConfig($this->extensionAlias),
        );

        return $config;
    }

    private function secondsToDateIntervalSpec(int $seconds): string
    {
        return \sprintf('PT%dS', $seconds);
    }
}
