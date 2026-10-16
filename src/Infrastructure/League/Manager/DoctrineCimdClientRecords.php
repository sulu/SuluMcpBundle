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

use Doctrine\ORM\EntityManagerInterface;
use Sulu\Mcp\Infrastructure\League\Entity\CimdClientRecord;

/**
 * @internal
 */
final readonly class DoctrineCimdClientRecords implements CimdClientRecords
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function find(string $identifier): ?CimdClientRecord
    {
        return $this->entityManager->find(CimdClientRecord::class, $identifier);
    }

    public function save(CimdClientRecord $record): void
    {
        $this->entityManager->persist($record);
        $this->entityManager->flush();
    }

    public function remove(string $identifier): void
    {
        $record = $this->find($identifier);
        if (null === $record) {
            return;
        }

        $this->entityManager->remove($record);
        $this->entityManager->flush();
    }

    public function countCreatedBy(string $user, \DateTimeImmutable $since): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(r.identifier)')
            ->from(CimdClientRecord::class, 'r')
            ->where('r.createdBy = :user')
            ->andWhere('r.createdAt >= :since')
            ->setParameter('user', $user)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findFetchedBefore(\DateTimeImmutable $before): array
    {
        /** @var list<CimdClientRecord> */
        return $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(CimdClientRecord::class, 'r')
            ->where('r.lastFetchedAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->getResult();
    }
}
