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

namespace Sulu\Mcp\Tests\Functional;

use Mcp\Capability\RegistryInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use Sulu\Mcp\Infrastructure\Mcp\FilteredRegistry;

/**
 * Boots the real kernel and diffs the compiled tool_permissions
 * map plus the actual MCP registry against ALLOWLIST -- dynamically, so a
 * tool added with no declaration or silently dropped is caught here.
 * sulu_ping/sulu_get_context are attribute-free, hence the allowlist.
 */
#[CoversNothing]
final class ToolPermissionMapCompletenessTest extends FunctionalTestCase
{
    private const ALLOWLIST = ['sulu_ping', 'sulu_get_context'];

    public function testDiscoveredToolNamesEqualMapKeysUnionAllowlist(): void
    {
        $container = self::getContainer();

        /** @var array<string, array{name: string, requirements: list<array{context: string, permission: string}>, contextArgument: ?string, contextResolver: ?string, objectResolved: bool, discoveryContexts: list<string>}> $map */
        $map = $container->getParameter('sulu_mcp.tool_permissions');
        self::assertNotEmpty($map, 'The compiled tool_permissions map is empty -- ToolPermissionMapPass did not run or found no tagged mcp.tool services.');

        // Populate the shared registry: Builder::build() runs its loaders only
        // when the mcp.server service is actually built.
        $container->get('mcp.server');

        // The undecorated registry, because FilteredRegistry::getTools() hides
        // everything the (tokenless) test request is not permitted to see.
        /** @var RegistryInterface $registry */
        $registry = $container->get(FilteredRegistry::class . '.inner');
        $discoveredNames = \array_keys($registry->getTools()->references);

        $expected = [...\array_keys($map), ...self::ALLOWLIST];
        \sort($expected);
        $actual = $discoveredNames;
        \sort($actual);

        self::assertSame(
            $expected,
            $actual,
            'discovered mcp.tool names must equal {map keys} union {allowlist}. A mismatch means either '
            . 'a tool was added with no #[RequiresPermission] declaration and is not allowlisted (present in '
            . 'discovered, absent from expected), or a declared/allowlisted tool was silently dropped from '
            . 'discovery (present in expected, absent from discovered).',
        );
    }
}
