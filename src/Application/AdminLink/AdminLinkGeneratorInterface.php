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
 * Port for building admin deeplinks, implemented in Infrastructure.
 *
 * @internal
 */
interface AdminLinkGeneratorInterface
{
    /**
     * Build an absolute deeplink into the Sulu admin for the given entity, or
     * null when no provider matches, the context is incomplete, or URL
     * generation fails. A missing link must never break a tool response.
     *
     * @param array<string, mixed> $context
     */
    public function generate(string $type, array $context): ?string;
}
