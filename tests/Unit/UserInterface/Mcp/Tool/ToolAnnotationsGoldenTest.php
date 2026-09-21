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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden-table pin for every tool's #[McpTool(annotations: ...)]. A readOnlyHint
 * of true tells a client the call never needs approval; destructiveHint true
 * tells it the call overwrites or removes existing state and should always
 * ask. See issue #46: the sulu.ai agent chat used a name-based guess
 * (get/list/search/tree/ping/context/preview/generate => no approval) before
 * these hints existed -- this table is the classification that replaces it.
 */
#[CoversNothing]
final class ToolAnnotationsGoldenTest extends TestCase
{
    /**
     * tool name => [readOnlyHint, destructiveHint, idempotentHint, openWorldHint].
     * null means the hint is intentionally omitted (not meaningful for that tool).
     *
     * @var array<string, array{0: ?bool, 1: ?bool, 2: ?bool, 3: ?bool}>
     */
    private const GOLDEN = [
        // read-only
        'sulu_get_context' => [true, null, null, false],
        'sulu_ping' => [true, null, null, false],
        'sulu_content_search' => [true, null, null, false],
        'sulu_page_get' => [true, null, null, false],
        'sulu_page_tree' => [true, null, null, false],
        'sulu_page_list' => [true, null, null, false],
        'sulu_contact_list' => [true, null, null, false],
        'sulu_snippet_get' => [true, null, null, false],
        'sulu_snippet_list' => [true, null, null, false],
        'sulu_navigation_get' => [true, null, null, false],
        'sulu_product_variant_list' => [true, null, null, false],
        'sulu_product_family_list' => [true, null, null, false],
        'sulu_product_list' => [true, null, null, false],
        'sulu_product_get' => [true, null, null, false],
        'sulu_attribute_list' => [true, null, null, false],
        'sulu_category_list' => [true, null, null, false],
        'sulu_tag_list' => [true, null, null, false],
        'sulu_article_list' => [true, null, null, false],
        'sulu_article_get' => [true, null, null, false],
        'sulu_block_list' => [true, null, null, false],
        'sulu_media_list' => [true, null, null, false],
        'sulu_media_get' => [true, null, null, false],

        // create: additive, reversible, never idempotent
        'sulu_page_create' => [false, false, false, false],
        'sulu_snippet_create' => [false, false, false, false],
        'sulu_product_create' => [false, false, false, false],
        'sulu_product_variant_create' => [false, false, false, false],
        'sulu_article_create' => [false, false, false, false],
        'sulu_tag_create' => [false, false, false, false],
        'sulu_category_create' => [false, false, false, false],
        // Persists a new token-protected preview link on every call, granting
        // unauthenticated access to draft content -- mutating, not read-only.
        'sulu_preview_link_generate' => [false, false, false, false],
        // Only inserts or appends; never overwrites or removes an existing block.
        'sulu_block_add' => [false, false, false, false],

        // update / move / reorder / delete / publish-state: overwrite existing state
        'sulu_page_move' => [false, true, true, false],
        'sulu_page_update' => [false, true, true, false],
        'sulu_page_reorder' => [false, true, true, false],
        'sulu_snippet_update' => [false, true, true, false],
        'sulu_content_unpublish' => [false, true, true, false],
        'sulu_content_publish' => [false, true, true, false],
        'sulu_content_delete' => [false, true, true, false],
        'sulu_product_variant_update' => [false, true, true, false],
        'sulu_product_update' => [false, true, true, false],
        'sulu_tag_delete' => [false, true, true, false],
        'sulu_category_delete' => [false, true, true, false],
        'sulu_preview_link_revoke' => [false, true, true, false],
        'sulu_article_update' => [false, true, true, false],
        'sulu_block_update' => [false, true, true, false],
        'sulu_media_update' => [false, true, true, false],
        // Both accept an index-addressed form (blockIndex / newOrder) where a
        // repeated call acts on the already-shifted state, not the original one --
        // never idempotent regardless of which addressing form a given call used.
        'sulu_block_remove' => [false, true, false, false],
        'sulu_block_reorder' => [false, true, false, false],

        // fetches a model-supplied external URL: open world, additive (not destructive)
        'sulu_media_upload' => [false, false, false, true],
    ];

    /**
     * Scans src/UserInterface/Mcp/Tool/ for every class with a #[McpTool]-attributed
     * method, mirroring ToolPermissionGoldenTest::discoverToolClasses().
     *
     * @return list<class-string>
     */
    private function discoverToolClasses(): array
    {
        $srcRoot = \dirname(__DIR__, 5) . '/src';
        $root = $srcRoot . '/UserInterface/Mcp/Tool';

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        $classes = [];
        foreach ($files as $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }

            $relative = \substr((string) $file->getPathname(), \strlen($srcRoot) + 1);
            $relative = \str_replace(\DIRECTORY_SEPARATOR, '\\', $relative);
            $class = 'Sulu\\Mcp\\' . \substr($relative, 0, -4);

            if (!\class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            foreach ($reflection->getMethods() as $method) {
                if ([] !== $method->getAttributes(McpTool::class)) {
                    $classes[] = $class;

                    continue 2;
                }
            }
        }

        \sort($classes);

        return $classes;
    }

    /**
     * @return array{name: string, method: \ReflectionMethod}
     */
    private function findToolMethod(string $class): array
    {
        $reflection = new \ReflectionClass($class);
        foreach ($reflection->getMethods() as $method) {
            $attributes = $method->getAttributes(McpTool::class);
            if ([] !== $attributes) {
                return ['name' => $attributes[0]->newInstance()->name, 'method' => $method];
            }
        }

        throw new \LogicException(\sprintf('%s has no #[McpTool]-attributed method.', $class));
    }

    public function testDiscoveredToolNamesMatchGoldenTable(): void
    {
        $discovered = [];
        foreach ($this->discoverToolClasses() as $class) {
            $discovered[] = $this->findToolMethod($class)['name'];
        }
        \sort($discovered);

        $expected = \array_keys(self::GOLDEN);
        \sort($expected);

        self::assertSame(
            $expected,
            $discovered,
            'A tool was added or renamed without updating GOLDEN in this test -- every MCP tool must '
            . 'carry a pinned annotations classification.',
        );
    }

    public static function golden(): iterable
    {
        foreach (self::GOLDEN as $name => $hints) {
            yield $name => [$name, $hints];
        }
    }

    /**
     * @param array{0: ?bool, 1: ?bool, 2: ?bool, 3: ?bool} $expectedHints
     */
    #[DataProvider('golden')]
    public function testToolAnnotationsMatchGoldenRow(string $name, array $expectedHints): void
    {
        $class = null;
        foreach ($this->discoverToolClasses() as $candidate) {
            if ($this->findToolMethod($candidate)['name'] === $name) {
                $class = $candidate;

                break;
            }
        }
        self::assertNotNull($class, \sprintf('No tool class found for "%s".', $name));

        $method = $this->findToolMethod($class)['method'];
        $attributes = $method->getAttributes(McpTool::class);
        $instance = $attributes[0]->newInstance();

        self::assertNotNull($instance->annotations, \sprintf('%s declares no annotations.', $name));

        [$readOnly, $destructive, $idempotent, $openWorld] = $expectedHints;

        self::assertSame($readOnly, $instance->annotations->readOnlyHint, \sprintf('%s readOnlyHint mismatch.', $name));
        self::assertSame($destructive, $instance->annotations->destructiveHint, \sprintf('%s destructiveHint mismatch.', $name));
        self::assertSame($idempotent, $instance->annotations->idempotentHint, \sprintf('%s idempotentHint mismatch.', $name));
        self::assertSame($openWorld, $instance->annotations->openWorldHint, \sprintf('%s openWorldHint mismatch.', $name));

        // A read-only tool never needs approval and can never also be destructive.
        if (true === $readOnly) {
            self::assertNotTrue($instance->annotations->destructiveHint, \sprintf('%s is readOnlyHint but also destructiveHint.', $name));
        }
    }

    public function testEveryToolDeclaresAnnotations(): void
    {
        $missing = [];
        foreach ($this->discoverToolClasses() as $class) {
            ['name' => $name, 'method' => $method] = $this->findToolMethod($class);
            $attributes = $method->getAttributes(McpTool::class);
            $instance = $attributes[0]->newInstance();

            if (null === $instance->annotations) {
                $missing[] = $name;
            }
        }
        \sort($missing);

        self::assertSame([], $missing, 'every #[McpTool] must declare annotations (readOnlyHint/destructiveHint/...)');
    }
}
