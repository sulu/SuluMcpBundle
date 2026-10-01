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
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ToolResourceKeyGuidanceTest extends TestCase
{
    public function testNoDescriptionUsesTheOldTypeParameterForResourceKeyTools(): void
    {
        $srcRoot = \dirname(__DIR__, 5) . '/src';
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcRoot . '/UserInterface/Mcp/Tool', \FilesystemIterator::SKIP_DOTS),
        );

        $checked = 0;
        $stale = [];
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
                $attributes = $method->getAttributes(McpTool::class);
                if ([] === $attributes) {
                    continue;
                }

                $hasResourceKey = false;
                foreach ($method->getParameters() as $parameter) {
                    $hasResourceKey = $hasResourceKey || 'resourceKey' === $parameter->getName();
                }

                if (!$hasResourceKey) {
                    continue;
                }

                ++$checked;
                $tool = $attributes[0]->newInstance();
                if (1 === \preg_match('/\(type:|type\s*=\s*["\']?\w/', (string) $tool->description)) {
                    $stale[] = $tool->name;
                }
            }
        }

        self::assertGreaterThan(0, $checked);
        self::assertSame([], $stale, 'These tools take "resourceKey" but their description still says "type".');
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
