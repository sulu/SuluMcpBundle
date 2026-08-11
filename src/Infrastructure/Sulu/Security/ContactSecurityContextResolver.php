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

namespace Sulu\Mcp\Infrastructure\Sulu\Security;

use Sulu\Mcp\Application\Security\ToolContextResolverInterface;

/**
 * @internal
 */
final readonly class ContactSecurityContextResolver implements ToolContextResolverInterface
{
    public function resolve(array $arguments): string
    {
        return 'account' === ($arguments['type'] ?? 'contact')
            ? 'sulu.contact.organizations'
            : 'sulu.contact.people';
    }

    public function candidates(): array
    {
        return ['sulu.contact.people', 'sulu.contact.organizations'];
    }
}
