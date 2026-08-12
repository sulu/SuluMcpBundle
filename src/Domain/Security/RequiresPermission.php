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
 * Declares the Sulu permission(s) a tool method requires. Single source of truth
 * for the central handler, discovery filtering, and the get_context catalogue.
 *
 * A `#context#` in a requirement marks a context only known at call time: either
 * substituted from `contextArgument` (`sulu.webspaces.#context#`) or replaced wholesale
 * by `contextResolver` (a bare `#context#`). The `#` is deliberately unresolvable, so a
 * context that never gets substituted fails closed in ToolPermissionChecker::has().
 *
 * All `requirements` must hold (AND).
 * - `contextArgument`: call argument whose value replaces `#context#` in a template.
 * - `contextResolver`: service id resolving a context from arguments (article group).
 * - `objectResolved`: the handler only runs the coarse `discoveryContexts` check and
 *   the tool does the object-level check in-body.
 * - `discoveryContexts`: candidate contexts used for hiding and the coarse check.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class RequiresPermission
{
    /**
     * @param list<PermissionRequirement> $requirements
     * @param list<string> $discoveryContexts
     */
    public function __construct(
        public array $requirements,
        public ?string $contextArgument = null,
        public ?string $contextResolver = null,
        public bool $objectResolved = false,
        public array $discoveryContexts = [],
    ) {
    }
}
