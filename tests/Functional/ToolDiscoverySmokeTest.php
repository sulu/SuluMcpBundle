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

namespace Sulu\Bundle\McpBundle\Tests\Functional;

use Mcp\Capability\RegistryInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use Sulu\Bundle\McpBundle\Capabilities\Tool\GetContextTool;
use Sulu\Bundle\SecurityBundle\System\SystemStoreInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;

/**
 * Discovery visibility against the real compiled map, for a real
 * view-only role. Requires mcp.server initialized first, else
 * getTools() assertions are meaningless-green. Also grants a real,
 * non-allowlisted permission to prove availability tracks real grants.
 */
#[CoversNothing]
final class ToolDiscoverySmokeTest extends FunctionalTestCase
{
    public function testViewOnlyRoleSeesDeniedToolsMarkedUnavailableWithReason(): void
    {
        $container = self::getContainer();
        $container->get('mcp.server'); // populates the registry -- must run first

        $builder = new PermissionFixtureBuilder(
            $this->entityManager,
            $container->get('sulu_security.mask_converter'),
            $container->get('security.token_storage'),
            $container->get(SystemStoreInterface::class),
        );
        $role = $builder->role('ViewOnlyDiscovery', [
            'sulu.webspaces.website' => [PermissionTypes::VIEW => true, PermissionTypes::EDIT => false],
            // sulu_category_list requires only VIEW on sulu.settings.categories and is
            // NOT allowlisted, isolating "tracks a real grant" from "allowlist always wins".
            'sulu.settings.categories' => [PermissionTypes::VIEW => true],
        ]);
        $user = $builder->user('view-only-discovery', $role);
        $builder->authenticate($user);

        /** @var GetContextTool $getContextTool */
        $getContextTool = $container->get(GetContextTool::class);
        $context = $getContextTool->getContext();

        self::assertArrayHasKey('tools', $context);
        $byName = \array_column($context['tools'], null, 'name');

        self::assertFalse($byName['sulu_page_create']['available']);
        self::assertNotEmpty($byName['sulu_page_create']['reason']);
        self::assertTrue($byName['sulu_ping']['available']);
        self::assertTrue($byName['sulu_get_context']['available']);
        self::assertTrue(
            $byName['sulu_category_list']['available'],
            'sulu_category_list requires only VIEW on sulu.settings.categories, which this role has -- '
            .'must be available, proving availability tracks real grants, not just the allowlist.',
        );

        /** @var RegistryInterface $registry */
        $registry = $container->get('mcp.registry');
        // Page::$references is the public readonly property FilteredRegistry reads
        // off the inner registry to build the filtered set (FilteredRegistry.php:124)
        // -- same name=>Tool map as getArrayCopy(), without the extra call.
        $filteredNames = \array_keys($registry->getTools(null, null)->references);

        self::assertNotContains('sulu_page_create', $filteredNames, 'Denied tool must be omitted from tools/list.');
        self::assertContains('sulu_ping', $filteredNames, 'Allowlisted tool must be present.');
        self::assertContains(
            'sulu_category_list',
            $filteredNames,
            'Genuinely granted, non-allowlisted tool must be present in the filtered tools/list.',
        );
    }

    /**
     * ANY_WEBSPACE_CONTEXT sentinel for an objectResolved tool (sulu_page_get):
     * visible once VIEW is granted on ANY declared webspace ('intranet' here,
     * out of 'website'/'intranet') -- discovery errs toward showing, the
     * in-body check decides. Ported from the former Integration suite's
     * PermissionEnforcementTest.
     */
    public function testAnyWebspaceSentinelToolVisibleWhenGrantedOnOneOfTwoWebspaces(): void
    {
        $container = self::getContainer();
        $container->get('mcp.server'); // populates the registry -- must run first

        $builder = new PermissionFixtureBuilder(
            $this->entityManager,
            $container->get('sulu_security.mask_converter'),
            $container->get('security.token_storage'),
            $container->get(SystemStoreInterface::class),
        );
        $role = $builder->role('IntranetViewer', [
            'sulu.webspaces.intranet' => [PermissionTypes::VIEW => true],
        ]);
        $user = $builder->user('intranet-viewer', $role);
        $builder->authenticate($user);

        /** @var GetContextTool $getContextTool */
        $getContextTool = $container->get(GetContextTool::class);
        $context = $getContextTool->getContext();
        $byName = \array_column($context['tools'], null, 'name');

        self::assertTrue(
            $byName['sulu_page_get']['available'],
            'VIEW on ANY declared webspace must make an ANY_WEBSPACE_CONTEXT-sentinel tool visible.',
        );
    }

    /**
     * Negative counterpart: no VIEW grant on any webspace at all hides the
     * same sentinel-gated tool, proving the visible case above tracks a real
     * grant rather than always showing.
     */
    public function testAnyWebspaceSentinelToolHiddenWhenGrantedOnNoWebspace(): void
    {
        $container = self::getContainer();
        $container->get('mcp.server'); // populates the registry -- must run first

        $builder = new PermissionFixtureBuilder(
            $this->entityManager,
            $container->get('sulu_security.mask_converter'),
            $container->get('security.token_storage'),
            $container->get(SystemStoreInterface::class),
        );
        $role = $builder->role('NoWebspaceGrant', []);
        $user = $builder->user('no-webspace-grant', $role);
        $builder->authenticate($user);

        /** @var GetContextTool $getContextTool */
        $getContextTool = $container->get(GetContextTool::class);
        $context = $getContextTool->getContext();
        $byName = \array_column($context['tools'], null, 'name');

        self::assertFalse($byName['sulu_page_get']['available']);
    }
}
