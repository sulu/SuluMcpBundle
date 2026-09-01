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

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Sulu\Bundle\PreviewBundle\Application\Manager\PreviewLinkManagerInterface;
use Sulu\Mcp\Application\AdminLink\AdminLinkGeneratorInterface;
use Sulu\Mcp\Application\AdminLink\AdminLinkProviderInterface;
use Sulu\Mcp\Application\Article\ArticleGroupResolver;
use Sulu\Mcp\Application\Content\BlockDataValidator;
use Sulu\Mcp\Application\Content\ContentMetadataMapper;
use Sulu\Mcp\Application\Content\ContentTypeResolver;
use Sulu\Mcp\Application\Metadata\ExtensionFieldsProvider;
use Sulu\Mcp\Application\Metadata\FieldNormalizer;
use Sulu\Mcp\Application\Metadata\FieldValueExampleProvider;
use Sulu\Mcp\Application\Metadata\MetadataLocaleResolver;
use Sulu\Mcp\Application\Security\AccessControlFilterFactory;
use Sulu\Mcp\Application\Security\ContentSecurityContextResolver;
use Sulu\Mcp\Application\Security\PageDescendantPermissionChecker;
use Sulu\Mcp\Application\Security\ToolPermissionChecker;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Application\Security\ToolVisibilityResolver;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Infrastructure\League\EventListener\OAuthAuthorizationListener;
use Sulu\Mcp\Infrastructure\Mcp\FilteredRegistry;
use Sulu\Mcp\Infrastructure\Mcp\PermissionAwareCallToolHandler;
use Sulu\Mcp\Infrastructure\Sulu\Admin\OAuthConsentAdmin;
use Sulu\Mcp\Infrastructure\Sulu\AdminLink\ArticleAdminLinkProvider;
use Sulu\Mcp\Infrastructure\Sulu\AdminLink\CategoryAdminLinkProvider;
use Sulu\Mcp\Infrastructure\Sulu\AdminLink\MediaAdminLinkProvider;
use Sulu\Mcp\Infrastructure\Sulu\AdminLink\PageAdminLinkProvider;
use Sulu\Mcp\Infrastructure\Sulu\AdminLink\SnippetAdminLinkProvider;
use Sulu\Mcp\Infrastructure\Sulu\AdminLink\TagAdminLinkProvider;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\Infrastructure\Sulu\Security\ContactSecurityContextResolver;
use Sulu\Mcp\Infrastructure\Sulu\Security\EntryPoint\OAuthAuthorizeEntryPoint;
use Sulu\Mcp\Infrastructure\Sulu\Security\EventListener\OAuthSystemStoreListener;
use Sulu\Mcp\Infrastructure\Symfony\HttpKernel\EventListener\McpExceptionListener;
use Sulu\Mcp\Infrastructure\Symfony\HttpKernel\EventListener\McpRequestFormatListener;
use Sulu\Mcp\Infrastructure\Symfony\Routing\AdminLinkGenerator;
use Sulu\Mcp\Infrastructure\Symfony\Security\EntryPoint\McpAuthenticationEntryPoint;
use Sulu\Mcp\Infrastructure\Symfony\Security\EventListener\McpLoginSuccessListener;
use Sulu\Mcp\Infrastructure\Symfony\Security\EventListener\McpScopeListener;
use Sulu\Mcp\Infrastructure\Symfony\Security\OAuthConsentStore;
use Sulu\Mcp\UserInterface\Command\CreateMcpClientCommand;
use Sulu\Mcp\UserInterface\Controller\Admin\DynamicClientRegistrationController;
use Sulu\Mcp\UserInterface\Controller\Admin\OAuthConsentController;
use Sulu\Mcp\UserInterface\Controller\Website\WellKnownController;
use Sulu\Mcp\UserInterface\Mcp\Resource\GlobalBlocksResource;
use Sulu\Mcp\UserInterface\Mcp\Resource\TemplatesResource;
use Sulu\Mcp\UserInterface\Mcp\Resource\WebspacesResource;
use Sulu\Mcp\UserInterface\Mcp\Tool\Article\ArticleCreateTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Article\ArticleGetTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Article\ArticleListTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Article\ArticleUpdateTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Block\BlockAddTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Block\BlockListTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Block\BlockRemoveTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Block\BlockReorderTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Block\BlockUpdateTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Contact\ContactListTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Content\ContentDeleteTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Content\ContentPublishTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Content\ContentUnpublishTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\ContentSearchTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\GetContextTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Media\MediaGetTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Media\MediaListTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Media\MediaUpdateTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Navigation\NavigationGetTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Page\PageCreateTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Page\PageGetTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Page\PageListTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Page\PageMoveTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Page\PageReorderTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Page\PageTreeTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Page\PageUpdateTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\PingTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Preview\PreviewLinkGenerateTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Preview\PreviewLinkRevokeTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Snippet\SnippetCreateTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Snippet\SnippetGetTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Snippet\SnippetListTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Snippet\SnippetUpdateTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Taxonomy\CategoryCreateTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Taxonomy\CategoryDeleteTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Taxonomy\CategoryListTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Taxonomy\TagCreateTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Taxonomy\TagDeleteTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Taxonomy\TagListTool;
use Sulu\Page\Domain\Repository\NavigationRepositoryInterface;
use Symfony\Component\DependencyInjection\Reference;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
        ->instanceof(AdminLinkProviderInterface::class)
            ->tag('sulu_mcp.admin_link_provider');

    // Providers need sulu_admin.view_registry, which only exists in the admin
    // container, hence the sulu.context tag. The generator itself is
    // context-agnostic and gets an empty iterator elsewhere.
    $services->set(AdminLinkGenerator::class)
        ->arg('$providers', tagged_iterator('sulu_mcp.admin_link_provider'));
    $services->alias(AdminLinkGeneratorInterface::class, AdminLinkGenerator::class);

    foreach ([
        PageAdminLinkProvider::class,
        ArticleAdminLinkProvider::class,
        SnippetAdminLinkProvider::class,
        MediaAdminLinkProvider::class,
        TagAdminLinkProvider::class,
        CategoryAdminLinkProvider::class,
    ] as $adminLinkProvider) {
        $services->set($adminLinkProvider)
            ->arg('$viewRegistry', new Reference('sulu_admin.view_registry'))
            ->tag('sulu.context', ['context' => 'admin']);
    }

    // Second layer over DangerousToolsPass: refuses disabled tools at registration
    // time, and hides tools the current user's role does not grant from tools/list.
    $services->set(FilteredRegistry::class)
        ->decorate('mcp.server.sulu.registry')
        ->arg('$inner', new Reference('.inner'))
        ->arg('$visibilityResolver', new Reference(ToolVisibilityResolver::class))
        ->arg('$disabledToolNames', '%sulu_mcp.disabled_tool_names%');

    $services->set(ToolPermissionChecker::class);
    $services->alias(ToolPermissionCheckerInterface::class, ToolPermissionChecker::class);
    $services->set(WebspacePermissionResolver::class);

    $services->set(AccessControlFilterFactory::class)
        ->arg('$security', new Reference('security.helper'))
        ->arg('$permissions', '%sulu_security.permissions%');

    // $contextResolvers is keyed the same way as PermissionAwareCallToolHandler's.
    $services->set(ToolVisibilityResolver::class)
        ->arg('$permissionMap', '%sulu_mcp.tool_permissions%')
        ->arg('$contextResolvers', [
            'sulu_mcp.contact_context_resolver' => new Reference('sulu_mcp.contact_context_resolver'),
            'sulu_mcp.article_context_resolver' => new Reference('sulu_mcp.article_context_resolver'),
        ])
        ->arg('$allowlist', ['sulu_ping', 'sulu_get_context']);

    // The MCP delete tool dispatches RemovePageMessage directly, bypassing
    // PageDescendantSecurityListener.
    $services->set(PageDescendantPermissionChecker::class)
        ->arg('$pageRepository', new Reference('sulu_page.page_repository'))
        ->arg('$accessControlRepository', new Reference('sulu.repository.access_control'))
        ->arg('$systemStore', new Reference('sulu_security.system_store'))
        ->arg('$security', new Reference('security.helper'))
        ->arg('$permissions', '%sulu_security.permissions%');

    $services->set('sulu_mcp.contact_context_resolver', ContactSecurityContextResolver::class);

    $services->set('sulu_mcp.article_context_resolver', ArticleSecurityContextResolver::class)
        ->arg('$groupProvider', new Reference('sulu_admin.metadata_group_provider'));
    $services->alias(ArticleSecurityContextResolver::class, 'sulu_mcp.article_context_resolver');
    $services->set(ContentSecurityContextResolver::class);

    // Fail-closed gate: checks the compile-time permission map before delegating.
    $services->set(PermissionAwareCallToolHandler::class)
        ->arg('$registry', new Reference('mcp.server.sulu.registry'))
        ->arg('$referenceHandler', new Reference('sulu_mcp.reference_handler'))
        ->arg('$webspacePermissionResolver', new Reference(WebspacePermissionResolver::class))
        ->arg('$permissionMap', '%sulu_mcp.tool_permissions%')
        ->arg('$contextResolvers', [
            'sulu_mcp.contact_context_resolver' => new Reference('sulu_mcp.contact_context_resolver'),
            'sulu_mcp.article_context_resolver' => new Reference('sulu_mcp.article_context_resolver'),
        ])
        ->arg('$allowlist', ['sulu_ping', 'sulu_get_context'])
        ->tag('mcp.request_handler', ['priority' => 100]);

    $services->set(PingTool::class)
        ->arg('$version', '%sulu_mcp.version%');
    $services->set(GetContextTool::class);
    $services->set(ContentSearchTool::class)
        ->arg('$engine', new Reference('cmsig_seal.engine.default'));

    // MCP resources
    $services->set(FieldValueExampleProvider::class);
    $services->set(FieldNormalizer::class);
    $services->set(MetadataLocaleResolver::class)
        ->arg('$fallbackLocale', '%sulu_core.fallback_locale%');
    $services->set(TemplatesResource::class)
        ->arg('$formMetadataProvider', new Reference('sulu_admin.form_metadata_provider'));
    $services->set(GlobalBlocksResource::class)
        ->arg('$formMetadataProvider', new Reference('sulu_admin.form_metadata_provider'));
    $services->set(WebspacesResource::class);
    $services->set(ExtensionFieldsProvider::class)
        ->arg('$formMetadataProvider', new Reference('sulu_admin.form_metadata_provider'));
    $services->set(WellKnownController::class)
        ->arg('$serverUrl', '%sulu_mcp.server_url%')
        ->arg('$mcpPath', '%sulu_mcp.mcp_path%')
        ->arg('$scopes', '%sulu_mcp.oauth.scopes%')
        ->tag('controller.service_arguments');

    $services->set(DynamicClientRegistrationController::class)
        ->arg('$clientSecretHasher', new Reference('league.oauth2_server.password_hasher'))
        ->arg('$allowedScopes', '%sulu_mcp.oauth.scopes%')
        ->tag('controller.service_arguments');

    $services->set(OAuthConsentStore::class);

    $services->set(OAuthConsentController::class)
        ->tag('controller.service_arguments');

    $services->set(OAuthConsentAdmin::class)
        ->tag('sulu.context', ['context' => 'admin']);

    $services->set(OAuthAuthorizationListener::class);

    $services->set(OAuthAuthorizeEntryPoint::class)
        ->decorate('sulu_security.authentication_entry_point')
        ->arg('$inner', new Reference('.inner'));

    // After admin login, resume a pending OAuth authorize request.
    $services->set(McpLoginSuccessListener::class);

    $services->set(McpAuthenticationEntryPoint::class)
        ->arg('$serverUrl', '%sulu_mcp.server_url%')
        ->arg('$mcpPath', '%sulu_mcp.mcp_path%')
        ->arg('$scopes', '%sulu_mcp.oauth.scopes%');

    // Priority 7: the firewall runs at 8, so the token storage is populated by now.
    $services->set(McpScopeListener::class)
        ->arg('$mcpPath', '%sulu_mcp.mcp_path%')
        ->arg('$scopes', '%sulu_mcp.oauth.scopes%')
        ->tag('kernel.event_listener', ['event' => 'kernel.request', 'method' => 'onKernelRequest', 'priority' => 7]);

    // Stable id so security.yaml never references an internal class name.
    $services->alias('sulu_mcp.authentication_entry_point', McpAuthenticationEntryPoint::class)
        ->public();

    $services->set(McpExceptionListener::class)
        ->arg('$mcpPath', '%sulu_mcp.mcp_path%')
        ->arg('$debug', '%kernel.debug%');

    // Keeps Sulu's MarkupBundle from running the HtmlMarkupParser on JSON-RPC
    // responses.
    $services->set(McpRequestFormatListener::class)
        ->arg('$mcpPath', '%sulu_mcp.mcp_path%')
        ->tag('kernel.event_listener', ['event' => 'kernel.request', 'method' => 'onKernelRequest', 'priority' => 64]);

    // Sulu system context, so the UserProvider can authenticate OAuth users.
    $services->set(OAuthSystemStoreListener::class)
        ->arg('$mcpPath', '%sulu_mcp.mcp_path%')
        ->arg('$defaultSystem', 'Sulu')
        ->tag('kernel.event_listener', ['event' => 'kernel.request', 'method' => 'onKernelRequest', 'priority' => 64]);

    // Commands
    $services->set(CreateMcpClientCommand::class)
        ->arg('$clientSecretHasher', new Reference('league.oauth2_server.password_hasher'))
        ->arg('$serverUrl', '%sulu_mcp.server_url%')
        ->arg('$mcpPath', '%sulu_mcp.mcp_path%');

    // Page read tools
    $services->set(PageGetTool::class);
    $services->set(PageListTool::class);
    $services->set(PageTreeTool::class);

    // Page write tools
    $services->set(PageCreateTool::class);
    $services->set(PageUpdateTool::class);
    $services->set(PageMoveTool::class); // gated by dangerous_tools.publish
    $services->set(PageReorderTool::class); // gated by dangerous_tools.publish

    // Unified content tools (page | article | snippet via `type`)
    $services->set(ContentDeleteTool::class); // gated by dangerous_tools.delete
    $services->set(ContentPublishTool::class); // gated by dangerous_tools.publish
    $services->set(ContentUnpublishTool::class); // gated by dangerous_tools.publish

    // Block management tools
    $services->set(ContentTypeResolver::class)
        ->arg('$productRepository', null); // overridden in services_product.php
    $services->set(ContentMetadataMapper::class)
        ->arg('$formMetadataProvider', new Reference('sulu_admin.form_metadata_provider'));
    $services->set(BlockDataValidator::class)
        ->arg('$formMetadataProvider', new Reference('sulu_admin.form_metadata_provider'));
    $services->set(BlockListTool::class);
    $services->set(BlockAddTool::class);
    $services->set(BlockUpdateTool::class);
    $services->set(BlockRemoveTool::class); // gated by dangerous_tools.block_remove
    $services->set(BlockReorderTool::class);

    $services->alias(NavigationRepositoryInterface::class, 'sulu_page.navigation_repository');

    $services->alias(PreviewLinkManagerInterface::class, 'sulu_preview.preview_link_manager');

    // Article tools
    $services->set(ArticleGroupResolver::class)
        ->arg('$groupProvider', new Reference('sulu_admin.metadata_group_provider'));
    $services->set(ArticleGetTool::class);
    $services->set(ArticleListTool::class);
    $services->set(ArticleCreateTool::class);
    $services->set(ArticleUpdateTool::class);

    // Taxonomy tools
    $services->set(TagCreateTool::class);
    $services->set(TagListTool::class);
    $services->set(TagDeleteTool::class); // gated by dangerous_tools.delete
    $services->set(CategoryCreateTool::class);
    $services->set(CategoryListTool::class);
    $services->set(CategoryDeleteTool::class); // gated by dangerous_tools.delete

    // Media tools
    $services->set(MediaListTool::class);
    $services->set(MediaGetTool::class);
    $services->set(MediaUpdateTool::class);

    // Snippet tools
    $services->set(SnippetGetTool::class);
    $services->set(SnippetListTool::class);
    $services->set(SnippetCreateTool::class);
    $services->set(SnippetUpdateTool::class);

    // Contact tools
    $services->set(ContactListTool::class);

    // Navigation tools
    $services->set(NavigationGetTool::class);

    // Preview link tools
    $services->set(PreviewLinkGenerateTool::class);
    $services->set(PreviewLinkRevokeTool::class); // gated by dangerous_tools.publish
};
