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

use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Mcp\Server;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Protocol;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use Sulu\Bundle\McpBundle\Capabilities\PermissionAwareCallToolHandler;

/**
 * Guards against a past bug class: this logic was unit-tested green but never
 * actually wired into the real call path. Ported from the deleted Integration
 * suite's PermissionEnforcementTest, in two halves:
 *
 * - testPermissionAwareHandlerDispatchesAheadOfDefaultCallToolHandler proves the
 *   real, fully-built "mcp.server" registers PermissionAwareCallToolHandler and
 *   dispatches tools/call to it before the SDK's own CallToolHandler ever gets a
 *   turn. Compiled containers strip tag metadata, and neither Mcp\Server nor
 *   Mcp\Server\Protocol expose their built handler list publicly, so this can only
 *   be verified by reflecting into the real, fully-built Server's actual handler
 *   order -- there is no public accessor for it.
 *   It does NOT prove that the explicit `mcp.request_handler` tag on
 *   PermissionAwareCallToolHandler in config/services.yaml is load-bearing -- it
 *   isn't. Symfony autoconfigures every RequestHandlerInterface with that same tag
 *   regardless, and Mcp\Server\Builder::build() always merges custom handlers ahead
 *   of its own default CallToolHandler, so this assertion would still pass with
 *   that tag block deleted entirely.
 * - testNegativeControlBareCallToolHandlerDoesNotEnforcePermissions proves the
 *   other half: without that wrapper in front of it, the SDK's bare CallToolHandler
 *   enforces nothing at all.
 */
#[CoversClass(PermissionAwareCallToolHandler::class)]
final class RequestHandlerWiringTest extends FunctionalTestCase
{
    public function testPermissionAwareHandlerDispatchesAheadOfDefaultCallToolHandler(): void
    {
        $server = self::getContainer()->get('mcp.server');
        self::assertInstanceOf(Server::class, $server);

        // Pinned to symfony/mcp-bundle ^0.6 (currently 0.6.0): Server::$protocol and
        // Protocol::$requestHandlers are `private readonly` with no public accessor.
        // A ReflectionException here means the SDK renamed these internals, not that
        // permission enforcement broke.
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

        self::assertNotNull($permissionAwareIndex, 'PermissionAwareCallToolHandler must be registered as an mcp.request_handler.');
        self::assertNotNull($defaultCallToolIndex, "Sanity check: the SDK's own default CallToolHandler must still be present as a fallback.");
        self::assertLessThan(
            $defaultCallToolIndex,
            $permissionAwareIndex,
            'PermissionAwareCallToolHandler must be dispatched before the unwrapped SDK CallToolHandler, or '
            .'tools/call requests bypass permission enforcement entirely.',
        );
    }

    /**
     * Half of the dead-code-path regression guard: the same call that would be
     * denied through PermissionAwareCallToolHandler succeeds through the SDK's
     * bare CallToolHandler -- no permission wrapper, no Sulu container, no
     * fixtures involved at all -- proving enforcement depends on that wrapper
     * being present, not on anything the SDK's own handler does by itself.
     */
    public function testNegativeControlBareCallToolHandlerDoesNotEnforcePermissions(): void
    {
        $registry = new Registry();
        $registry->registerTool(
            new Tool('sulu_tag_create', ['type' => 'object', 'properties' => new \stdClass()], null, null),
            static fn (): string => 'tag created',
        );
        $bareHandler = new CallToolHandler($registry, new ReferenceHandler(null));

        $response = $bareHandler->handle(
            CallToolRequest::fromArray([
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => 'sulu_tag_create', 'arguments' => ['name' => 'x']],
            ]),
            $this->session(),
        );

        self::assertInstanceOf(Response::class, $response);
        $result = $response->result;
        self::assertInstanceOf(CallToolResult::class, $result);
        self::assertFalse(
            $result->isError,
            'Bare CallToolHandler executed a tool call requiring sulu.settings.tags ADD, with no permission '
            .'checker, no Sulu container, and no fixtures involved at all -- nothing in this path checks '
            .'permissions. Whether the real dispatch chain actually reaches PermissionAwareCallToolHandler '
            .'instead is asserted separately by '
            .'testPermissionAwareHandlerDispatchesAheadOfDefaultCallToolHandler.',
        );
    }

    private function session(): SessionInterface&Stub
    {
        return $this->createStub(SessionInterface::class);
    }
}
