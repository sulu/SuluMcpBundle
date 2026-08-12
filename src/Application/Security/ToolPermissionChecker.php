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

use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Security\Authorization\SecurityCondition;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Wraps Sulu's SecurityChecker with fail-CLOSED preconditions. Sulu's checker
 * grants access for an empty subject or an inactive firewall; this
 * class denies in those cases and only delegates once a real context and an
 * authenticated Sulu user are present.
 *
 * @internal
 */
final readonly class ToolPermissionChecker implements ToolPermissionCheckerInterface
{
    public function __construct(
        private SecurityCheckerInterface $securityChecker,
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    /**
     * @param string|list<string> $permissions every listed permission must be granted
     *
     * @throws PermissionDeniedException
     */
    public function check(
        string $context,
        string|array $permissions,
        ?string $locale = null,
        ?string $objectType = null,
        mixed $objectId = null,
    ): void {
        foreach ((array) $permissions as $permission) {
            if (!$this->has($context, $permission, $locale, $objectType, $objectId)) {
                throw new PermissionDeniedException($context, $permission, $locale);
            }
        }
    }

    public function has(
        string $context,
        string $permission,
        ?string $locale = null,
        ?string $objectType = null,
        mixed $objectId = null,
    ): bool {
        if ('' === $context || \str_contains($context, '#')) {
            return false;
        }

        $token = $this->tokenStorage->getToken();
        if (!$token instanceof TokenInterface || !$token->getUser() instanceof UserInterface) {
            return false;
        }

        return $this->securityChecker->hasPermission(
            new SecurityCondition($context, $locale, $objectType, $objectId),
            $permission,
        );
    }
}
