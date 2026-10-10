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

namespace Sulu\Mcp\Tests\Functional;

use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Repository\ClientRepository;
use PHPUnit\Framework\Attributes\CoversNothing;
use Sulu\Mcp\Infrastructure\League\Manager\CimdClientManager;

/**
 * League resolves clients through its ClientRepository; the CIMD decorator only counts if
 * that is what the repository is handed.
 */
#[CoversNothing]
final class CimdWiringTest extends FunctionalTestCase
{
    public function testTheClientManagerIsDecoratedForCimd(): void
    {
        $manager = self::getContainer()->get(ClientManagerInterface::class);

        self::assertInstanceOf(CimdClientManager::class, $manager);
    }

    public function testTheOAuthRepositoryUsesTheDecoratedManager(): void
    {
        $repository = self::getContainer()->get(ClientRepository::class);
        self::assertInstanceOf(ClientRepository::class, $repository);

        $manager = (new \ReflectionProperty(ClientRepository::class, 'clientManager'))->getValue($repository);

        self::assertInstanceOf(CimdClientManager::class, $manager);
    }
}
