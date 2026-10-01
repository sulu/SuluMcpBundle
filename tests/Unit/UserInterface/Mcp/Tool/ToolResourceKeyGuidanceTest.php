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

#[CoversNothing]
final class ToolResourceKeyGuidanceTest extends TestCase
{
    public function testNoToolTextStillPointsAtTheOldTypeParameter(): void
    {
        $root = \dirname(__DIR__, 5);
        $sources = [];
        foreach (['/src/UserInterface/Mcp', '/docs'] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . $directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->isFile() && \in_array($file->getExtension(), ['php', 'md'], true)) {
                    $sources[] = (string) $file->getPathname();
                }
            }
        }
        $sources[] = $root . '/README.md';

        // Covers descriptions, hints and docs alike. Tools with a real "type" parameter
        // (contact list, product create) name other values, so only the content type values match.
        $contentTypes = '(page|article|snippet|product)';
        $stale = [];
        foreach ($sources as $source) {
            $text = (string) \file_get_contents($source);
            foreach ([
                '/\(type:\s*' . $contentTypes . '\b/',
                '/\btype\s*=\s*["\']' . $contentTypes . '["\']/',
                '/Verify the type/',
                '/the type is correct/',
                '/top-level keys `page`/',
            ] as $pattern) {
                if (1 === \preg_match($pattern, $text, $match)) {
                    $stale[] = \substr($source, \strlen($root) + 1) . ': ' . $match[0];
                }
            }
        }

        self::assertGreaterThan(50, \count($sources));
        self::assertSame([], $stale, 'These texts still point at the old "type" parameter instead of "resourceKey".');
    }

    public function testNoToolDescriptionNamesTheRenamedPlaceholders(): void
    {
        $srcRoot = \dirname(__DIR__, 5) . '/src';
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcRoot . '/UserInterface/Mcp/Tool', \FilesystemIterator::SKIP_DOTS),
        );

        $checked = 0;
        foreach ($files as $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }

            $relative = \str_replace(\DIRECTORY_SEPARATOR, '\\', \substr((string) $file->getPathname(), \strlen($srcRoot) + 1));
            $class = 'Sulu\\Mcp\\' . \substr($relative, 0, -4);
            if (!\class_exists($class)) {
                continue;
            }

            foreach ((new \ReflectionClass($class))->getMethods() as $method) {
                foreach ($method->getAttributes(McpTool::class) as $attribute) {
                    ++$checked;
                    self::assertStringNotContainsString('contentResourceKeys', (string) $attribute->newInstance()->description, $class);
                }
            }
        }

        self::assertGreaterThan(0, $checked);
    }

    #[DataProvider('lifecycleTools')]
    public function testLifecycleToolsNameTheRenamedResponseKey(string $file): void
    {
        $source = (string) \file_get_contents(\dirname(__DIR__, 5) . '/src/UserInterface/Mcp/Tool/Content/' . $file . '.php');

        self::assertStringContainsString('The response carries "resourceKey" instead of "type".', $source);
        self::assertStringContainsString('formerly "type", which is no longer accepted', $source);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function lifecycleTools(): iterable
    {
        yield 'publish' => ['ContentPublishTool'];
        yield 'unpublish' => ['ContentUnpublishTool'];
        yield 'delete' => ['ContentDeleteTool'];
    }

    public function testCreateToolsPointToTheResourceKeyOfTheirPublishStep(): void
    {
        $srcRoot = \dirname(__DIR__, 5) . '/src/UserInterface/Mcp/Tool';

        foreach (['Page/PageCreateTool' => 'pages', 'Article/ArticleCreateTool' => 'articles', 'Snippet/SnippetCreateTool' => 'snippets'] as $file => $resourceKey) {
            self::assertStringContainsString(
                'sulu_content_publish (resourceKey: ' . $resourceKey . ')',
                (string) \file_get_contents($srcRoot . '/' . $file . '.php'),
            );
        }
    }
}
