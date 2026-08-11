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

namespace Sulu\Mcp\Application\Security;

use Sulu\Bundle\SecurityBundle\System\SystemStoreInterface;
use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Component\Security\Authorization\AccessControl\AccessControlRepositoryInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Reproduces PageDescendantSecurityListener: MCP dispatches
 * RemovePageMessage directly, bypassing the route listener that requires DELETE
 * on every descendant. This restores that gate.
 *
 * @internal
 */
final readonly class PageDescendantPermissionChecker
{
    /**
     * @param array<string, int> $permissions
     */
    public function __construct(
        private PageRepositoryInterface $pageRepository,
        private AccessControlRepositoryInterface $accessControlRepository,
        private SystemStoreInterface $systemStore,
        private ?Security $security,
        private array $permissions,
    ) {
    }

    /**
     * @throws PermissionDeniedException
     */
    public function assertCanDeleteDescendants(string $uuid): void
    {
        $user = $this->security?->getUser();
        if (!$user instanceof UserInterface) {
            throw new PermissionDeniedException('sulu.webspaces', PermissionTypes::DELETE);
        }

        $descendantIds = $this->pageRepository->findDescendantIdsById($uuid);
        if ([] === $descendantIds) {
            return;
        }

        $granted = $this->accessControlRepository->findIdsWithGrantedPermissions(
            $user,
            $this->permissions[PermissionTypes::DELETE],
            Page::class,
            $descendantIds,
            $this->systemStore->getSystem(),
            null,
        );

        if ([] !== \array_diff($descendantIds, $granted)) {
            throw new PermissionDeniedException('sulu.webspaces', PermissionTypes::DELETE);
        }
    }
}
