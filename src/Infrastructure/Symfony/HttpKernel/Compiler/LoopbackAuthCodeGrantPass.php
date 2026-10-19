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

namespace Sulu\Mcp\Infrastructure\Symfony\HttpKernel\Compiler;

use League\OAuth2\Server\Grant\AuthCodeGrant;
use Sulu\Mcp\Infrastructure\League\Grant\LoopbackAuthCodeGrant;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * league's grant builds its redirect URI validator inline, so the only way to change the
 * comparison is to swap the grant class. Constructor arguments and the calls the league
 * extension adds stay as they are.
 *
 * @internal
 */
final class LoopbackAuthCodeGrantPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(AuthCodeGrant::class)) {
            return;
        }

        $container->findDefinition(AuthCodeGrant::class)->setClass(LoopbackAuthCodeGrant::class);
    }
}
