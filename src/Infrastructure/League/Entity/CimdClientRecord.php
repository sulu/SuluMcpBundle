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

namespace Sulu\Mcp\Infrastructure\League\Entity;

use Doctrine\ORM\Mapping as ORM;
use Sulu\Mcp\Infrastructure\OAuth\ClientMetadataFetcher;

/**
 * What League's client row cannot hold: where a client identified by URL came from, who first stored
 * it and when its document was last fetched successfully.
 *
 * The repository reads and writes the table with plain DBAL statements, so it never flushes the entity
 * manager of the request it runs in. The mapping is there for the schema.
 *
 * @internal
 */
#[ORM\Entity]
#[ORM\Table(name: 'sulu_mcp_cimd_client')]
#[ORM\Index(columns: ['created_by', 'created_at'])]
class CimdClientRecord
{
    #[ORM\Column(name: 'last_fetched_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $lastFetchedAt;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'identifier', length: 32)]
        private readonly string $identifier,
        #[ORM\Column(name: 'url', length: ClientMetadataFetcher::MAX_URL_LENGTH)]
        private readonly string $url,
        #[ORM\Column(name: 'created_by', length: 255)]
        private readonly string $createdBy,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private readonly \DateTimeImmutable $createdAt,
    ) {
        $this->lastFetchedAt = $createdAt;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getCreatedBy(): string
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastFetchedAt(): \DateTimeImmutable
    {
        return $this->lastFetchedAt;
    }

    public function fetched(\DateTimeImmutable $at): void
    {
        $this->lastFetchedAt = $at;
    }
}
