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

namespace Sulu\Mcp\Domain\Security;

/**
 * One AND-combined permission requirement: a security-context template (which may
 * contain the `#context#` placeholder) and the PermissionTypes constant required.
 *
 * @internal
 */
final readonly class PermissionRequirement
{
    public function __construct(
        public string $contextTemplate,
        public string $permissionType,
    ) {
    }
}
