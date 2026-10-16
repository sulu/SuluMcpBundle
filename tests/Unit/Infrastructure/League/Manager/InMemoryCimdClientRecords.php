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

namespace Sulu\Mcp\Tests\Unit\Infrastructure\League\Manager;

use Sulu\Mcp\Infrastructure\League\Entity\CimdClientRecord;
use Sulu\Mcp\Infrastructure\League\Manager\CimdClientRecords;

final class InMemoryCimdClientRecords implements CimdClientRecords
{
    /** @var array<string, CimdClientRecord> */
    private array $records = [];

    public function find(string $identifier): ?CimdClientRecord
    {
        return $this->records[$identifier] ?? null;
    }

    public function save(CimdClientRecord $record): void
    {
        $this->records[$record->getIdentifier()] = $record;
    }

    public function remove(string $identifier): void
    {
        unset($this->records[$identifier]);
    }

    public function countCreatedBy(string $user, \DateTimeImmutable $since): int
    {
        return \count(\array_filter($this->records, static fn (CimdClientRecord $record): bool => $record->getCreatedBy() === $user && $record->getCreatedAt() >= $since));
    }

    public function findFetchedBefore(\DateTimeImmutable $before): array
    {
        return \array_values(\array_filter($this->records, static fn (CimdClientRecord $record): bool => $record->getLastFetchedAt() < $before));
    }
}
