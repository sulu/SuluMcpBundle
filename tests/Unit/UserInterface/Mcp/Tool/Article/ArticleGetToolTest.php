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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sulu\Article\Domain\Exception\ArticleNotFoundException;
use Sulu\Article\Domain\Model\ArticleInterface;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Bundle\AdminBundle\Metadata\GroupProviderInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\TemplateInterface;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\UserInterface\Mcp\Tool\Article\ArticleGetTool;

#[CoversClass(ArticleGetTool::class)]
final class ArticleGetToolTest extends TestCase
{
    private ArticleRepositoryInterface&MockObject $articleRepository;
    private ContentManagerInterface&MockObject $contentManager;
    private ToolPermissionCheckerInterface&MockObject $permissionChecker;
    private ArticleSecurityContextResolver $articleContextResolver;
    private ArticleGetTool $tool;

    protected function setUp(): void
    {
        $this->articleRepository = $this->createMock(ArticleRepositoryInterface::class);
        $this->contentManager = $this->createMock(ContentManagerInterface::class);
        $this->permissionChecker = $this->createMock(ToolPermissionCheckerInterface::class);
        $groupProvider = $this->createMock(GroupProviderInterface::class);
        $groupProvider->method('getGroups')->willReturn([]);
        $this->articleContextResolver = new ArticleSecurityContextResolver($groupProvider);
        $this->tool = new ArticleGetTool(
            $this->articleRepository,
            $this->contentManager,
            $this->permissionChecker,
            $this->articleContextResolver,
        );
    }

    public function testGetArticleReturnsNormalizedContent(): void
    {
        $article = $this->createMock(ArticleInterface::class);
        $article->method('getUuid')->willReturn('test-uuid-123');

        $dimensionContent = $this->createMockForIntersectionOfInterfaces([TemplateInterface::class, DimensionContentInterface::class]);
        $normalizedData = ['title' => 'Test Article', 'template' => 'blog'];

        $this->articleRepository->method('getOneBy')->willReturn($article);
        $this->contentManager->method('resolve')->willReturn($dimensionContent);
        $this->contentManager->method('normalize')->willReturn($normalizedData);

        $result = $this->tool->getArticle('en', 'test-uuid-123');

        $this->assertSame('test-uuid-123', $result['uuid']);
        $this->assertSame('en', $result['locale']);
        $this->assertSame($normalizedData, $result['data']);
        $this->assertArrayNotHasKey('webspace', $result);
    }

    public function testGetArticlePassesCorrectFiltersToRepository(): void
    {
        $article = $this->createMock(ArticleInterface::class);
        $article->method('getUuid')->willReturn('my-uuid');
        $dimensionContent = $this->createMockForIntersectionOfInterfaces([TemplateInterface::class, DimensionContentInterface::class]);

        $this->articleRepository
            ->expects($this->once())
            ->method('getOneBy')
            ->with(
                [
                    'uuid' => 'my-uuid',
                    'locale' => 'de',
                    'stage' => DimensionContentInterface::STAGE_DRAFT,
                ],
                [
                    ArticleRepositoryInterface::GROUP_SELECT_ARTICLE_ADMIN => true,
                ],
            )
            ->willReturn($article);

        $this->contentManager->method('resolve')->willReturn($dimensionContent);
        $this->contentManager->method('normalize')->willReturn([]);

        $this->tool->getArticle('de', 'my-uuid');
    }

    public function testGetArticleUsesContentManagerToResolveAndNormalize(): void
    {
        $article = $this->createMock(ArticleInterface::class);
        $article->method('getUuid')->willReturn('uuid-1');
        $dimensionContent = $this->createMockForIntersectionOfInterfaces([TemplateInterface::class, DimensionContentInterface::class]);

        $this->articleRepository->method('getOneBy')->willReturn($article);

        $this->contentManager
            ->expects($this->once())
            ->method('resolve')
            ->with($article, [
                'locale' => 'en',
                'stage' => DimensionContentInterface::STAGE_DRAFT,
            ])
            ->willReturn($dimensionContent);

        $this->contentManager
            ->expects($this->once())
            ->method('normalize')
            ->with($dimensionContent)
            ->willReturn(['title' => 'Test']);

        $this->tool->getArticle('en', 'uuid-1');
    }

    public function testGetArticleReturnsErrorForMissingArticle(): void
    {
        $this->articleRepository
            ->method('getOneBy')
            ->willThrowException(new ArticleNotFoundException(['uuid' => 'missing-uuid']));

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
        $article = $this->createMock(ArticleInterface::class);
        $article->method('getUuid')->willReturn('test-uuid-123');

        $dimensionContent = $this->createMockForIntersectionOfInterfaces([
            TemplateInterface::class,
            DimensionContentInterface::class,
        ]);
        $dimensionContent->method('getTemplateKey')->willReturn('article');

        $this->articleRepository->method('getOneBy')->willReturn($article);
        $this->contentManager->method('resolve')->willReturn($dimensionContent);

        $this->permissionChecker
            ->method('check')
            ->willThrowException(new PermissionDeniedException('sulu.article.articles', PermissionTypes::VIEW, 'en'));

        $this->expectException(ToolCallException::class);

        $this->tool->getArticle('en', 'test-uuid-123');
    }
}
