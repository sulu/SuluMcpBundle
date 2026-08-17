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

namespace Sulu\Mcp\Application\Metadata;

use Sulu\Component\Security\Authentication\UserInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Resolves the locale form metadata is translated into. Mirrors Sulu's own
 * admin behaviour, which reads the locale off the authenticated user, and
 * falls back to the project's configured locale when no Sulu user is present.
 *
 * @internal
 */
final readonly class MetadataLocaleResolver
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private string $fallbackLocale,
    ) {
    }

    public function resolve(): string
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        return $user instanceof UserInterface ? $user->getLocale() : $this->fallbackLocale;
    }
}
