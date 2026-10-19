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

namespace Sulu\Mcp\Infrastructure\League\Manager;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Sulu\Mcp\Infrastructure\League\Entity\CimdClientRecord;

/**
 * Plain statements on the connection, so nothing here flushes the entity manager of the caller.
 *
 * @internal
 */
final readonly class DoctrineCimdClientRecords implements CimdClientRecords
{
    private const TABLE = 'sulu_mcp_cimd_client';

    private Connection $connection;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->connection = $entityManager->getConnection();
    }

    public function find(string $identifier): ?CimdClientRecord
    {
        $row = $this->connection->fetchAssociative(
            'SELECT identifier, url, created_by, created_at, last_fetched_at FROM ' . self::TABLE . ' WHERE identifier = :identifier',
            ['identifier' => $identifier],
        );

        return false === $row ? null : $this->hydrate($row);
    }

    public function save(CimdClientRecord $record): void
    {
        $types = ['last_fetched_at' => Types::DATETIME_IMMUTABLE, 'created_at' => Types::DATETIME_IMMUTABLE];

        if (false !== $this->connection->fetchOne('SELECT 1 FROM ' . self::TABLE . ' WHERE identifier = :identifier', ['identifier' => $record->getIdentifier()])) {
            $this->connection->update(
                self::TABLE,
                ['url' => $record->getUrl(), 'last_fetched_at' => $record->getLastFetchedAt()],
                ['identifier' => $record->getIdentifier()],
                $types,
            );

            return;
        }

        $this->connection->insert(
            self::TABLE,
            [
                'identifier' => $record->getIdentifier(),
                'url' => $record->getUrl(),
                'created_by' => $record->getCreatedBy(),
                'created_at' => $record->getCreatedAt(),
                'last_fetched_at' => $record->getLastFetchedAt(),
            ],
            $types,
        );
    }

    public function remove(string $identifier): void
    {
        $this->connection->delete(self::TABLE, ['identifier' => $identifier]);
    }

    public function countCreatedBy(string $user, \DateTimeImmutable $since): int
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(identifier) FROM ' . self::TABLE . ' WHERE created_by = :user AND created_at >= :since',
            ['user' => $user, 'since' => $since],
            ['since' => Types::DATETIME_IMMUTABLE],
        );

        return \is_numeric($count) ? (int) $count : 0;
    }

    public function findFetchedBefore(\DateTimeImmutable $before): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT identifier, url, created_by, created_at, last_fetched_at FROM ' . self::TABLE . ' WHERE last_fetched_at < :before',
            ['before' => $before],
            ['before' => Types::DATETIME_IMMUTABLE],
        );

        return \array_map($this->hydrate(...), $rows);
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): CimdClientRecord
    {
        /** @var \DateTimeImmutable $createdAt */
        $createdAt = $this->connection->convertToPHPValue($row['created_at'], Types::DATETIME_IMMUTABLE);
        /** @var \DateTimeImmutable $lastFetchedAt */
        $lastFetchedAt = $this->connection->convertToPHPValue($row['last_fetched_at'], Types::DATETIME_IMMUTABLE);

        $record = new CimdClientRecord(self::text($row['identifier']), self::text($row['url']), self::text($row['created_by']), $createdAt);
        $record->fetched($lastFetchedAt);

        return $record;
    }
}
