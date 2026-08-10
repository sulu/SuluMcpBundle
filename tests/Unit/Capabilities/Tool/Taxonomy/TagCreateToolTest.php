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

namespace Sulu\Bundle\McpBundle\Tests\Unit\Capabilities\Tool\Taxonomy;

use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\McpBundle\AdminLink\AdminLinkGenerator;
use Sulu\Bundle\McpBundle\AdminLink\Provider\TagAdminLinkProvider;
use Sulu\Bundle\McpBundle\Capabilities\Tool\Taxonomy\TagCreateTool;
use Sulu\Bundle\McpBundle\Tests\Application\TestBundle\Admin\TestViewRegistry;
use Sulu\Bundle\TagBundle\Tag\TagInterface;
use Sulu\Bundle\TagBundle\Tag\TagManagerInterface;
use Symfony\Component\Routing\RouterInterface;

#[CoversClass(TagCreateTool::class)]
final class TagCreateToolTest extends TestCase
{
    private TagManagerInterface&MockObject $tagManager;
    private TagCreateTool $tool;

    protected function setUp(): void
    {
        $this->tagManager = $this->createMock(TagManagerInterface::class);

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturn('https://example.com/admin/');
        $adminLinkGenerator = new AdminLinkGenerator($router, [new TagAdminLinkProvider(new TestViewRegistry())]);

        $this->tool = new TagCreateTool($this->tagManager, $adminLinkGenerator);
    }

    public function testCreateTagReturnsSuccessWithIdAndName(): void
    {
        $mockTag = $this->createMock(TagInterface::class);
        $mockTag->method('getId')->willReturn(42);
        $mockTag->method('getName')->willReturn('breaking-news');

        $this->tagManager->expects($this->once())
            ->method('save')
            ->with(['name' => 'breaking-news'])
            ->willReturn($mockTag);

        $result = $this->tool->createTag('breaking-news');

        $this->assertTrue($result['success']);
        $this->assertSame(42, $result['id']);
        $this->assertSame('breaking-news', $result['name']);
        $this->assertSame('https://example.com/admin/#/tags/42', $result['admin_url']);
    }

    public function testCreateTagReturnsErrorOnException(): void
    {
        $this->tagManager->method('save')
            ->willThrowException(new \RuntimeException('Duplicate tag'));

        $result = $this->tool->createTag('existing-tag');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('existing-tag', $result['error']);
        $this->assertStringContainsString('Duplicate tag', $result['error']);
        $this->assertArrayNotHasKey('success', $result);
        $this->assertTrue(\array_key_exists('hint', $result));
        $this->assertIsString($result['hint']);
        $this->assertNotEmpty($result['hint']);
    }

    public function testCreateTagMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(TagCreateTool::class, 'createTag');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes, 'createTag() must have exactly one #[McpTool] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_tag_create', $instance->name);
    }
}
