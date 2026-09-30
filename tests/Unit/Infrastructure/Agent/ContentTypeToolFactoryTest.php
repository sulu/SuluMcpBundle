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

namespace Sulu\Mcp\Tests\Unit\Infrastructure\Agent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Mcp\Application\Content\ContentTypeSchemaExpander;
use Sulu\Mcp\Infrastructure\Agent\ContentTypeToolFactory;
use Sulu\Mcp\Tests\Unit\Fixture\ContentTypes;
use Sulu\Mcp\Tests\Unit\Fixture\FakeContentTypeExtension;
use Sulu\Mcp\UserInterface\Agent\Tool\ContentSearchTool;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Agent\Toolbox\ToolFactory\ReflectionToolFactory;
use Symfony\AI\Platform\Tool\Tool;

#[CoversClass(ContentTypeToolFactory::class)]
#[Group('ai-agent')]
final class ContentTypeToolFactoryTest extends TestCase
{
    use ProphecyTrait;

    public function testExpandsDescriptionAndParameters(): void
    {
        $tool = $this->only($this->factory()->getTool(ContentSearchTool::class));

        $this->assertStringNotContainsString('{', $tool->getDescription());
        $this->assertStringContainsString('"pages", "articles"', $tool->getDescription());
        $this->assertStringContainsString('"widgets"', $tool->getDescription());

        $resourceKey = $tool->getParameters()['properties']['resourceKey'] ?? [];
        $this->assertContains('pages', $resourceKey['enum']);
        $this->assertContains('articles', $resourceKey['enum']);
        $this->assertContains('widgets', $resourceKey['enum']);
        $this->assertStringContainsString('"widgets"', $resourceKey['description']);
        $this->assertStringNotContainsString('{', $resourceKey['description']);
    }

    public function testPassesToolsWithoutPlaceholdersThrough(): void
    {
        $inner = new ReflectionToolFactory();
        $plain = $this->only($inner->getTool(PlainTool::class));

        $result = $this->only($this->factory()->getTool(PlainTool::class));

        $this->assertEquals($plain, $result);
        $this->assertSame('Plain description.', $result->getDescription());
    }

    private function factory(): ContentTypeToolFactory
    {
        $registry = ContentTypes::registry(
            $this->prophesize(PageRepositoryInterface::class)->reveal(),
            $this->prophesize(ArticleRepositoryInterface::class)->reveal(),
            extensions: [new FakeContentTypeExtension()],
        );

        return new ContentTypeToolFactory(new ReflectionToolFactory(), new ContentTypeSchemaExpander($registry));
    }

    /**
     * @param iterable<Tool> $tools
     */
    private function only(iterable $tools): Tool
    {
        $tools = [...$tools];
        $this->assertCount(1, $tools);

        return $tools[0];
    }
}

#[AsTool(name: 'plain_tool', description: 'Plain description.')]
final class PlainTool
{
    public function __invoke(string $query): string
    {
        return $query;
    }
}
