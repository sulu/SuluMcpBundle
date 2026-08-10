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

use Doctrine\ORM\EntityManagerInterface;
use Sulu\Bundle\AdminBundle\Admin\Admin;
use Sulu\Bundle\SecurityBundle\Entity\AccessControl;
use Sulu\Bundle\SecurityBundle\Entity\Permission;
use Sulu\Bundle\SecurityBundle\Entity\Role;
use Sulu\Bundle\SecurityBundle\Entity\User;
use Sulu\Bundle\SecurityBundle\Entity\UserRole;
use Sulu\Bundle\SecurityBundle\System\SystemStoreInterface;
use Sulu\Component\Security\Authorization\MaskConverterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Fluent fixture builder for the dev functional permission suite, extracted from
 * PermissionAclSmokeTest so every tier reuses one seeding path. Adds no new
 * schema -- the security tables (User/Role/UserRole/Permission/AccessControl)
 * already exist in the real MySQL schema created by
 * "composer bootstrap-test-environment".
 */
final readonly class PermissionFixtureBuilder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MaskConverterInterface $maskConverter,
        private TokenStorageInterface $tokenStorage,
        private SystemStoreInterface $systemStore,
    ) {
    }

    /**
     * @param array<string, array<string, bool>> $contextMasks security context => [PermissionTypes::X => bool]
     */
    public function role(string $name, array $contextMasks, string $system = Admin::SULU_ADMIN_SECURITY_SYSTEM): Role
    {
        $role = new Role();
        $role->setName($name);
        $role->setSystem($system);
        $this->entityManager->persist($role);

        foreach ($contextMasks as $context => $mask) {
            $permission = new Permission();
            $permission->setContext($context);
            $permission->setPermissions($this->maskConverter->convertPermissionsToNumber($mask));
            $permission->setRole($role);
            $role->addPermission($permission);
            $this->entityManager->persist($permission);
        }

        $this->entityManager->flush();

        return $role;
    }

    /**
     * @param array<string, bool> $mask
     */
    public function objectAcl(string $entityClass, string|int $entityId, Role $role, array $mask): AccessControl
    {
        $accessControl = new AccessControl();
        $accessControl->setEntityClass($entityClass);
        $accessControl->setEntityId((string) $entityId);
        $accessControl->setRole($role);
        $accessControl->setPermissions($this->maskConverter->convertPermissionsToNumber($mask));

        $this->entityManager->persist($accessControl);
        $this->entityManager->flush();

        return $accessControl;
    }

    public function user(string $username, Role $role, string $locale = 'en'): User
    {
        $user = new User();
        $user->setUsername($username);
        $user->setPassword('not-used-in-this-test');
        $user->setSalt('');
        $user->setLocale($locale);

        $userRole = new UserRole();
        $userRole->setUser($user);
        $userRole->setRole($role);
        $userRole->setLocale(\json_encode([$locale], \JSON_THROW_ON_ERROR));
        $user->addUserRole($userRole);

        $this->entityManager->persist($user);
        $this->entityManager->persist($userRole);
        $this->entityManager->flush();

        return $user;
    }

    public function authenticate(User $user, string $system = Admin::SULU_ADMIN_SECURITY_SYSTEM): void
    {
        $this->systemStore->setSystem($system);
        $this->tokenStorage->setToken(new UsernamePasswordToken($user, 'admin', $user->getRoles()));
    }
}
