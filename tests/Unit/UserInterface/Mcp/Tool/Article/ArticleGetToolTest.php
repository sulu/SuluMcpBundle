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
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Article\Domain\Exception\ArticleNotFoundException;
use Sulu\Article\Domain\Model\Article;
use Sulu\Article\Domain\Model\ArticleDimensionContent;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\Tests\Application\TestBundle\Metadata\TestGroupProvider;
use Sulu\Mcp\Tests\Unit\Fixture\FakeToolPermissionChecker;
use Sulu\Mcp\UserInterface\Mcp\Tool\Article\ArticleGetTool;

#[CoversClass(ArticleGetTool::class)]
final class ArticleGetToolTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<ArticleRepositoryInterface> */
    private ObjectProphecy $articleRepository;
    /** @var ObjectProphecy<ContentManagerInterface> */
    private ObjectProphecy $contentManager;
    private FakeToolPermissionChecker $permissionChecker;
    private ArticleSecurityContextResolver $articleContextResolver;
    private ArticleGetTool $tool;

    protected function setUp(): void
    {
        $this->articleRepository = $this->prophesize(ArticleRepositoryInterface::class);
        $this->contentManager = $this->prophesize(ContentManagerInterface::class);
        $this->permissionChecker = FakeToolPermissionChecker::grantingAll();
        $groupProvider = new TestGroupProvider([]);
        $this->articleContextResolver = new ArticleSecurityContextResolver($groupProvider);
        $this->tool = new ArticleGetTool(
            $this->articleRepository->reveal(),
            $this->contentManager->reveal(),
            $this->permissionChecker,
            $this->articleContextResolver,
        );
    }

    public function testGetArticleReturnsNormalizedContent(): void
    {
        $article = new Article('test-uuid-123');

        $dimensionContent = new ArticleDimensionContent(new Article());
        $normalizedData = ['title' => 'Test Article', 'template' => 'blog'];

        $this->articleRepository->getOneBy(Argument::cetera())->willReturn($article);
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn($normalizedData);

        $result = $this->tool->getArticle('en', 'test-uuid-123');

        $this->assertSame('test-uuid-123', $result['uuid']);
        $this->assertSame('en', $result['locale']);
        $this->assertSame($normalizedData, $result['data']);
        $this->assertArrayNotHasKey('webspace', $result);
    }

    public function testGetArticlePassesCorrectFiltersToRepository(): void
    {
        $article = new Article('my-uuid');
        $dimensionContent = new ArticleDimensionContent(new Article());

        $this->articleRepository->getOneBy(
            [
                'uuid' => 'my-uuid',
                'locale' => 'de',
                'stage' => DimensionContentInterface::STAGE_DRAFT,
            ],
            [
                ArticleRepositoryInterface::GROUP_SELECT_ARTICLE_ADMIN => true,
            ],
        )->shouldBeCalledOnce()
            ->willReturn($article);

        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([]);

        $this->tool->getArticle('de', 'my-uuid');
    }

    public function testGetArticleUsesContentManagerToResolveAndNormalize(): void
    {
        $article = new Article('uuid-1');
        $dimensionContent = new ArticleDimensionContent(new Article());

        $this->articleRepository->getOneBy(Argument::cetera())->willReturn($article);

        $this->contentManager->resolve($article, [
            'locale' => 'en',
            'stage' => DimensionContentInterface::STAGE_DRAFT,
        ])->shouldBeCalledOnce()
            ->willReturn($dimensionContent);

        $this->contentManager->normalize($dimensionContent)->shouldBeCalledOnce()
            ->willReturn(['title' => 'Test']);

        $this->tool->getArticle('en', 'uuid-1');
    }

    public function testGetArticleReturnsErrorForMissingArticle(): void
    {
        $this->articleRepository->getOneBy(Argument::cetera())->willThrow(new ArticleNotFoundException(['uuid' => 'missing-uuid']));

        $result = $this->tool->getArticle('en', 'missing-uuid');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('missing-uuid', $result['error']);
        $this->assertTrue(\array_key_exists('hint', $result));
        $this->assertIsString($result['hint']);
        $this->assertNotEmpty($result['hint']);
    }

    public function testGetArticleMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(ArticleGetTool::class, 'getArticle');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes, 'getArticle() method must have exactly one #[McpTool] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_article_get', $instance->name);
    }

    public function testGetArticleThrowsToolCallExceptionWhenPermissionDenied(): void
    {
        $article = new Article('test-uuid-123');

        $dimensionContent = new ArticleDimensionContent(new Article());
        $dimensionContent->setTemplateKey('article');

        $this->articleRepository->getOneBy(Argument::cetera())->willReturn($article);
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);

        $this->permissionChecker->denyAll();

        $this->expectException(ToolCallException::class);

        $this->tool->getArticle('en', 'test-uuid-123');
    }
}
