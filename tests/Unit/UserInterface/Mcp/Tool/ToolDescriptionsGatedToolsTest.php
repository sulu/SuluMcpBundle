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
use Sulu\Mcp\Domain\Security\DangerousTool;

#[CoversNothing]
final class ToolDescriptionsGatedToolsTest extends TestCase
{
    public function testADescriptionNamesAToolOfAnotherDangerousCategoryOnlyConditionally(): void
    {
        $tools = $this->tools();
        $gated = \array_filter(\array_column($tools, 'category', 'name'));
        self::assertNotEmpty($gated);

        $unconditional = [];
        foreach ($tools as $tool) {
            foreach ($gated as $name => $category) {
                if ($category === $tool['category'] || !\str_contains($tool['description'], $name)) {
                    continue;
                }
                if (!\str_contains($tool['description'], 'in your tool list')) {
                    $unconditional[] = $tool['name'] . ' names ' . $name;
                }
            }
        }

        self::assertSame(
            [],
            $unconditional,
            'With the category switched off the named tool does not exist, so the description has to say "if it is in your tool list".',
        );
    }

    /**
     * @return list<array{name: string, description: string, category: string|null}>
     */
    private function tools(): array
    {
        $srcRoot = \dirname(__DIR__, 5) . '/src';
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcRoot . '/UserInterface/Mcp/Tool', \FilesystemIterator::SKIP_DOTS),
        );

        $tools = [];
        foreach ($files as $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }

            $relative = \str_replace(\DIRECTORY_SEPARATOR, '\\', \substr((string) $file->getPathname(), \strlen($srcRoot) + 1));
            $class = 'Sulu\\Mcp\\' . \substr($relative, 0, -4);
            if (!\class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            $classGate = $reflection->getAttributes(DangerousTool::class)[0] ?? null;
            foreach ($reflection->getMethods() as $method) {
                foreach ($method->getAttributes(McpTool::class) as $attribute) {
                    $mcpTool = $attribute->newInstance();
                    $gate = $method->getAttributes(DangerousTool::class)[0] ?? $classGate;
                    $tools[] = [
                        'name' => (string) $mcpTool->name,
                        'description' => (string) $mcpTool->description,
                        'category' => $gate?->newInstance()->category,
                    ];
                }
            }
        }

        return $tools;
    }
}
