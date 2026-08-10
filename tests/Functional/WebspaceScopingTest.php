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

use PHPUnit\Framework\Attributes\CoversNothing;
use Sulu\Bundle\McpBundle\Security\Permission\ToolPermissionCheckerInterface;
use Sulu\Bundle\McpBundle\Security\Permission\WebspacePermissionResolver;
use Sulu\Bundle\SecurityBundle\System\SystemStoreInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;

/**
 * Single-webspace scoping. Requires a second configured
 * webspace (config/webspaces/intranet.xml) -- with only "website"
 * configured, permittedWebspaceKeys() == ['website'] would hold even if the
 * resolver never filtered anything, so it wouldn't prove exclusion.
 */
#[CoversNothing]
final class WebspaceScopingTest extends FunctionalTestCase
{
    public function testWebspacePermissionResolverExcludesUngrantedWebspace(): void
    {
        $container = self::getContainer();

        /** @var WebspaceManagerInterface $webspaceManager */
        $webspaceManager = $container->get(WebspaceManagerInterface::class);
        $keys = [];
        foreach ($webspaceManager->getWebspaceCollection() as $webspace) {
            $keys[] = $webspace->getKey();
        }
        \sort($keys);
        self::assertSame(['intranet', 'website'], $keys, 'Expected both dev webspaces to be configured.');

        $builder = new PermissionFixtureBuilder(
            $this->entityManager,
            $container->get('sulu_security.mask_converter'),
            $container->get('security.token_storage'),
            $container->get(SystemStoreInterface::class),
        );
        $role = $builder->role('WebsiteOnlyEditor', [
            'sulu.webspaces.website' => [
                PermissionTypes::VIEW => true, PermissionTypes::ADD => true, PermissionTypes::EDIT => true,
                PermissionTypes::DELETE => true, PermissionTypes::ARCHIVE => true, PermissionTypes::LIVE => true,
                PermissionTypes::SECURITY => true,
            ],
        ]);
        $user = $builder->user('website-only-editor', $role);
        $builder->authenticate($user);

        /** @var WebspacePermissionResolver $resolver */
        $resolver = $container->get(WebspacePermissionResolver::class);
        self::assertSame(['website'], $resolver->permittedWebspaceKeys(PermissionTypes::EDIT, 'en'));

        /** @var ToolPermissionCheckerInterface $checker */
        $checker = $container->get(ToolPermissionCheckerInterface::class);
        self::assertFalse($checker->has('sulu.webspaces.intranet', PermissionTypes::EDIT, 'en'));
    }
}
