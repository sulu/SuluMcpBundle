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

namespace Sulu\Mcp\Infrastructure\Sulu\OAuth;

use League\OAuth2\Server\Entities\UserEntityInterface;
use Sulu\Bundle\SecurityBundle\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Maps authenticated Sulu admin users to OAuth user entities during authorization.
 *
 * The username is used as the OAuth identifier so that Sulu's user provider
 * can reload the user from the JWT sub claim via loadUserByIdentifier().
 *
 * @internal
 */
class SuluUserResolver
{
    /**
     * Resolves a Sulu User from a Symfony security token.
     *
     * Used during OAuth authorization to map the authenticated admin user
     * to an OAuth user entity. The identifier is the Sulu username so that
     * Sulu's user provider can load it back via loadUserByIdentifier().
     */
    public function resolveFromSecurityToken(TokenInterface $token): UserEntityInterface
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            throw new \RuntimeException('Expected Sulu User entity, got '.($user instanceof UserInterface ? $user::class : self::class));
        }

        return new SuluOAuthUser($user->getUserIdentifier());
    }
}
