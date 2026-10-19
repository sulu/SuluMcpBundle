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

namespace Sulu\Mcp\Tests\Unit\Infrastructure\Symfony\HttpKernel\Compiler;

use League\OAuth2\Server\Grant\AuthCodeGrant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sulu\Mcp\Infrastructure\League\Grant\LoopbackAuthCodeGrant;
use Sulu\Mcp\Infrastructure\Symfony\HttpKernel\Compiler\LoopbackAuthCodeGrantPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(LoopbackAuthCodeGrantPass::class)]
final class LoopbackAuthCodeGrantPassTest extends TestCase
{
    public function testProcessSwapsTheGrantClassAndKeepsItsWiring(): void
    {
        $container = new ContainerBuilder();
        $container->register('league.oauth2_server.grant.auth_code', AuthCodeGrant::class)
            ->setArguments(['repository', 'refresh', 'ttl'])
            ->addMethodCall('setPrivateKey', ['key']);
        $container->setAlias(AuthCodeGrant::class, 'league.oauth2_server.grant.auth_code');

        (new LoopbackAuthCodeGrantPass())->process($container);

        $definition = $container->getDefinition('league.oauth2_server.grant.auth_code');
        self::assertSame(LoopbackAuthCodeGrant::class, $definition->getClass());
        self::assertSame(['repository', 'refresh', 'ttl'], $definition->getArguments());
        self::assertSame([['setPrivateKey', ['key']]], $definition->getMethodCalls());
    }

    public function testProcessDoesNothingWithoutTheGrant(): void
    {
        $container = new ContainerBuilder();

        (new LoopbackAuthCodeGrantPass())->process($container);

        self::assertCount(1, $container->getDefinitions());
    }
}
