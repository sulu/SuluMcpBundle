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
use Sulu\Bundle\SecurityBundle\System\SystemStoreInterface;
use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Component\Security\Authorization\AccessControl\AccessControlRepositoryInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Application\Security\PageDescendantPermissionChecker;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Symfony\Bundle\SecurityBundle\Security;

#[CoversClass(PageDescendantPermissionChecker::class)]
final class PageDescendantPermissionCheckerTest extends TestCase
{
    private PageRepositoryInterface&MockObject $pageRepository;
    private AccessControlRepositoryInterface&MockObject $accessControlRepository;
    private SystemStoreInterface&MockObject $systemStore;
    private Security&MockObject $security;
    private PageDescendantPermissionChecker $checker;

    protected function setUp(): void
    {
        $this->pageRepository = $this->createMock(PageRepositoryInterface::class);
        $this->accessControlRepository = $this->createMock(AccessControlRepositoryInterface::class);
        $this->systemStore = $this->createMock(SystemStoreInterface::class);
        $this->systemStore->method('getSystem')->willReturn('Sulu');
        $this->security = $this->createMock(Security::class);

        $this->checker = new PageDescendantPermissionChecker(
            $this->pageRepository,
            $this->accessControlRepository,
            $this->systemStore,
            $this->security,
            [PermissionTypes::DELETE => 8],
        );
    }

    public function testThrowsWhenNoUser(): void
    {
        $this->security->method('getUser')->willReturn(null);
        $this->pageRepository->expects($this->never())->method('findDescendantIdsById');

        $this->expectException(PermissionDeniedException::class);

        $this->checker->assertCanDeleteDescendants('uuid-1');
    }

    public function testReturnsSilentlyWhenNoDescendants(): void
    {
        $user = $this->createMock(UserInterface::class);
        $this->security->method('getUser')->willReturn($user);
        $this->pageRepository->method('findDescendantIdsById')->willReturn([]);
        $this->accessControlRepository->expects($this->never())->method('findIdsWithGrantedPermissions');

        $this->checker->assertCanDeleteDescendants('uuid-1');

        $this->addToAssertionCount(1);
    }

    public function testReturnsSilentlyWhenAllDescendantsGranted(): void
    {
        $user = $this->createMock(UserInterface::class);
        $this->security->method('getUser')->willReturn($user);
        $this->pageRepository->method('findDescendantIdsById')->willReturn(['child-1', 'child-2']);
        $this->accessControlRepository->method('findIdsWithGrantedPermissions')->willReturn(['child-1', 'child-2']);

        $this->checker->assertCanDeleteDescendants('uuid-1');

        $this->addToAssertionCount(1);
    }

    public function testThrowsWhenSomeDescendantsNotGranted(): void
    {
        $user = $this->createMock(UserInterface::class);
        $this->security->method('getUser')->willReturn($user);
        $this->pageRepository->method('findDescendantIdsById')->willReturn(['child-1', 'child-2']);
        $this->accessControlRepository->method('findIdsWithGrantedPermissions')->willReturn(['child-1']);

        $this->expectException(PermissionDeniedException::class);

        $this->checker->assertCanDeleteDescendants('uuid-1');
    }
}
