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

namespace Sulu\Mcp\Application\Security;

/**
 * Resolves a dynamic security context from a tool call's arguments (e.g. the
 * per-group article context from the `template` argument).
 *
 * @internal
 */
interface ToolContextResolverInterface
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function resolve(array $arguments): string;

    /**
     * @return list<string> the possible contexts this resolver can produce
     */
    public function candidates(): array;
}
