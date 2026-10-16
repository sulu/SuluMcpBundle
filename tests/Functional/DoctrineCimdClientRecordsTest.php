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

use PHPUnit\Framework\Attributes\CoversClass;
use Sulu\Mcp\Infrastructure\League\Entity\CimdClientRecord;
use Sulu\Mcp\Infrastructure\League\Manager\DoctrineCimdClientRecords;

#[CoversClass(DoctrineCimdClientRecords::class)]
final class DoctrineCimdClientRecordsTest extends FunctionalTestCase
{
    public function testStoresAndFindsARecord(): void
    {
        $records = $this->records();
        $created = new \DateTimeImmutable('2026-01-01 10:00:00');
        $records->save(new CimdClientRecord('cimd_one', 'https://one.example/c.json', 'admin', $created));
        $this->entityManager->clear();

        $record = $records->find('cimd_one');

        self::assertSame('https://one.example/c.json', $record?->getUrl());
        self::assertSame('admin', $record?->getCreatedBy());
        self::assertEquals($created, $record?->getLastFetchedAt());
    }

    public function testCountsWhatAUserCreatedSince(): void
    {
        $records = $this->records();
        $records->save(new CimdClientRecord('cimd_a', 'https://a.example/c.json', 'admin', new \DateTimeImmutable('2026-01-02 10:00:00')));
        $records->save(new CimdClientRecord('cimd_b', 'https://b.example/c.json', 'admin', new \DateTimeImmutable('2026-01-01 08:00:00')));
        $records->save(new CimdClientRecord('cimd_c', 'https://c.example/c.json', 'other', new \DateTimeImmutable('2026-01-02 10:00:00')));

        self::assertSame(1, $records->countCreatedBy('admin', new \DateTimeImmutable('2026-01-02 00:00:00')));
        self::assertSame(2, $records->countCreatedBy('admin', new \DateTimeImmutable('2026-01-01 00:00:00')));
    }

    public function testFindsRecordsFetchedBeforeADate(): void
    {
        $records = $this->records();
        $old = new CimdClientRecord('cimd_old', 'https://old.example/c.json', 'admin', new \DateTimeImmutable('2026-01-01 10:00:00'));
        $fresh = new CimdClientRecord('cimd_new', 'https://new.example/c.json', 'admin', new \DateTimeImmutable('2026-01-01 10:00:00'));
        $fresh->fetched(new \DateTimeImmutable('2026-03-01 10:00:00'));
        $records->save($old);
        $records->save($fresh);

        $found = $records->findFetchedBefore(new \DateTimeImmutable('2026-02-01 00:00:00'));

        self::assertSame(['cimd_old'], \array_map(static fn (CimdClientRecord $record): string => $record->getIdentifier(), $found));
    }

    public function testRemovesARecord(): void
    {
        $records = $this->records();
        $records->save(new CimdClientRecord('cimd_gone', 'https://gone.example/c.json', 'admin', new \DateTimeImmutable('2026-01-01 10:00:00')));

        $records->remove('cimd_gone');
        $records->remove('cimd_gone');

        self::assertNull($records->find('cimd_gone'));
    }

    private function records(): DoctrineCimdClientRecords
    {
        return new DoctrineCimdClientRecords($this->entityManager);
    }
}
