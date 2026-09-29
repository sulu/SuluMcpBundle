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
 * Declares the "dangerous_tools" category that gates a tool method, additive to
 * `#[RequiresPermission]`: `DangerousToolsPass` removes the tool's service definition
 * entirely when its category is not enabled in configuration, rather than restricting
 * who may call it. A category is free-form and does not have to be declared anywhere
 * else; a config key with no matching category is rejected as a typo at compile time.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class DangerousTool
{
    public function __construct(
        public string $category,
    ) {
    }
}
