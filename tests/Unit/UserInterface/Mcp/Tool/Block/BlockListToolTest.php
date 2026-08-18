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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\Block;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Article\Domain\Model\Article;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Mcp\Application\Content\ContentTypeResolver;
use Sulu\Mcp\Application\Security\ContentSecurityContextResolver;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\Tests\Application\TestBundle\Metadata\TestGroupProvider;
use Sulu\Mcp\Tests\Unit\Fixture\FakeToolPermissionChecker;
use Sulu\Mcp\UserInterface\Mcp\Tool\Block\BlockListTool;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Model\PageDimensionContent;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;

#[CoversClass(BlockListTool::class)]
#[CoversClass(ContentTypeResolver::class)]
final class BlockListToolTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<PageRepositoryInterface> */
    private ObjectProphecy $pageRepository;
    /** @var ObjectProphecy<ArticleRepositoryInterface> */
    private ObjectProphecy $articleRepository;
    /** @var ObjectProphecy<SnippetRepositoryInterface> */
    private ObjectProphecy $snippetRepository;
    /** @var ObjectProphecy<ContentManagerInterface> */
    private ObjectProphecy $contentManager;
    private FakeToolPermissionChecker $permissionChecker;
    private ContentSecurityContextResolver $contentSecurityContextResolver;
    private BlockListTool $tool;

    protected function setUp(): void
    {
        $this->pageRepository = $this->prophesize(PageRepositoryInterface::class);
        $this->articleRepository = $this->prophesize(ArticleRepositoryInterface::class);
        $this->snippetRepository = $this->prophesize(SnippetRepositoryInterface::class);
        $this->contentManager = $this->prophesize(ContentManagerInterface::class);
        $this->permissionChecker = FakeToolPermissionChecker::grantingAll();
        $groupProvider = new TestGroupProvider([]);
        $this->contentSecurityContextResolver = new ContentSecurityContextResolver(new ArticleSecurityContextResolver($groupProvider));
        $this->tool = new BlockListTool(
            new ContentTypeResolver($this->pageRepository->reveal(), $this->articleRepository->reveal(), $this->snippetRepository->reveal()),
            $this->contentManager->reveal(),
            $this->permissionChecker,
            $this->contentSecurityContextResolver,
        );
    }

    public function testListBlocksReturnsFirstPage(): void
    {
        $this->setupPageWithBlocks([
            ['_id' => 'a', 'type' => 'text', 'title' => 'Block 1', 'description' => '<p>Content 1</p>'],
            ['_id' => 'b', 'type' => 'image', 'title' => 'Block 2', 'src' => '/img.jpg'],
            ['_id' => 'c', 'type' => 'text', 'title' => 'Block 3', 'description' => '<p>Content 3</p>'],
            ['_id' => 'd', 'type' => 'text', 'title' => 'Block 4', 'description' => '<p>Content 4</p>'],
            ['_id' => 'e', 'type' => 'text', 'title' => 'Block 5', 'description' => '<p>Content 5</p>'],
        ]);

        $result = $this->tool->listBlocks('page', 'test-uuid', 'en', 'blocks', 1, 3);

        $this->assertSame(5, $result['total']);
        $this->assertSame(1, $result['page']);
        $this->assertSame(3, $result['limit']);
        $this->assertSame(0, $result['offset']);
        $this->assertCount(3, $result['blocks']);
        $this->assertSame('a', $result['blocks'][0]['_id']);
        $this->assertSame('c', $result['blocks'][2]['_id']);
    }

    public function testListBlocksReturnsSecondPage(): void
    {
        $this->setupPageWithBlocks([
            ['_id' => 'a', 'type' => 'text', 'title' => 'Block 1'],
            ['_id' => 'b', 'type' => 'text', 'title' => 'Block 2'],
            ['_id' => 'c', 'type' => 'text', 'title' => 'Block 3'],
            ['_id' => 'd', 'type' => 'text', 'title' => 'Block 4'],
            ['_id' => 'e', 'type' => 'text', 'title' => 'Block 5'],
        ]);

        $result = $this->tool->listBlocks('page', 'test-uuid', 'en', 'blocks', 2, 3);

        $this->assertSame(5, $result['total']);
        $this->assertSame(2, $result['page']);
        $this->assertSame(3, $result['offset']);
        $this->assertCount(2, $result['blocks']);
        $this->assertSame('d', $result['blocks'][0]['_id']);
        $this->assertSame('e', $result['blocks'][1]['_id']);
    }

    public function testListBlocksStripsEmptyValues(): void
    {
        $this->setupPageWithBlocks([
            ['_id' => 'a', 'type' => 'text', 'title' => 'Block 1', 'settings' => [], 'description' => ''],
        ]);

        $result = $this->tool->listBlocks('page', 'test-uuid', 'en', 'blocks', 1, 10);

        $this->assertCount(1, $result['blocks']);
        $this->assertArrayNotHasKey('settings', $result['blocks'][0]);
        $this->assertArrayNotHasKey('description', $result['blocks'][0]);
    }

    public function testListBlocksReturnsErrorForInvalidProperty(): void
    {
        $this->setupPageWithBlocks([
            ['_id' => 'a', 'type' => 'text'],
        ]);

        $result = $this->tool->listBlocks('page', 'test-uuid', 'en', 'nonexistent', 1, 10);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('nonexistent', $result['error']);
        $this->assertStringContainsString('blocks', $result['error']);
    }

    public function testListBlocksReturnsErrorForNotFound(): void
    {
        $this->pageRepository->getOneBy(Argument::cetera())->willThrow(new \RuntimeException('Not found'));

        $result = $this->tool->listBlocks('page', 'missing-uuid', 'en', 'blocks');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('missing-uuid', $result['error']);
    }

    public function testListBlocksReturnsErrorForInvalidType(): void
    {
        $result = $this->tool->listBlocks('invalid', 'test-uuid', 'en', 'blocks');

        $this->assertArrayHasKey('error', $result);
    }

    public function testListBlocksLoadsArticle(): void
    {
        $article = new Article('article-uuid');

        $this->articleRepository->getOneBy(Argument::cetera())->willReturn($article);

        $dimensionContent = new PageDimensionContent(new Page());
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([
            'blocks' => [
                ['_id' => 'x', 'type' => 'section', 'title' => 'Intro'],
            ],
        ]);

        $result = $this->tool->listBlocks('article', 'article-uuid', 'en', 'blocks');

        $this->assertSame(1, $result['total']);
        $this->assertSame('x', $result['blocks'][0]['_id']);
    }

    public function testMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(BlockListTool::class, 'listBlocks');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes);

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_block_list', $instance->name);
    }

    public function testListBlocksThrowsToolCallExceptionWhenPermissionDenied(): void
    {
        $this->setupPageWithBlocks([
            ['_id' => 'a', 'type' => 'text', 'title' => 'Block 1'],
        ]);

        $this->permissionChecker->denyAll();

        $this->expectException(ToolCallException::class);

        $this->tool->listBlocks('page', 'test-uuid', 'en', 'blocks');
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private function setupPageWithBlocks(array $blocks): void
    {
        $page = new Page('test-uuid');
        $page->setWebspaceKey('example');

        $this->pageRepository->getOneBy(Argument::cetera())->willReturn($page);

        $dimensionContent = new PageDimensionContent(new Page());
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([
            'template' => 'default',
            'title' => 'Test Page',
            'blocks' => $blocks,
        ]);
    }
}
