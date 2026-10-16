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

use Sulu\Mcp\Infrastructure\League\Entity\CimdClientRecord;

/**
 * @internal
 */
interface CimdClientRecords
{
    public function find(string $identifier): ?CimdClientRecord;

    public function save(CimdClientRecord $record): void;

    public function remove(string $identifier): void;

    public function countCreatedBy(string $user, \DateTimeImmutable $since): int;

    /**
     * @return list<CimdClientRecord>
     */
    public function findFetchedBefore(\DateTimeImmutable $before): array;
}
