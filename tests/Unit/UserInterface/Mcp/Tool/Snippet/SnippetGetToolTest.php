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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\Snippet;

use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Mcp\UserInterface\Mcp\Tool\Snippet\SnippetGetTool;
use Sulu\Snippet\Domain\Exception\SnippetNotFoundException;
use Sulu\Snippet\Domain\Model\Snippet;
use Sulu\Snippet\Domain\Model\SnippetDimensionContent;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;

#[CoversClass(SnippetGetTool::class)]
final class SnippetGetToolTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<SnippetRepositoryInterface> */
    private ObjectProphecy $snippetRepository;
    /** @var ObjectProphecy<ContentManagerInterface> */
    private ObjectProphecy $contentManager;
    private SnippetGetTool $tool;

    protected function setUp(): void
    {
        $this->snippetRepository = $this->prophesize(SnippetRepositoryInterface::class);
        $this->contentManager = $this->prophesize(ContentManagerInterface::class);
        $this->tool = new SnippetGetTool($this->snippetRepository->reveal(), $this->contentManager->reveal());
    }

    public function testGetSnippetReturnsNormalizedContent(): void
    {
        $snippet = new Snippet('snippet-uuid');
        $dimensionContent = new SnippetDimensionContent(new Snippet());

        $this->snippetRepository->getOneBy(Argument::cetera())->willReturn($snippet);
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn(['title' => 'Footer']);

        $result = $this->tool->getSnippet('en', 'snippet-uuid');

        $this->assertSame('snippet-uuid', $result['uuid']);
        $this->assertSame('en', $result['locale']);
        $this->assertSame(['title' => 'Footer'], $result['data']);
    }

    public function testGetSnippetReturnsErrorForNotFound(): void
    {
        $this->snippetRepository->getOneBy(Argument::cetera())->willThrow(new SnippetNotFoundException(['uuid' => 'bad']));

        $result = $this->tool->getSnippet('en', 'bad');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('bad', $result['error']);
        $this->assertTrue(\array_key_exists('hint', $result));
        $this->assertIsString($result['hint']);
        $this->assertNotEmpty($result['hint']);
    }

    public function testMethodHasNoWebspaceParameter(): void
    {
        $reflection = new \ReflectionMethod(SnippetGetTool::class, 'getSnippet');
        $params = \array_map(fn ($p) => $p->getName(), $reflection->getParameters());

        $this->assertNotContains('webspace', $params);
    }

    public function testMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(SnippetGetTool::class, 'getSnippet');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes);
        $this->assertSame('sulu_snippet_get', $attributes[0]->newInstance()->name);
    }
}
