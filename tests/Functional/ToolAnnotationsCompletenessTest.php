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
 * Boots the real kernel and asserts that every registered tool's annotations
 * actually reach the compiled Mcp\Schema\Tool object and survive JSON
 * serialization -- i.e. that `tools/list` really carries readOnlyHint /
 * destructiveHint, not just that the #[McpTool] attribute declares them
 * (ToolAnnotationsGoldenTest covers the attribute; this covers the wire
 * shape a client actually reads, per the "to check" in issue #46).
 */
#[CoversNothing]
final class ToolAnnotationsCompletenessTest extends FunctionalTestCase
{
    public function testEveryRegisteredToolSerializesReadOnlyHint(): void
    {
        $container = self::getContainer();

        // Populate the shared registry: Builder::build() runs its loaders only
        // when the mcp.server.sulu service is actually built.
        $container->get('mcp.server.sulu');

        // The undecorated registry, because FilteredRegistry::getTools() hides
        // everything the (tokenless) test request is not permitted to see.
        /** @var RegistryInterface $registry */
        $registry = $container->get(FilteredRegistry::class . '.inner');

        /** @var array<string, Tool> $tools */
        $tools = $registry->getTools()->references;
        self::assertNotEmpty($tools, 'No tools are registered -- McpPass did not run or found no tagged mcp.tool services.');

        $missingReadOnly = [];
        $missingDestructive = [];
        foreach ($tools as $tool) {
            /** @var array{annotations?: array{readOnlyHint?: bool, destructiveHint?: bool}} $serialized */
            $serialized = \json_decode((string) \json_encode($tool), true);
            $annotations = $serialized['annotations'] ?? [];

            // readOnlyHint is declared explicitly on every tool.
            if (!\array_key_exists('readOnlyHint', $annotations)) {
                $missingReadOnly[] = $tool->name;

                continue;
            }

            // Every non-read-only tool also declares destructiveHint explicitly
            // (read-only tools omit it -- it is not meaningful there).
            if (false === $annotations['readOnlyHint'] && !\array_key_exists('destructiveHint', $annotations)) {
                $missingDestructive[] = $tool->name;
            }
        }
        \sort($missingReadOnly);
        \sort($missingDestructive);

        self::assertSame(
            [],
            $missingReadOnly,
            'every tool must carry readOnlyHint on the JSON-serialized Tool -- a name present here means '
            . 'the #[McpTool(annotations: ...)] value did not reach tools/list.',
        );
        self::assertSame(
            [],
            $missingDestructive,
            'every non-read-only tool must carry destructiveHint on the JSON-serialized Tool.',
        );
    }
}
