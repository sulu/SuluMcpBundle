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
 * Removes the tool from the container unless its `dangerous_tools` category is enabled.
 * A configured category that no tool declares fails the build.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final readonly class DangerousTool
{
    public function __construct(
        public string $category,
    ) {
    }
}
