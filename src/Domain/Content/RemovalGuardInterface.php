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

/**
 * Implemented by an extension that must veto a removal beyond the permission on the entity itself.
 */
interface RemovalGuardInterface
{
    /**
     * Throws a PermissionDeniedException, called after the permission on the entity itself passed.
     */
    public function assertCanRemove(string $uuid): void;
}
