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

namespace Sulu\Mcp\Tests\Unit\Fixture;

use Sulu\Bundle\SecurityBundle\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Sulu's real user entity, with the identifier made settable -- Doctrine normally
 * assigns it, and the whole `UserInterface` (seven two-factor interfaces deep) is not
 * worth reimplementing to fake one getter.
 *
 * @internal
 */
final class TestUser extends User
{
    private int $testId;

    public function __construct(
        int $id = 1,
        string $locale = 'en',
        string $username = 'tester',
    ) {
        parent::__construct();

        $this->testId = $id;
        $this->setLocale($locale);
        $this->setUsername($username);
    }

    public function getId(): int
    {
        return $this->testId;
    }

    public function inTokenStorage(string $firewall = 'admin'): TokenStorageInterface
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($this, $firewall));

        return $tokenStorage;
    }
}
