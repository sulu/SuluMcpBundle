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
use Mcp\Schema\Tool;
use PHPUnit\Framework\Attributes\CoversNothing;
use Sulu\Mcp\Infrastructure\Mcp\FilteredRegistry;

/**
 * Boots the real kernel and asserts every registered tool carries a `title`, the
 * label MCP clients show in place of the machine name. A tool added without one
 * is only visible as its snake_case name, which is caught here rather than in a
 * client UI.
 */
#[CoversNothing]
final class ToolTitleCompletenessTest extends FunctionalTestCase
{
    public function testEveryRegisteredToolHasATitle(): void
    {
        $container = self::getContainer();

        // Populate the shared registry: Builder::build() runs its loaders only
        // when the mcp.server service is actually built.
        $container->get('mcp.server');

        // The undecorated registry, because FilteredRegistry::getTools() hides
        // everything the (tokenless) test request is not permitted to see.
        /** @var RegistryInterface $registry */
        $registry = $container->get(FilteredRegistry::class . '.inner');

        /** @var array<string, Tool> $tools */
        $tools = $registry->getTools()->references;
        self::assertNotEmpty($tools, 'No tools are registered -- McpPass did not run or found no tagged mcp.tool services.');

        $untitled = [];
        foreach ($tools as $tool) {
            if (null === $tool->title || '' === $tool->title) {
                $untitled[] = $tool->name;
            }
        }
        \sort($untitled);

        self::assertSame([], $untitled, 'every #[McpTool] must declare a title');
    }
}
