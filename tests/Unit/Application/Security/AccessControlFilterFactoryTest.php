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
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Mcp\Application\Security\AccessControlFilterFactory;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface as CoreUserInterface;

#[CoversClass(AccessControlFilterFactory::class)]
final class AccessControlFilterFactoryTest extends TestCase
{
    use ProphecyTrait;

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
        $user = $this->prophesize(UserInterface::class);
        $security = $this->prophesize(Security::class);
        $security->getUser(Argument::cetera())->willReturn($user->reveal());

        $factory = new AccessControlFilterFactory($security->reveal(), self::PERMISSIONS);

        self::assertSame(['user' => $user->reveal(), 'permission' => 64], $factory->forPermission('view'));
    }

    public function testForPermissionReturnsNullUserWhenSecurityHasNoUser(): void
    {
        $security = $this->prophesize(Security::class);
        $security->getUser(Argument::cetera())->willReturn(null);

        $factory = new AccessControlFilterFactory($security->reveal(), self::PERMISSIONS);

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
        $foreignUser = $this->prophesize(CoreUserInterface::class);
        $security = $this->prophesize(Security::class);
        $security->getUser(Argument::cetera())->willReturn($foreignUser->reveal());

        $factory = new AccessControlFilterFactory($security->reveal(), self::PERMISSIONS);

        self::assertSame(['user' => null, 'permission' => 64], $factory->forPermission('view'));
    }

    public function testForPermissionDefaultsToZeroForUnknownPermissionType(): void
    {
        $factory = new AccessControlFilterFactory(null, self::PERMISSIONS);

        self::assertSame(['user' => null, 'permission' => 0], $factory->forPermission('unknown'));
    }
}
