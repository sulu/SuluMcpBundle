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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\CategoryBundle\Api\Category as ApiCategory;
use Sulu\Bundle\CategoryBundle\Category\CategoryManagerInterface;
use Sulu\Mcp\UserInterface\Mcp\Tool\Taxonomy\CategoryListTool;

#[CoversClass(CategoryListTool::class)]
final class CategoryListToolTest extends TestCase
{
    private CategoryManagerInterface&MockObject $categoryManager;
    private CategoryListTool $tool;

    protected function setUp(): void
    {
        $this->categoryManager = $this->createMock(CategoryManagerInterface::class);
        $this->tool = new CategoryListTool($this->categoryManager);
    }

    public function testListCategoriesReturnsTree(): void
    {
        $child = $this->createMock(ApiCategory::class);
        $child->method('getId')->willReturn(2);
        $child->method('getName')->willReturn('PHP');
        $child->method('getKey')->willReturn('php');
        $child->method('getChildren')->willReturn([]);

        $parent = $this->createMock(ApiCategory::class);
        $parent->method('getId')->willReturn(1);
        $parent->method('getName')->willReturn('Technology');
        $parent->method('getKey')->willReturn('technology');
        $parent->method('getChildren')->willReturn([$child]);

        $this->categoryManager->method('findChildrenByParentId')
            ->with(null)
            ->willReturn([$parent]);

        $this->categoryManager->method('getApiObjects')
            ->willReturn([$parent]);

        $result = $this->tool->listCategories('en');

        $this->assertArrayHasKey('categories', $result);
        $this->assertCount(1, $result['categories']);
        $this->assertSame('Technology', $result['categories'][0]['name']);
        $this->assertTrue($result['categories'][0]['hasChildren']);
        $this->assertCount(1, $result['categories'][0]['children']);
        $this->assertSame('PHP', $result['categories'][0]['children'][0]['name']);
        $this->assertFalse($result['categories'][0]['children'][0]['hasChildren']);
    }

    public function testListCategoriesHasChildrenPresentOnAllNodes(): void
    {
        $leaf = $this->createMock(ApiCategory::class);
        $leaf->method('getId')->willReturn(3);
        $leaf->method('getName')->willReturn('Leaf');
        $leaf->method('getKey')->willReturn('leaf');
        $leaf->method('getChildren')->willReturn([]);

        $this->categoryManager->method('findChildrenByParentId')->willReturn([$leaf]);
        $this->categoryManager->method('getApiObjects')->willReturn([$leaf]);

        $result = $this->tool->listCategories('en');

        $this->assertArrayHasKey('hasChildren', $result['categories'][0]);
        $this->assertFalse($result['categories'][0]['hasChildren']);
    }

    public function testListCategoriesMaxDepthStopsRecursion(): void
    {
        $grandchild = $this->createMock(ApiCategory::class);
        $grandchild->method('getId')->willReturn(3);
        $grandchild->method('getName')->willReturn('Grandchild');
        $grandchild->method('getKey')->willReturn('grandchild');
        $grandchild->method('getChildren')->willReturn([]);

        $child = $this->createMock(ApiCategory::class);
        $child->method('getId')->willReturn(2);
        $child->method('getName')->willReturn('Child');
        $child->method('getKey')->willReturn('child');
        $child->method('getChildren')->willReturn([$grandchild]);

        $parent = $this->createMock(ApiCategory::class);
        $parent->method('getId')->willReturn(1);
        $parent->method('getName')->willReturn('Parent');
        $parent->method('getKey')->willReturn('parent');
        $parent->method('getChildren')->willReturn([$child]);

        $this->categoryManager->method('findChildrenByParentId')->willReturn([$parent]);
        $this->categoryManager->method('getApiObjects')->willReturn([$parent]);

        $result = $this->tool->listCategories('en', 1);

        $parentNode = $result['categories'][0];
        $this->assertTrue($parentNode['hasChildren']);
        $this->assertCount(1, $parentNode['children'], 'depth=0 is below maxDepth=1, child must be present');

        $childNode = $parentNode['children'][0];
        $this->assertTrue($childNode['hasChildren'], 'child has children so hasChildren must be true');
        $this->assertSame([], $childNode['children'], 'grandchild must be omitted at maxDepth=1');
    }

    public function testListCategoriesMaxDepthZeroReturnsOnlyTopLevel(): void
    {
        $child = $this->createMock(ApiCategory::class);
        $child->method('getId')->willReturn(2);
        $child->method('getName')->willReturn('Child');
        $child->method('getKey')->willReturn('child');
        $child->method('getChildren')->willReturn([]);

        $root = $this->createMock(ApiCategory::class);
        $root->method('getId')->willReturn(1);
        $root->method('getName')->willReturn('Root');
        $root->method('getKey')->willReturn('root');
        $root->method('getChildren')->willReturn([$child]);

        $this->categoryManager->method('findChildrenByParentId')->willReturn([$root]);
        $this->categoryManager->method('getApiObjects')->willReturn([$root]);

        $result = $this->tool->listCategories('en', 0);

        $rootNode = $result['categories'][0];
        $this->assertTrue($rootNode['hasChildren']);
        $this->assertSame([], $rootNode['children']);
    }

    public function testListCategoriesWithoutMaxDepthReturnsFullTree(): void
    {
        $grandchild = $this->createMock(ApiCategory::class);
        $grandchild->method('getId')->willReturn(3);
        $grandchild->method('getName')->willReturn('Grandchild');
        $grandchild->method('getKey')->willReturn('grandchild');
        $grandchild->method('getChildren')->willReturn([]);

        $child = $this->createMock(ApiCategory::class);
        $child->method('getId')->willReturn(2);
        $child->method('getName')->willReturn('Child');
        $child->method('getKey')->willReturn('child');
        $child->method('getChildren')->willReturn([$grandchild]);

        $root = $this->createMock(ApiCategory::class);
        $root->method('getId')->willReturn(1);
        $root->method('getName')->willReturn('Root');
        $root->method('getKey')->willReturn('root');
        $root->method('getChildren')->willReturn([$child]);

        $this->categoryManager->method('findChildrenByParentId')->willReturn([$root]);
        $this->categoryManager->method('getApiObjects')->willReturn([$root]);

        $result = $this->tool->listCategories('en');

        $this->assertCount(1, $result['categories'][0]['children']);
        $this->assertCount(1, $result['categories'][0]['children'][0]['children'], 'grandchild must be present with no maxDepth');
    }

    public function testListCategoriesReturnsHintOnFailure(): void
    {
        $this->categoryManager->method('findChildrenByParentId')
            ->willThrowException(new \RuntimeException('DB error'));

        $result = $this->tool->listCategories('en');

        $this->assertArrayHasKey('error', $result);
        $this->assertTrue(\array_key_exists('hint', $result));
        $this->assertIsString($result['hint']);
        $this->assertNotEmpty($result['hint']);
    }

    public function testMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(CategoryListTool::class, 'listCategories');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes);
        $this->assertSame('sulu_category_list', $attributes[0]->newInstance()->name);
    }
}
