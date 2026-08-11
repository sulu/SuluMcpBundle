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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Mcp\Application\Security\ToolPermissionChecker;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

#[CoversClass(ToolPermissionChecker::class)]
final class ToolPermissionCheckerTest extends TestCase
{
    private SecurityCheckerInterface&MockObject $securityChecker;
    private TokenStorageInterface&MockObject $tokenStorage;
    private ToolPermissionChecker $checker;

    protected function setUp(): void
    {
        $this->securityChecker = $this->createMock(SecurityCheckerInterface::class);
        $this->tokenStorage = $this->createMock(TokenStorageInterface::class);
        $this->checker = new ToolPermissionChecker($this->securityChecker, $this->tokenStorage);
        $this->authenticate();
    }

    private function authenticate(): void
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($this->createMock(UserInterface::class));
        $this->tokenStorage->method('getToken')->willReturn($token);
    }

    public function testCheckPassesWhenGranted(): void
    {
        $this->securityChecker->method('hasPermission')->willReturn(true);
        $this->checker->check('sulu.settings.tags', PermissionTypes::ADD);
        $this->addToAssertionCount(1);
    }

    public function testCheckThrowsWhenDenied(): void
    {
        $this->securityChecker->method('hasPermission')->willReturn(false);
        $this->expectException(PermissionDeniedException::class);
        $this->checker->check('sulu.settings.tags', PermissionTypes::ADD, 'en');
    }

    public function testCheckPassesWhenAllListedPermissionsGranted(): void
    {
        $this->securityChecker->method('hasPermission')->willReturn(true);
        $this->checker->check('sulu.settings.tags', [PermissionTypes::EDIT, PermissionTypes::ADD]);
        $this->addToAssertionCount(1);
    }

    public function testCheckThrowsWhenOneListedPermissionIsDenied(): void
    {
        $this->securityChecker->method('hasPermission')->willReturnCallback(
            static fn ($condition, string $permission): bool => PermissionTypes::ADD !== $permission,
        );

        try {
            $this->checker->check('sulu.settings.tags', [PermissionTypes::EDIT, PermissionTypes::ADD], 'en');
            self::fail('Expected PermissionDeniedException');
        } catch (PermissionDeniedException $e) {
            self::assertStringContainsString(PermissionTypes::ADD, $e->getMessage());
        }
    }

    public function testDeniesEmptyContextWithoutCallingSulu(): void
    {
        $this->securityChecker->expects(self::never())->method('hasPermission');
        self::assertFalse($this->checker->has('', PermissionTypes::VIEW));
    }

    public function testDeniesUnsubstitutedPlaceholderContext(): void
    {
        $this->securityChecker->expects(self::never())->method('hasPermission');
        self::assertFalse($this->checker->has('sulu.webspaces.#context#', PermissionTypes::EDIT));
    }

    public function testDeniesWhenNoAuthenticatedUser(): void
    {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);
        $checker = new ToolPermissionChecker($this->securityChecker, $tokenStorage);
        $this->securityChecker->expects(self::never())->method('hasPermission');
        self::assertFalse($checker->has('sulu.settings.tags', PermissionTypes::VIEW));
    }
}
