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

namespace Sulu\Bundle\McpBundle\Tests\Unit\Infrastructure\Symfony\HttpKernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\McpBundle\Infrastructure\Symfony\HttpKernel\Configuration;
use Sulu\Bundle\McpBundle\Infrastructure\Symfony\HttpKernel\SuluMcpExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

#[CoversClass(SuluMcpExtension::class)]
#[CoversClass(Configuration::class)]
final class SuluMcpExtensionTest extends TestCase
{
    public function testPrependWiresMcpAndLeagueOAuthConfiguration(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->extension('mcp'));
        $container->registerExtension($this->extension('league_oauth2_server'));
        $container->registerExtension(new SuluMcpExtension());
        $container->loadFromExtension('sulu_mcp', [
            'server_url' => 'https://sulu.example.com',
            'mcp_path' => '/admin/custom-mcp',
            'oauth' => [
                'access_token_ttl' => 120,
                'refresh_token_ttl' => 240,
                'scopes' => ['mcp:tools'],
            ],
        ]);

        (new SuluMcpExtension())->prepend($container);

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
                    'authorization_server' => [
                        'access_token_ttl' => 'PT120S',
                        'refresh_token_ttl' => 'PT240S',
                        'require_code_challenge_for_public_clients' => true,
                    ],
                    'scopes' => [
                        'available' => ['mcp:tools'],
                        'default' => ['mcp:tools'],
                    ],
                ],
            ],
            $container->getExtensionConfig('league_oauth2_server'),
        );
    }

    public function testLoadSetsDisabledToolNamesFromDangerousToolsConfig(): void
    {
        $container = new ContainerBuilder();

        (new SuluMcpExtension())->load([
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
            ['sulu_content_publish', 'sulu_content_unpublish', 'sulu_preview_link_revoke'],
            $container->getParameter('sulu_mcp.disabled_tool_names'),
        );
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
