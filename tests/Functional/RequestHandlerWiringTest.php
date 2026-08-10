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

namespace Sulu\Bundle\McpBundle\Tests\Functional;

use Mcp\Server;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Protocol;
use PHPUnit\Framework\Attributes\CoversClass;
use Sulu\Bundle\McpBundle\Capabilities\PermissionAwareCallToolHandler;

/**
 * Guards against permission logic that is unit-tested green but never reaches the
 * real dispatch chain. Every other test fetches PermissionAwareCallToolHandler
 * from the container by class name and calls it directly, so all of them stay
 * green even if the built server never registers it as a request handler.
 */
#[CoversClass(PermissionAwareCallToolHandler::class)]
final class RequestHandlerWiringTest extends FunctionalTestCase
{
    public function testPermissionAwareHandlerDispatchesAheadOfDefaultCallToolHandler(): void
    {
        $server = self::getContainer()->get('mcp.server');
        self::assertInstanceOf(Server::class, $server);

        // Both properties are private readonly with no accessor, so a ReflectionException
        // here means mcp/sdk renamed its internals, not that enforcement broke.
        $protocol = (new \ReflectionProperty(Server::class, 'protocol'))->getValue($server);
        self::assertInstanceOf(Protocol::class, $protocol);

        /** @var list<object> $handlers */
        $handlers = (new \ReflectionProperty(Protocol::class, 'requestHandlers'))->getValue($protocol);

        $permissionAwareIndex = null;
        $defaultCallToolIndex = null;
        foreach ($handlers as $index => $handler) {
            if ($handler instanceof PermissionAwareCallToolHandler) {
                $permissionAwareIndex = $index;
            } elseif (null === $defaultCallToolIndex && $handler instanceof CallToolHandler) {
                $defaultCallToolIndex = $index;
            }
        }

        self::assertNotNull($permissionAwareIndex, 'PermissionAwareCallToolHandler must be registered as an mcp.request_handler, or tools/call never reaches permission enforcement.');
        self::assertNotNull($defaultCallToolIndex, "Sanity check: the SDK's own default CallToolHandler must still be present as a fallback.");
        self::assertLessThan($defaultCallToolIndex, $permissionAwareIndex, 'PermissionAwareCallToolHandler must be dispatched before the unwrapped SDK CallToolHandler.');
    }
}
