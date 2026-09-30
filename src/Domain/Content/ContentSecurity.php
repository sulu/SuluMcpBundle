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

namespace Sulu\Mcp\Domain\Content;

final readonly class ContentSecurity
{
    public function __construct(
        public string $context,
        public ?string $aclObjectType = null,
        public ?string $webspaceKey = null,
    ) {
    }
}
