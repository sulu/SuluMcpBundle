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

namespace Sulu\Mcp\Application\AdminLink;

/**
 * Per-entity-type strategy behind AdminLinkGeneratorInterface.
 *
 * @internal
 */
interface AdminLinkProviderInterface
{
    /**
     * The entity type this provider builds links for, e.g. "page" or "article".
     */
    public function getType(): string;

    /**
     * Build the admin SPA hash path (without scheme/host/admin prefix), e.g.
     * "/snippets/en/<uuid>". Returns null when required context is missing.
     *
     * @param array<string, mixed> $context
     */
    public function buildPath(array $context): ?string;
}
