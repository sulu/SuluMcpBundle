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
use Prophecy\Prophecy\ObjectProphecy;
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
    use ProphecyTrait;

    /** @var ObjectProphecy<PageRepositoryInterface> */
    private ObjectProphecy $pageRepository;
    /** @var ObjectProphecy<AccessControlRepositoryInterface> */
    private ObjectProphecy $accessControlRepository;
    /** @var ObjectProphecy<SystemStoreInterface> */
    private ObjectProphecy $systemStore;
    /** @var ObjectProphecy<Security> */
    private ObjectProphecy $security;
    private PageDescendantPermissionChecker $checker;

    protected function setUp(): void
    {
        $this->pageRepository = $this->prophesize(PageRepositoryInterface::class);
        $this->accessControlRepository = $this->prophesize(AccessControlRepositoryInterface::class);
        $this->systemStore = $this->prophesize(SystemStoreInterface::class);
        $this->systemStore->getSystem(Argument::cetera())->willReturn('Sulu');
        $this->security = $this->prophesize(Security::class);

        $this->checker = new PageDescendantPermissionChecker(
            $this->pageRepository->reveal(),
            $this->accessControlRepository->reveal(),
            $this->systemStore->reveal(),
            $this->security->reveal(),
            [PermissionTypes::DELETE => 8],
        );
    }

    public function testThrowsWhenNoUser(): void
    {
        $this->security->getUser(Argument::cetera())->willReturn(null);
        $this->pageRepository->findDescendantIdsById(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(PermissionDeniedException::class);

        $this->checker->assertCanDeleteDescendants('uuid-1');
    }

    public function testReturnsSilentlyWhenNoDescendants(): void
    {
        $user = $this->prophesize(UserInterface::class);
        $this->security->getUser(Argument::cetera())->willReturn($user->reveal());
        $this->pageRepository->findDescendantIdsById(Argument::cetera())->willReturn([]);
        $this->accessControlRepository->findIdsWithGrantedPermissions(Argument::cetera())->shouldNotBeCalled();

        $this->checker->assertCanDeleteDescendants('uuid-1');

        $this->addToAssertionCount(1);
    }

    public function testReturnsSilentlyWhenAllDescendantsGranted(): void
    {
        $user = $this->prophesize(UserInterface::class);
        $this->security->getUser(Argument::cetera())->willReturn($user->reveal());
        $this->pageRepository->findDescendantIdsById(Argument::cetera())->willReturn(['child-1', 'child-2']);
        $this->accessControlRepository->findIdsWithGrantedPermissions(Argument::cetera())->willReturn(['child-1', 'child-2']);

        $this->checker->assertCanDeleteDescendants('uuid-1');

        $this->addToAssertionCount(1);
    }

    public function testThrowsWhenSomeDescendantsNotGranted(): void
    {
        $user = $this->prophesize(UserInterface::class);
        $this->security->getUser(Argument::cetera())->willReturn($user->reveal());
        $this->pageRepository->findDescendantIdsById(Argument::cetera())->willReturn(['child-1', 'child-2']);
        $this->accessControlRepository->findIdsWithGrantedPermissions(Argument::cetera())->willReturn(['child-1']);

        $this->expectException(PermissionDeniedException::class);

        $this->checker->assertCanDeleteDescendants('uuid-1');
    }
}
