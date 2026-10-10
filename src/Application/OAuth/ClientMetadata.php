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
 * A validated Client ID Metadata Document, reduced to what the authorization server uses.
 *
 * @internal
 */
final readonly class ClientMetadata
{
    /**
     * @param list<string> $redirectUris
     */
    public function __construct(
        public string $clientId,
        public string $clientName,
        public array $redirectUris,
    ) {
    }
}
