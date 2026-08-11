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

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * @internal
 */
class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('sulu_mcp');

        $treeBuilder->getRootNode()
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

        return $treeBuilder;
    }
}
