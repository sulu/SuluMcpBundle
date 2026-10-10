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

namespace Sulu\Mcp\Application\OAuth;

/**
 * @internal
 */
final readonly class FetchedClientMetadata
{
    /**
     * @param string $body the raw JSON document
     * @param int $ttl seconds the document may be reused, already clamped
     */
    public function __construct(
        public string $body,
        public int $ttl,
    ) {
    }
}
