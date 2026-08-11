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
use Sulu\Bundle\SecurityBundle\Entity\UserRole;
use Sulu\Bundle\SecurityBundle\System\SystemStoreInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;

/**
 * Two targeted rows verifying our layer supplies the correct locale and
 * system inputs to Sulu's real security stack. Sulu's own OR-combination of
 * permissions across multiple roles is Sulu's responsibility (already covered
 * by Sulu's own test suite) and is deliberately NOT re-tested here.
 */
#[CoversNothing]
final class SecurityBoundaryTest extends FunctionalTestCase
{
    public function testRoleValidForDifferentLocaleIsNotApplied(): void
    {
        $container = self::getContainer();
        $builder = new PermissionFixtureBuilder(
            $this->entityManager,
            $container->get('sulu_security.mask_converter'),
            $container->get('security.token_storage'),
            $container->get(SystemStoreInterface::class),
        );

        $role = $builder->role('GermanOnlyEditor', [
            'sulu.settings.tags' => [PermissionTypes::EDIT => true, PermissionTypes::ADD => true],
        ]);
        $user = $builder->user('german-editor', $role, locale: 'de');
        // Restricts the UserRole itself to German -- distinct from the user's default locale.
        foreach ($user->getUserRoles() as $userRole) {
            \assert($userRole instanceof UserRole);
            $userRole->setLocale(\json_encode(['de'], \JSON_THROW_ON_ERROR));
        }
        $this->entityManager->flush();
        $builder->authenticate($user);

        /** @var ToolPermissionCheckerInterface $checker */
        $checker = $container->get(ToolPermissionCheckerInterface::class);

        self::assertTrue(
            $checker->has('sulu.settings.tags', PermissionTypes::EDIT, 'de'),
            'The role must apply when the checked locale (de) matches the UserRole locale.',
        );
        self::assertFalse(
            $checker->has('sulu.settings.tags', PermissionTypes::EDIT, 'en'),
            'A role restricted to locale "de" must not apply when checking locale "en".',
        );
    }

    public function testRoleInForeignSystemIsIgnored(): void
    {
        $container = self::getContainer();
        $builder = new PermissionFixtureBuilder(
            $this->entityManager,
            $container->get('sulu_security.mask_converter'),
            $container->get('security.token_storage'),
            $container->get(SystemStoreInterface::class),
        );

        // Role registered under a different system (production sets this via OAuthSystemStoreListener).
        $role = $builder->role('ForeignSystemEditor', [
            'sulu.settings.tags' => [PermissionTypes::EDIT => true, PermissionTypes::ADD => true],
        ], system: 'OtherSystem');
        $user = $builder->user('foreign-system-editor', $role);
        $builder->authenticate($user); // sets SystemStore to Admin::SULU_ADMIN_SECURITY_SYSTEM ('Sulu')

        /** @var ToolPermissionCheckerInterface $checker */
        $checker = $container->get(ToolPermissionCheckerInterface::class);

        self::assertFalse(
            $checker->has('sulu.settings.tags', PermissionTypes::EDIT, 'en'),
            'A role registered under a different Sulu system must be ignored when SystemStore is set to "Sulu".',
        );

        // Positive control: re-authenticate under the role's own system
        // ('OtherSystem'), resetting SystemStore+token. If this also came back
        // false, the prior deny couldn't be attributed to the system filter alone.
        $builder->authenticate($user, 'OtherSystem');

        self::assertTrue(
            $checker->has('sulu.settings.tags', PermissionTypes::EDIT, 'en'),
            'The same role/user must grant EDIT once SystemStore matches the role\'s own system - '
            .'isolating the system filter as the sole cause of the earlier deny.',
        );
    }
}
