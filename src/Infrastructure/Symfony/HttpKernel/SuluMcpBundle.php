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
use Sulu\Mcp\Application\AdminLink\AdminLinkProviderInterface;
use Sulu\Mcp\Domain\Content\ContentTypeExtensionInterface;
use Sulu\Mcp\Infrastructure\Symfony\HttpKernel\Compiler\DangerousToolsPass;
use Sulu\Mcp\Infrastructure\Symfony\HttpKernel\Compiler\ToolPermissionMapPass;
use Sulu\Mcp\Infrastructure\Symfony\HttpKernel\Compiler\ToolReferenceHandlerPass;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
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
 *     dangerous_tools: array<string, bool>,
 *     media_upload: array{allowed_hosts: list<string>},
 * }
 */
class SuluMcpBundle extends AbstractBundle
{
    /**
     * Name of the server prepended into symfony/mcp-bundle. Service ids are derived from it
     * (`mcp.server.<name>.registry`), so config/services.php hardcodes the same value.
     */
    public const MCP_SERVER_NAME = 'sulu';

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
                    ->useAttributeAsKey('name')
                    ->normalizeKeys(false)
                    ->info('Enables tools with hard-to-reverse effects per #[DangerousTool] category (built in: delete, publish, block_remove, media_upload). Unlisted categories stay off; one that no tool declares fails the build.')
                    ->booleanPrototype()->end()
                ->end()
                ->arrayNode('media_upload')
                    ->addDefaultsIfNotSet()
                    // Separate from the dangerous_tools flag on purpose: that node decides
                    // whether the tool exists, this one how it behaves once it does.
                    ->info('Limits applied to sulu_media_upload. Only relevant when dangerous_tools.media_upload is true. The download size is bounded by sulu_media.upload.max_filesize rather than a second limit here.')
                    ->children()
                        ->arrayNode('allowed_hosts')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                            ->info('Hosts sulu_media_upload may download from. Empty allows any public host; private and reserved addresses are refused either way.')
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
                'servers' => [
                    self::MCP_SERVER_NAME => [
                        'registry' => '*',
                        'transports' => [
                            'http' => true,
                        ],
                        'http' => [
                            'path' => $config['mcp_path'],
                        ],
                    ],
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
        $builder->setParameter('sulu_mcp.dangerous_tools', $config['dangerous_tools']);

        $builder->setParameter('sulu_mcp.media_upload.allowed_hosts', \array_map(
            static fn (string $host): string => \strtolower($host),
            $config['media_upload']['allowed_hosts'],
        ));

        $builder->registerForAutoconfiguration(ContentTypeExtensionInterface::class)->addTag('sulu_mcp.content_type_extension');
        $builder->registerForAutoconfiguration(AdminLinkProviderInterface::class)->addTag('sulu_mcp.admin_link_provider');

        $container->import(\dirname(__DIR__, 4) . '/config/services.php');

        // Agent-side counterparts of the MCP tools, only importable once symfony/ai-agent's
        // own #[AsTool] attribute class can be resolved.
        if (ContainerBuilder::willBeAvailable('symfony/ai-agent', AsTool::class, ['sulu/mcp-bundle'])) {
            $container->import(\dirname(__DIR__, 4) . '/config/services_agent.php');
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
