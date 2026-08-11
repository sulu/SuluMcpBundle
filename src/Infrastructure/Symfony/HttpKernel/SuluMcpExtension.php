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
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * @internal
 */
class SuluMcpExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        $configs = $container->getExtensionConfig($this->getAlias());
        $config = $this->processConfiguration(new Configuration(), $configs);

        if ($container->hasExtension('mcp')) {
            $container->prependExtensionConfig('mcp', [
                'client_transports' => [
                    'http' => true,
                ],
                'http' => [
                    'path' => $config['mcp_path'],
                ],
            ]);
        }

        if ($container->hasExtension('league_oauth2_server')) {
            $container->prependExtensionConfig('league_oauth2_server', [
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

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('sulu_mcp.server_url', $config['server_url']);
        $container->setParameter('sulu_mcp.mcp_path', $config['mcp_path']);
        $container->setParameter('sulu_mcp.oauth.scopes', $config['oauth']['scopes']);
        $container->setParameter('sulu_mcp.dangerous_tools.delete', $config['dangerous_tools']['delete']);
        $container->setParameter('sulu_mcp.dangerous_tools.publish', $config['dangerous_tools']['publish']);
        $container->setParameter('sulu_mcp.dangerous_tools.block_remove', $config['dangerous_tools']['block_remove']);
        $container->setParameter(
            'sulu_mcp.disabled_tool_names',
            DangerousToolsPass::resolveDisabledToolNames($config['dangerous_tools']),
        );
        $loader = new YamlFileLoader(
            $container,
            new FileLocator(\dirname(__DIR__, 4).'/config')
        );
        $loader->load('services.yaml');
    }

    private function secondsToDateIntervalSpec(int $seconds): string
    {
        return \sprintf('PT%dS', $seconds);
    }
}
