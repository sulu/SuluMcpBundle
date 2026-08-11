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
use Sulu\Bundle\TagBundle\Tag\TagManagerInterface;
use Sulu\Mcp\UserInterface\Mcp\Tool\Taxonomy\TagDeleteTool;

#[CoversClass(TagDeleteTool::class)]
final class TagDeleteToolTest extends TestCase
{
    private TagManagerInterface&MockObject $tagManager;
    private TagDeleteTool $tool;

    protected function setUp(): void
    {
        $this->tagManager = $this->createMock(TagManagerInterface::class);
        $this->tool = new TagDeleteTool($this->tagManager);
    }

    public function testDeleteTagReturnsSuccessResult(): void
    {
        $this->tagManager->expects($this->once())
            ->method('delete')
            ->with(42);

        $result = $this->tool->deleteTag(42);

        $this->assertTrue($result['success']);
        $this->assertSame(42, $result['id']);
        $this->assertTrue($result['deleted']);
    }

    public function testDeleteTagReturnsErrorOnException(): void
    {
        $this->tagManager->method('delete')
            ->willThrowException(new \RuntimeException('Tag not found'));

        $result = $this->tool->deleteTag(999);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('999', $result['error']);
        $this->assertStringContainsString('Tag not found', $result['error']);
        $this->assertArrayNotHasKey('success', $result);
        $this->assertTrue(\array_key_exists('hint', $result));
        $this->assertIsString($result['hint']);
        $this->assertNotEmpty($result['hint']);
    }

    public function testDeleteTagMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(TagDeleteTool::class, 'deleteTag');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes, 'deleteTag() must have exactly one #[McpTool] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_tag_delete', $instance->name);
    }
}
