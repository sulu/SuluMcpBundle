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

namespace Sulu\Bundle\McpBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Sulu\Bundle\TestBundle\Testing\PurgeDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Boots the tests/Application kernel against the real MySQL schema created by
 * "composer bootstrap-test-environment" and purges it between tests.
 */
abstract class FunctionalTestCase extends KernelTestCase
{
    use PurgeDatabaseTrait;

    protected EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $this->entityManager = $entityManager;

        static::purgeDatabase();
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager)) {
            $this->entityManager->close();
        }

        parent::tearDown();
    }
}
