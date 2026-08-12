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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\Article;

use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Article\Domain\Model\ArticleInterface;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormGroup;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\TemplateInterface;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\Tests\Application\TestBundle\Metadata\TestGroupProvider;
use Sulu\Mcp\UserInterface\Mcp\Tool\Article\ArticleListTool;

#[CoversClass(ArticleListTool::class)]
final class ArticleListToolTest extends TestCase
{
    use ProphecyTrait;

    private ArticleRepositoryInterface&MockObject $articleRepository;
    private ContentManagerInterface&MockObject $contentManager;
    private ToolPermissionCheckerInterface&MockObject $permissionChecker;
    private ArticleSecurityContextResolver $articleContextResolver;
    private ArticleListTool $tool;

    protected function setUp(): void
    {
        $this->articleRepository = $this->createMock(ArticleRepositoryInterface::class);
        $this->contentManager = $this->createMock(ContentManagerInterface::class);
        $this->permissionChecker = $this->createMock(ToolPermissionCheckerInterface::class);
        // Default: grant, so existing happy-path tests are unaffected by the new filter.
        $this->permissionChecker->method('has')->willReturn(true);
        // Single-group install owning both template keys used across these tests.
        $this->articleContextResolver = new ArticleSecurityContextResolver(
            new TestGroupProvider(['default' => new FormGroup('default', 'Default', ['article', 'blog'])]),
        );
        $this->tool = new ArticleListTool(
            $this->articleRepository,
            $this->contentManager,
            $this->permissionChecker,
            $this->articleContextResolver,
        );
    }

    public function testListArticlesReturnsPaginatedResults(): void
    {
        $article1 = $this->createMock(ArticleInterface::class);
        $article1->method('getUuid')->willReturn('uuid-1');
        $article2 = $this->createMock(ArticleInterface::class);
        $article2->method('getUuid')->willReturn('uuid-2');

        $this->articleRepository->method('findIdentifiersBy')->willReturn(['uuid-1', 'uuid-2']);
        $this->articleRepository->method('findBy')->willReturn([$article1, $article2]);
        $this->articleRepository->method('countBy')->willReturn(5);

        $dimensionContent = $this->createMockForIntersectionOfInterfaces([TemplateInterface::class, DimensionContentInterface::class]);
        $this->contentManager->method('resolve')->willReturn($dimensionContent);
        $this->contentManager->method('normalize')->willReturn(['title' => 'Test']);

        $result = $this->tool->listArticles('en');

        $this->assertCount(2, $result['articles']);
        $this->assertSame(5, $result['total']);
        $this->assertSame(1, $result['page']);
        $this->assertSame(20, $result['limit']);
        $this->assertSame('uuid-1', $result['articles'][0]['uuid']);
        $this->assertSame('uuid-2', $result['articles'][1]['uuid']);
    }

    public function testListArticlesAppliesTemplateFilter(): void
    {
        $this->articleRepository
            ->expects($this->once())
            ->method('findIdentifiersBy')
            ->with(
                $this->callback(fn (array $filters): bool => isset($filters['templateKeys'])
                    && $filters['templateKeys'] === ['blog']),
                $this->anything(),
            )
            ->willReturn([]);
        $this->articleRepository->method('countBy')->willReturn(0);

        $this->tool->listArticles('en', 'blog');
    }

    public function testListArticlesDefaultsPaginationToPage1Limit20(): void
    {
        $this->articleRepository
            ->expects($this->once())
            ->method('findIdentifiersBy')
            ->with(
                $this->callback(fn (array $filters): bool => 1 === $filters['page'] && 20 === $filters['limit']),
                $this->anything(),
            )
            ->willReturn([]);
        $this->articleRepository->method('countBy')->willReturn(0);

        $this->tool->listArticles('en');
    }

    public function testListArticlesResolvesAndNormalizesEachArticle(): void
    {
        $article1 = $this->createMock(ArticleInterface::class);
        $article1->method('getUuid')->willReturn('uuid-1');
        $article2 = $this->createMock(ArticleInterface::class);
        $article2->method('getUuid')->willReturn('uuid-2');
        $article3 = $this->createMock(ArticleInterface::class);
        $article3->method('getUuid')->willReturn('uuid-3');

        $this->articleRepository->method('findIdentifiersBy')->willReturn(['uuid-1', 'uuid-2', 'uuid-3']);
        $this->articleRepository->method('findBy')->willReturn([$article1, $article2, $article3]);
        $this->articleRepository->method('countBy')->willReturn(3);

        $dimensionContent = $this->createMockForIntersectionOfInterfaces([TemplateInterface::class, DimensionContentInterface::class]);
        $this->contentManager
            ->expects($this->exactly(3))
            ->method('resolve')
            ->willReturn($dimensionContent);
        $this->contentManager
            ->expects($this->exactly(3))
            ->method('normalize')
            ->willReturn(['title' => 'Test']);

        $this->tool->listArticles('en');
    }

    public function testListArticlesMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(ArticleListTool::class, 'listArticles');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes, 'listArticles() method must have exactly one #[McpTool] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_article_list', $instance->name);
    }

    /**
     * Group scoping must reach the query, not filter rows afterwards, or `total`
     * (COUNT DISTINCT) could count articles from a group the user can't read.
     */
    public function testListArticlesScopesQueryToPermittedGroupTemplates(): void
    {
        // Two groups so "default" and "blog" resolve to distinct security contexts.
        $contextResolver = new ArticleSecurityContextResolver(new TestGroupProvider([
            'default' => (new FormGroup('default', 'Default'))->withTemplate('default'),
            'blog' => (new FormGroup('blog', 'Blog'))->withTemplate('blog'),
        ]));

        $permissionChecker = $this->createMock(ToolPermissionCheckerInterface::class);
        $permissionChecker
            ->method('has')
            ->willReturnCallback(static fn (string $context): bool => 'sulu.article.articles' === $context);

        $onlyDefaultTemplate = static fn (array $filters): bool => ['default'] === ($filters['templateKeys'] ?? null);

        $this->articleRepository
            ->expects($this->once())
            ->method('findIdentifiersBy')
            ->with($this->callback($onlyDefaultTemplate), $this->anything())
            ->willReturn([]);
        $this->articleRepository
            ->expects($this->once())
            ->method('countBy')
            ->with($this->callback($onlyDefaultTemplate))
            ->willReturn(0);

        $tool = new ArticleListTool(
            $this->articleRepository,
            $this->contentManager,
            $permissionChecker,
            $contextResolver,
        );

        $tool->listArticles('en');
    }

    public function testListArticlesReturnsEmptyWhenNoArticleGroupIsPermitted(): void
    {
        $permissionChecker = $this->createMock(ToolPermissionCheckerInterface::class);
        $permissionChecker->method('has')->willReturn(false);

        $this->articleRepository->expects($this->never())->method('findIdentifiersBy');

        $tool = new ArticleListTool(
            $this->articleRepository,
            $this->contentManager,
            $permissionChecker,
            $this->articleContextResolver,
        );

        $result = $tool->listArticles('en');

        $this->assertSame([], $result['articles']);
        $this->assertSame(0, $result['total']);
    }

    /**
     * @return array{ArticleListTool, ObjectProphecy<ArticleRepositoryInterface>}
     */
    private function createToolWithProphecyRepository(): array
    {
        $articleRepository = $this->prophesize(ArticleRepositoryInterface::class);

        $contentManager = $this->prophesize(ContentManagerInterface::class);
        $contentManager->resolve(Argument::cetera())
            ->willReturn($this->prophesize(DimensionContentInterface::class)->reveal());
        $contentManager->normalize(Argument::cetera())->willReturn(['title' => 'Test']);

        $permissionChecker = $this->prophesize(ToolPermissionCheckerInterface::class);
        $permissionChecker->has(Argument::cetera())->willReturn(true);

        $tool = new ArticleListTool(
            $articleRepository->reveal(),
            $contentManager->reveal(),
            $permissionChecker->reveal(),
            $this->articleContextResolver,
        );

        return [$tool, $articleRepository];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sortFieldAndOrderProvider(): iterable
    {
        foreach (['title', 'authored', 'created', 'changed', 'workflowPublished'] as $field) {
            foreach (['asc', 'desc'] as $order) {
                yield "{$field}/{$order}" => [$field, $order];
            }
        }
    }

    #[DataProvider('sortFieldAndOrderProvider')]
    public function testListArticlesAppliesSortByToBothRepositoryCalls(string $sortBy, string $sortOrder): void
    {
        [$tool, $articleRepository] = $this->createToolWithProphecyRepository();

        $article = $this->prophesize(ArticleInterface::class);
        $article->getUuid()->willReturn('uuid-1');

        $articleRepository->countBy(Argument::type('array'))->willReturn(1);
        $articleRepository->findIdentifiersBy(Argument::type('array'), [$sortBy => $sortOrder])
            ->shouldBeCalledOnce()
            ->willReturn(['uuid-1']);
        $articleRepository->findBy(Argument::type('array'), [$sortBy => $sortOrder], Argument::type('array'))
            ->shouldBeCalledOnce()
            ->willReturn([$article->reveal()]);

        $tool->listArticles('en', null, 1, 20, $sortBy, $sortOrder);
    }

    public function testListArticlesDefaultSortIsTitleAscendingWhenOmitted(): void
    {
        [$tool, $articleRepository] = $this->createToolWithProphecyRepository();

        $article = $this->prophesize(ArticleInterface::class);
        $article->getUuid()->willReturn('uuid-1');

        $articleRepository->countBy(Argument::type('array'))->willReturn(1);
        $articleRepository->findIdentifiersBy(Argument::type('array'), ['title' => 'asc'])
            ->shouldBeCalledOnce()
            ->willReturn(['uuid-1']);
        $articleRepository->findBy(Argument::type('array'), ['title' => 'asc'], Argument::type('array'))
            ->shouldBeCalledOnce()
            ->willReturn([$article->reveal()]);

        $tool->listArticles('en');
    }

    public function testListArticlesRejectsUnsupportedSortBy(): void
    {
        [$tool] = $this->createToolWithProphecyRepository();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported sortBy "bogus"');

        $tool->listArticles('en', null, 1, 20, 'bogus', 'asc');
    }

    public function testListArticlesRejectsUnsupportedSortOrder(): void
    {
        [$tool] = $this->createToolWithProphecyRepository();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported sortOrder "bogus"');

        $tool->listArticles('en', null, 1, 20, 'title', 'bogus');
    }
}
