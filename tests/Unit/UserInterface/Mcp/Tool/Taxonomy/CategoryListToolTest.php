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
use Sulu\Bundle\CategoryBundle\Api\Category as ApiCategory;
use Sulu\Bundle\CategoryBundle\Category\CategoryManagerInterface;
use Sulu\Mcp\UserInterface\Mcp\Tool\Taxonomy\CategoryListTool;

#[CoversClass(CategoryListTool::class)]
final class CategoryListToolTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<CategoryManagerInterface> */
    private ObjectProphecy $categoryManager;
    private CategoryListTool $tool;

    protected function setUp(): void
    {
        $this->categoryManager = $this->prophesize(CategoryManagerInterface::class);
        $this->tool = new CategoryListTool($this->categoryManager->reveal());
    }

    public function testListCategoriesReturnsTree(): void
    {
        $child = $this->prophesize(ApiCategory::class);
        $child->getId(Argument::cetera())->willReturn(2);
        $child->getName(Argument::cetera())->willReturn('PHP');
        $child->getKey(Argument::cetera())->willReturn('php');
        $child->getChildren(Argument::cetera())->willReturn([]);

        $parent = $this->prophesize(ApiCategory::class);
        $parent->getId(Argument::cetera())->willReturn(1);
        $parent->getName(Argument::cetera())->willReturn('Technology');
        $parent->getKey(Argument::cetera())->willReturn('technology');
        $parent->getChildren(Argument::cetera())->willReturn([$child->reveal()]);

        $this->categoryManager->findChildrenByParentId(null)->willReturn([$parent->reveal()]);

        $this->categoryManager->getApiObjects(Argument::cetera())->willReturn([$parent->reveal()]);

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
        $leaf = $this->prophesize(ApiCategory::class);
        $leaf->getId(Argument::cetera())->willReturn(3);
        $leaf->getName(Argument::cetera())->willReturn('Leaf');
        $leaf->getKey(Argument::cetera())->willReturn('leaf');
        $leaf->getChildren(Argument::cetera())->willReturn([]);

        $this->categoryManager->findChildrenByParentId(Argument::cetera())->willReturn([$leaf->reveal()]);
        $this->categoryManager->getApiObjects(Argument::cetera())->willReturn([$leaf->reveal()]);

        $result = $this->tool->listCategories('en');

        $this->assertArrayHasKey('hasChildren', $result['categories'][0]);
        $this->assertFalse($result['categories'][0]['hasChildren']);
    }

    public function testListCategoriesMaxDepthStopsRecursion(): void
    {
        $grandchild = $this->prophesize(ApiCategory::class);
        $grandchild->getId(Argument::cetera())->willReturn(3);
        $grandchild->getName(Argument::cetera())->willReturn('Grandchild');
        $grandchild->getKey(Argument::cetera())->willReturn('grandchild');
        $grandchild->getChildren(Argument::cetera())->willReturn([]);

        $child = $this->prophesize(ApiCategory::class);
        $child->getId(Argument::cetera())->willReturn(2);
        $child->getName(Argument::cetera())->willReturn('Child');
        $child->getKey(Argument::cetera())->willReturn('child');
        $child->getChildren(Argument::cetera())->willReturn([$grandchild->reveal()]);

        $parent = $this->prophesize(ApiCategory::class);
        $parent->getId(Argument::cetera())->willReturn(1);
        $parent->getName(Argument::cetera())->willReturn('Parent');
        $parent->getKey(Argument::cetera())->willReturn('parent');
        $parent->getChildren(Argument::cetera())->willReturn([$child->reveal()]);

        $this->categoryManager->findChildrenByParentId(Argument::cetera())->willReturn([$parent->reveal()]);
        $this->categoryManager->getApiObjects(Argument::cetera())->willReturn([$parent->reveal()]);

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
        $child = $this->prophesize(ApiCategory::class);
        $child->getId(Argument::cetera())->willReturn(2);
        $child->getName(Argument::cetera())->willReturn('Child');
        $child->getKey(Argument::cetera())->willReturn('child');
        $child->getChildren(Argument::cetera())->willReturn([]);

        $root = $this->prophesize(ApiCategory::class);
        $root->getId(Argument::cetera())->willReturn(1);
        $root->getName(Argument::cetera())->willReturn('Root');
        $root->getKey(Argument::cetera())->willReturn('root');
        $root->getChildren(Argument::cetera())->willReturn([$child->reveal()]);

        $this->categoryManager->findChildrenByParentId(Argument::cetera())->willReturn([$root->reveal()]);
        $this->categoryManager->getApiObjects(Argument::cetera())->willReturn([$root->reveal()]);

        $result = $this->tool->listCategories('en', 0);

        $rootNode = $result['categories'][0];
        $this->assertTrue($rootNode['hasChildren']);
        $this->assertSame([], $rootNode['children']);
    }

    public function testListCategoriesWithoutMaxDepthReturnsFullTree(): void
    {
        $grandchild = $this->prophesize(ApiCategory::class);
        $grandchild->getId(Argument::cetera())->willReturn(3);
        $grandchild->getName(Argument::cetera())->willReturn('Grandchild');
        $grandchild->getKey(Argument::cetera())->willReturn('grandchild');
        $grandchild->getChildren(Argument::cetera())->willReturn([]);

        $child = $this->prophesize(ApiCategory::class);
        $child->getId(Argument::cetera())->willReturn(2);
        $child->getName(Argument::cetera())->willReturn('Child');
        $child->getKey(Argument::cetera())->willReturn('child');
        $child->getChildren(Argument::cetera())->willReturn([$grandchild->reveal()]);

        $root = $this->prophesize(ApiCategory::class);
        $root->getId(Argument::cetera())->willReturn(1);
        $root->getName(Argument::cetera())->willReturn('Root');
        $root->getKey(Argument::cetera())->willReturn('root');
        $root->getChildren(Argument::cetera())->willReturn([$child->reveal()]);

        $this->categoryManager->findChildrenByParentId(Argument::cetera())->willReturn([$root->reveal()]);
        $this->categoryManager->getApiObjects(Argument::cetera())->willReturn([$root->reveal()]);

        $result = $this->tool->listCategories('en');

        $this->assertCount(1, $result['categories'][0]['children']);
        $this->assertCount(1, $result['categories'][0]['children'][0]['children'], 'grandchild must be present with no maxDepth');
    }

    public function testListCategoriesReturnsHintOnFailure(): void
    {
        $this->categoryManager->findChildrenByParentId(Argument::cetera())->willThrow(new \RuntimeException('DB error'));

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
