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

namespace Sulu\Mcp\Tests\Unit\Application\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Mcp\Application\Security\AccessControlFilterFactory;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface as CoreUserInterface;

#[CoversClass(AccessControlFilterFactory::class)]
final class AccessControlFilterFactoryTest extends TestCase
{
    private const PERMISSIONS = [
        'view' => 64,
        'add' => 32,
        'edit' => 16,
        'delete' => 8,
        'archive' => 4,
        'live' => 2,
        'security' => 1,
    ];

    public function testForPermissionReturnsUserAndBitmaskWhenAuthenticated(): void
    {
        $user = $this->createMock(UserInterface::class);
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $factory = new AccessControlFilterFactory($security, self::PERMISSIONS);

        self::assertSame(['user' => $user, 'permission' => 64], $factory->forPermission('view'));
    }

    public function testForPermissionReturnsNullUserWhenSecurityHasNoUser(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);

        $factory = new AccessControlFilterFactory($security, self::PERMISSIONS);

        self::assertSame(['user' => null, 'permission' => 16], $factory->forPermission('edit'));
    }

    public function testForPermissionReturnsNullUserWhenSecurityHelperIsNull(): void
    {
        $factory = new AccessControlFilterFactory(null, self::PERMISSIONS);

        self::assertSame(['user' => null, 'permission' => 64], $factory->forPermission('view'));
    }

    public function testForPermissionTreatsNonSuluUserAsNull(): void
    {
        // Symfony's core UserInterface can be satisfied by a user that is not a Sulu
        // UserInterface (e.g. an OAuth-only identity). Without the instanceof guard
        // this would leak a foreign user object into the accessControl filter.
        $foreignUser = $this->createMock(CoreUserInterface::class);
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($foreignUser);

        $factory = new AccessControlFilterFactory($security, self::PERMISSIONS);

        self::assertSame(['user' => null, 'permission' => 64], $factory->forPermission('view'));
    }

    public function testForPermissionDefaultsToZeroForUnknownPermissionType(): void
    {
        $factory = new AccessControlFilterFactory(null, self::PERMISSIONS);

        self::assertSame(['user' => null, 'permission' => 0], $factory->forPermission('unknown'));
    }
}
