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

use PHPUnit\Framework\Attributes\CoversClass;
use Sulu\Bundle\AdminBundle\Admin\Admin;
use Sulu\Bundle\SecurityBundle\System\SystemStoreInterface;
use Sulu\Component\Security\Authorization\MaskConverterInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Security\Authorization\SecurityCondition;
use Sulu\Mcp\Application\Security\ToolPermissionChecker;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Model\PageInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Functional smoke test for the object-level (per-page) ACL layer, against
 * Sulu's real SecurityChecker/AccessControlManager. Pins a real bypass: using
 * abstract `PageInterface::class` instead of concrete `Page::class` as the ACL
 * object type silently drops the per-page lookup and falls back to the grant.
 *
 * @see ToolPermissionChecker
 */
#[CoversClass(ToolPermissionChecker::class)]
final class PermissionAclSmokeTest extends FunctionalTestCase
{
    private const WEBSPACE_KEY = 'website';
    private const LOCALE = 'en';
    private const PAGE_UUID = '11111111-1111-1111-1111-111111111111';

    public function testPageLevelAclDenyOverridesWebspaceGrantOnlyWithConcretePageClass(): void
    {
        $container = self::getContainer();

        /** @var SecurityCheckerInterface $securityChecker */
        $securityChecker = $container->get(SecurityCheckerInterface::class);
        /** @var MaskConverterInterface $maskConverter */
        $maskConverter = $container->get('sulu_security.mask_converter');
        /** @var TokenStorageInterface $tokenStorage */
        $tokenStorage = $container->get('security.token_storage');
        /** @var SystemStoreInterface $systemStore */
        $systemStore = $container->get(SystemStoreInterface::class);

        // Production sets this via SystemListener on kernel.request; set directly here.
        $systemStore->setSystem(Admin::SULU_ADMIN_SECURITY_SYSTEM);

        $builder = new PermissionFixtureBuilder($this->entityManager, $maskConverter, $tokenStorage, $systemStore);

        // Webspace-level EDIT grant, plus everything else, to keep the "context"
        // side of the check unambiguous.
        $role = $builder->role('Editor', [
            'sulu.webspaces.'.self::WEBSPACE_KEY => [
                PermissionTypes::VIEW => true,
                PermissionTypes::ADD => true,
                PermissionTypes::EDIT => true,
                PermissionTypes::DELETE => true,
                PermissionTypes::ARCHIVE => true,
                PermissionTypes::LIVE => true,
                PermissionTypes::SECURITY => true,
            ],
        ]);

        // Deny EDIT on one specific page, keyed under the concrete Page class --
        // how Sulu actually stores per-page ACLs (PageController::getSecuredClass()).
        $builder->objectAcl(Page::class, self::PAGE_UUID, $role, [
            PermissionTypes::VIEW => true,
            PermissionTypes::ADD => false,
            PermissionTypes::EDIT => false,
            PermissionTypes::DELETE => false,
            PermissionTypes::ARCHIVE => false,
            PermissionTypes::LIVE => false,
            PermissionTypes::SECURITY => false,
        ]);

        $user = $builder->user('editor', $role, self::LOCALE);

        $builder->authenticate($user);

        // Real SecurityChecker, concrete Page::class: the per-page deny must
        // win over the webspace-level grant.
        $condition = new SecurityCondition(
            'sulu.webspaces.'.self::WEBSPACE_KEY,
            self::LOCALE,
            Page::class,
            self::PAGE_UUID,
        );

        self::assertFalse(
            $securityChecker->hasPermission($condition, PermissionTypes::EDIT),
            'Per-page ACL deny must override the webspace-level EDIT grant when the concrete Page class is used.',
        );

        // Regression pin: the fixed bug used PageInterface::class here. No
        // AccessControl row is ever stored under the interface string, so the
        // lookup finds nothing and silently falls back to the webspace grant,
        // hiding the deny -- this assertion catches a regression back to it.
        $bypassCondition = new SecurityCondition(
            'sulu.webspaces.'.self::WEBSPACE_KEY,
            self::LOCALE,
            PageInterface::class,
            self::PAGE_UUID,
        );

        self::assertTrue(
            $securityChecker->hasPermission($bypassCondition, PermissionTypes::EDIT),
            'Using PageInterface::class must find no matching ACL row and fall back to the webspace grant - '
            .'this is exactly the bypass the Page::class fix closed.',
        );

        // The bundle's own wrapper (what tools actually call) denies too,
        // using the concrete Page class like production code.
        /** @var ToolPermissionCheckerInterface $toolPermissionChecker */
        $toolPermissionChecker = $container->get(ToolPermissionCheckerInterface::class);

        self::assertFalse(
            $toolPermissionChecker->has(
                'sulu.webspaces.'.self::WEBSPACE_KEY,
                PermissionTypes::EDIT,
                self::LOCALE,
                Page::class,
                self::PAGE_UUID,
            ),
            'ToolPermissionChecker must deny too - it delegates straight to the SecurityChecker assertion above.',
        );
    }
}
