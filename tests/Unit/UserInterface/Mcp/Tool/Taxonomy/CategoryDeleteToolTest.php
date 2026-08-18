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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\Taxonomy;

use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Bundle\CategoryBundle\Category\CategoryManagerInterface;
use Sulu\Mcp\UserInterface\Mcp\Tool\Taxonomy\CategoryDeleteTool;

#[CoversClass(CategoryDeleteTool::class)]
final class CategoryDeleteToolTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<CategoryManagerInterface> */
    private ObjectProphecy $categoryManager;
    private CategoryDeleteTool $tool;

    protected function setUp(): void
    {
        $this->categoryManager = $this->prophesize(CategoryManagerInterface::class);
        $this->tool = new CategoryDeleteTool($this->categoryManager->reveal());
    }

    public function testDeleteCategoryReturnsSuccess(): void
    {
        $this->categoryManager->delete(42)->shouldBeCalledOnce();

        $result = $this->tool->deleteCategory(42);

        $this->assertTrue($result['success']);
        $this->assertSame(42, $result['id']);
        $this->assertTrue($result['deleted']);
    }

    public function testDeleteCategoryReturnsErrorOnException(): void
    {
        $this->categoryManager->delete(Argument::cetera())->willThrow(new \RuntimeException('Not found'));

        $result = $this->tool->deleteCategory(999);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('999', $result['error']);
        $this->assertTrue(\array_key_exists('hint', $result));
        $this->assertIsString($result['hint']);
        $this->assertNotEmpty($result['hint']);
    }

    public function testMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(CategoryDeleteTool::class, 'deleteCategory');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes);
        $this->assertSame('sulu_category_delete', $attributes[0]->newInstance()->name);
    }
}
