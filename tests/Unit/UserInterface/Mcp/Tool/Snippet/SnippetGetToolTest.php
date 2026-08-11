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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\UserInterface\Mcp\Tool\Snippet\SnippetGetTool;
use Sulu\Snippet\Domain\Exception\SnippetNotFoundException;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;

#[CoversClass(SnippetGetTool::class)]
final class SnippetGetToolTest extends TestCase
{
    private SnippetRepositoryInterface&MockObject $snippetRepository;
    private ContentManagerInterface&MockObject $contentManager;
    private SnippetGetTool $tool;

    protected function setUp(): void
    {
        $this->snippetRepository = $this->createMock(SnippetRepositoryInterface::class);
        $this->contentManager = $this->createMock(ContentManagerInterface::class);
        $this->tool = new SnippetGetTool($this->snippetRepository, $this->contentManager);
    }

    public function testGetSnippetReturnsNormalizedContent(): void
    {
        $snippet = $this->createMock(SnippetInterface::class);
        $snippet->method('getUuid')->willReturn('snippet-uuid');
        $dimensionContent = $this->createMock(DimensionContentInterface::class);

        $this->snippetRepository->method('getOneBy')->willReturn($snippet);
        $this->contentManager->method('resolve')->willReturn($dimensionContent);
        $this->contentManager->method('normalize')->willReturn(['title' => 'Footer']);

        $result = $this->tool->getSnippet('en', 'snippet-uuid');

        $this->assertSame('snippet-uuid', $result['uuid']);
        $this->assertSame('en', $result['locale']);
        $this->assertSame(['title' => 'Footer'], $result['data']);
    }

    public function testGetSnippetReturnsErrorForNotFound(): void
    {
        $this->snippetRepository->method('getOneBy')
            ->willThrowException(new SnippetNotFoundException(['uuid' => 'bad']));

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
