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

use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\UserEntityInterface;

/**
 * Minimal OAuth user entity that bridges League OAuth2 Server with Sulu.
 *
 * The identifier is the Sulu user ID, which allows resolving the full
 * Sulu User entity later for permission checks.
 *
 * @internal
 */
class SuluOAuthUser implements UserEntityInterface
{
    use EntityTrait;

    public function __construct(string $identifier)
    {
        if ('' === $identifier) {
            throw new \InvalidArgumentException('SuluOAuthUser identifier must not be empty.');
        }

        $this->setIdentifier($identifier);
    }
}
