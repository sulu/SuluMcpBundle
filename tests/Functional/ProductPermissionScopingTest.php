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

namespace Sulu\Mcp\Tests\Functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Sulu\Bundle\SecurityBundle\System\SystemStoreInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\UserInterface\Mcp\Tool\GetContextTool;

#[CoversNothing]
#[Group('product')]
final class ProductPermissionScopingTest extends FunctionalTestCase
{
    /**
     * @var list<string>
     */
    private const PRODUCT_TOOLS = [
        'sulu_product_get',
        'sulu_product_list',
        'sulu_product_create',
        'sulu_product_update',
        'sulu_product_variant_list',
        'sulu_product_variant_create',
        'sulu_product_variant_update',
        'sulu_product_family_list',
        'sulu_attribute_list',
    ];

    public function testViewOnlyRoleSeesProductReadToolsButNotWriteTools(): void
    {
        $container = self::getContainer();
        $container->get('mcp.server.sulu'); // populates the registry -- must run first

        $builder = new PermissionFixtureBuilder(
            $this->entityManager,
            $container->get('sulu_security.mask_converter'),
            $container->get('security.token_storage'),
            $container->get(SystemStoreInterface::class),
        );

        $role = $builder->role('ProductViewer', [
            'sulu.product.products' => [
                PermissionTypes::VIEW => true,
                PermissionTypes::ADD => false,
                PermissionTypes::EDIT => false,
            ],
            'sulu.product.attributes' => [PermissionTypes::VIEW => true],
            'sulu.product.product_families' => [PermissionTypes::VIEW => false],
        ]);
        $builder->authenticate($builder->user('product-viewer', $role));

        /** @var GetContextTool $getContextTool */
        $getContextTool = $container->get(GetContextTool::class);
        $context = $getContextTool->getContext();

        $byName = \array_column($context['tools'], null, 'name');

        foreach (self::PRODUCT_TOOLS as $name) {
            self::assertArrayHasKey($name, $byName, \sprintf('%s must be advertised in the tool catalogue.', $name));
        }

        self::assertTrue($byName['sulu_product_get']['available']);
        self::assertTrue($byName['sulu_product_list']['available']);
        self::assertTrue($byName['sulu_product_variant_list']['available']);
        self::assertTrue($byName['sulu_attribute_list']['available']);

        self::assertFalse($byName['sulu_product_create']['available']);
        self::assertNotEmpty($byName['sulu_product_create']['reason']);
        self::assertFalse($byName['sulu_product_update']['available']);
        self::assertFalse($byName['sulu_product_variant_create']['available']);
        self::assertFalse($byName['sulu_product_variant_update']['available']);

        self::assertFalse(
            $byName['sulu_product_family_list']['available'],
            'Families sit behind sulu.product.product_families, so product permissions alone must not unlock them.',
        );
    }

    public function testProductOnlyRoleCanReachTheUnifiedContentAndBlockTools(): void
    {
        $container = self::getContainer();
        $container->get('mcp.server.sulu');

        $builder = new PermissionFixtureBuilder(
            $this->entityManager,
            $container->get('sulu_security.mask_converter'),
            $container->get('security.token_storage'),
            $container->get(SystemStoreInterface::class),
        );

        $role = $builder->role('ProductOnly', [
            'sulu.product.products' => [
                PermissionTypes::VIEW => true,
                PermissionTypes::ADD => true,
                PermissionTypes::EDIT => true,
                PermissionTypes::DELETE => true,
                PermissionTypes::LIVE => true,
            ],
        ]);
        $builder->authenticate($builder->user('product-only', $role));

        /** @var GetContextTool $getContextTool */
        $getContextTool = $container->get(GetContextTool::class);
        $byName = \array_column($getContextTool->getContext()['tools'], null, 'name');

        foreach (['sulu_content_publish', 'sulu_content_unpublish', 'sulu_content_delete', 'sulu_block_add', 'sulu_block_update', 'sulu_block_remove', 'sulu_block_reorder', 'sulu_block_list'] as $name) {
            self::assertTrue(
                $byName[$name]['available'],
                \sprintf('%s reaches its in-body product check only if a product context is one of its discovery candidates; otherwise coarseDenial() rejects the call first.', $name),
            );
        }
    }

    public function testRoleWithoutProductPermissionsSeesNoProductToolAsAvailable(): void
    {
        $container = self::getContainer();
        $container->get('mcp.server.sulu');

        $builder = new PermissionFixtureBuilder(
            $this->entityManager,
            $container->get('sulu_security.mask_converter'),
            $container->get('security.token_storage'),
            $container->get(SystemStoreInterface::class),
        );

        $role = $builder->role('NoProducts', [
            'sulu.settings.tags' => [PermissionTypes::VIEW => true],
        ]);
        $builder->authenticate($builder->user('no-products', $role));

        /** @var GetContextTool $getContextTool */
        $getContextTool = $container->get(GetContextTool::class);
        $byName = \array_column($getContextTool->getContext()['tools'], null, 'name');

        foreach (self::PRODUCT_TOOLS as $name) {
            self::assertFalse($byName[$name]['available'], \sprintf('%s must be unavailable without product permissions.', $name));
        }
    }
}
