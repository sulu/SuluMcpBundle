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
 * A description that names a resource or a renamed tool as if it were a tool sends the
 * model calling something that does not exist.
 */
#[CoversNothing]
final class ToolDescriptionReferencesTest extends FunctionalTestCase
{
    public function testDescriptionsOnlyNameRegisteredTools(): void
    {
        $container = self::getContainer();
        $container->get('mcp.server.sulu');

        /** @var RegistryInterface $registry */
        $registry = $container->get(FilteredRegistry::class . '.inner');

        /** @var array<string, Tool> $tools */
        $tools = $registry->getTools()->references;
        self::assertNotEmpty($tools);

        $unknown = [];
        foreach ($tools as $tool) {
            \preg_match_all('/\bsulu_[a-z0-9_]+\b/', (string) $tool->description, $matches);
            foreach ($matches[0] as $name) {
                if (!isset($tools[$name])) {
                    $unknown[] = $tool->name . ' -> ' . $name;
                }
            }
        }
        \sort($unknown);

        self::assertSame([], $unknown, 'every sulu_* name in a tool description must be a registered tool');
    }
}
