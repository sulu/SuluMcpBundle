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

use Sulu\Mcp\Application\Content\ContentTypeResolver;
use Sulu\Mcp\Application\Product\VariantParentResolver;
use Sulu\Mcp\Infrastructure\Sulu\AdminLink\ProductAdminLinkProvider;
use Sulu\Mcp\Infrastructure\Sulu\AdminLink\ProductVariantAdminLinkProvider;
use Sulu\Mcp\UserInterface\Mcp\Tool\Product\AttributeListTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Product\ProductCreateTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Product\ProductFamilyListTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Product\ProductGetTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Product\ProductListTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Product\ProductUpdateTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Product\ProductVariantCreateTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Product\ProductVariantListTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Product\ProductVariantUpdateTool;
use Sulu\Product\Domain\Repository\ProductRepositoryInterface;
use Symfony\Component\DependencyInjection\Reference;

/*
 * Imported by SuluMcpBundle only when SuluProductBundle is registered.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure();

    $services->set(ContentTypeResolver::class)
        ->arg('$productRepository', new Reference(ProductRepositoryInterface::class));

    $services->set(VariantParentResolver::class);

    // The admin_link_provider tag is set explicitly: the instanceof rule in
    // services.php is file-scoped and does not reach this file.
    foreach ([ProductAdminLinkProvider::class, ProductVariantAdminLinkProvider::class] as $adminLinkProvider) {
        $services->set($adminLinkProvider)
            ->arg('$viewRegistry', new Reference('sulu_admin.view_registry'))
            ->tag('sulu_mcp.admin_link_provider')
            ->tag('sulu.context', ['context' => 'admin']);
    }

    $services->set(ProductGetTool::class);
    $services->set(ProductListTool::class);
    $services->set(ProductCreateTool::class);
    $services->set(ProductUpdateTool::class);

    $services->set(ProductVariantListTool::class);
    $services->set(ProductVariantCreateTool::class);
    $services->set(ProductVariantUpdateTool::class);

    $services->set(ProductFamilyListTool::class);
    $services->set(AttributeListTool::class);
};
