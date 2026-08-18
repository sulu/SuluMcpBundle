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
use Sulu\Bundle\TagBundle\Tag\TagInterface;
use Sulu\Bundle\TagBundle\Tag\TagManagerInterface;
use Sulu\Mcp\Infrastructure\Sulu\AdminLink\TagAdminLinkProvider;
use Sulu\Mcp\Infrastructure\Symfony\Routing\AdminLinkGenerator;
use Sulu\Mcp\Tests\Application\TestBundle\Admin\TestViewRegistry;
use Sulu\Mcp\UserInterface\Mcp\Tool\Taxonomy\TagCreateTool;
use Symfony\Component\Routing\RouterInterface;

#[CoversClass(TagCreateTool::class)]
final class TagCreateToolTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<TagManagerInterface> */
    private ObjectProphecy $tagManager;
    private TagCreateTool $tool;

    protected function setUp(): void
    {
        $this->tagManager = $this->prophesize(TagManagerInterface::class);

        $router = $this->prophesize(RouterInterface::class);
        $router->generate(Argument::cetera())->willReturn('https://example.com/admin/');
        $adminLinkGenerator = new AdminLinkGenerator($router->reveal(), [new TagAdminLinkProvider(new TestViewRegistry())]);

        $this->tool = new TagCreateTool($this->tagManager->reveal(), $adminLinkGenerator);
    }

    public function testCreateTagReturnsSuccessWithIdAndName(): void
    {
        $mockTag = $this->prophesize(TagInterface::class);
        $mockTag->getId(Argument::cetera())->willReturn(42);
        $mockTag->getName(Argument::cetera())->willReturn('breaking-news');

        $this->tagManager->save(['name' => 'breaking-news'])->shouldBeCalledOnce()
            ->willReturn($mockTag->reveal());

        $result = $this->tool->createTag('breaking-news');

        $this->assertTrue($result['success']);
        $this->assertSame(42, $result['id']);
        $this->assertSame('breaking-news', $result['name']);
        $this->assertSame('https://example.com/admin/#/tags/42', $result['admin_url']);
    }

    public function testCreateTagReturnsErrorOnException(): void
    {
        $this->tagManager->save(Argument::cetera())->willThrow(new \RuntimeException('Duplicate tag'));

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
