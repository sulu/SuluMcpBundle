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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Command;

use League\Bundle\OAuth2ServerBundle\Manager\InMemory\ClientManager;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sulu\Mcp\Infrastructure\League\Entity\CimdClientRecord;
use Sulu\Mcp\Infrastructure\League\Manager\CimdClientManager;
use Sulu\Mcp\Tests\Unit\Infrastructure\League\Manager\InMemoryCimdClientRecords;
use Sulu\Mcp\UserInterface\Command\CleanupCimdClientsCommand;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(CleanupCimdClientsCommand::class)]
final class CleanupCimdClientsCommandTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private ClientManager $clients;
    private InMemoryCimdClientRecords $records;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->clients = new ClientManager(new EventDispatcher());
        $this->records = new InMemoryCimdClientRecords();
        $this->tester = new CommandTester(new CleanupCimdClientsCommand($this->clients, $this->records, static fn (): int => self::NOW));
    }

    public function testRemovesClientsThatWereNotFetchedForThirtyDays(): void
    {
        $this->store('cimd_old', 'https://old.example/client.json', self::NOW - CimdClientManager::STORED_LIFETIME - 1);
        $this->store('cimd_new', 'https://new.example/client.json', self::NOW - 60);

        $exitCode = $this->tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertNull($this->clients->find('cimd_old'));
        self::assertNull($this->records->find('cimd_old'));
        self::assertNotNull($this->clients->find('cimd_new'));
        self::assertNotNull($this->records->find('cimd_new'));
        self::assertStringContainsString('https://old.example/client.json', $this->tester->getDisplay());
    }

    public function testDryRunRemovesNothing(): void
    {
        $this->store('cimd_old', 'https://old.example/client.json', self::NOW - CimdClientManager::STORED_LIFETIME - 1);

        $this->tester->execute(['--dry-run' => true]);

        self::assertNotNull($this->clients->find('cimd_old'));
        self::assertNotNull($this->records->find('cimd_old'));
        self::assertStringContainsString('Would remove', $this->tester->getDisplay());
    }

    public function testLeavesClientsWithoutARecordAlone(): void
    {
        $this->clients->save(new Client('Provisioned', 'abcdef0123456789abcdef0123456789', 'secret'));

        $this->tester->execute([]);

        self::assertNotNull($this->clients->find('abcdef0123456789abcdef0123456789'));
    }

    private function store(string $identifier, string $url, int $fetchedAt): void
    {
        $this->clients->save(new Client('Example', $identifier, null));
        $record = new CimdClientRecord($identifier, $url, 'admin', (new \DateTimeImmutable())->setTimestamp($fetchedAt));
        $this->records->save($record);
    }
}
