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

namespace Sulu\Mcp\Tests\Unit\Application\Article;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sulu\Article\Domain\Model\ArticleInterface;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormGroup;
use Sulu\Bundle\AdminBundle\Metadata\GroupProviderInterface;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Application\Article\ArticleGroupResolver;

#[CoversClass(ArticleGroupResolver::class)]
final class ArticleGroupResolverTest extends TestCase
{
    private GroupProviderInterface&MockObject $groupProvider;
    private ContentManagerInterface&MockObject $contentManager;
    private ArticleGroupResolver $resolver;

    protected function setUp(): void
    {
        $this->groupProvider = $this->createMock(GroupProviderInterface::class);
        $this->contentManager = $this->createMock(ContentManagerInterface::class);
        $this->resolver = new ArticleGroupResolver($this->groupProvider, $this->contentManager);
    }

    public function testResolveByTemplateReturnsDefaultWhenTemplateIsNull(): void
    {
        $this->groupProvider->expects($this->never())->method('getGroups');

        $this->assertSame('default', $this->resolver->resolveByTemplate(null));
    }

    public function testResolveByTemplateReturnsDefaultWhenTemplateIsEmpty(): void
    {
        $this->assertSame('default', $this->resolver->resolveByTemplate(''));
    }

    public function testResolveByTemplateReturnsGroupIdentifierForMatchingTemplate(): void
    {
        $this->groupProvider->method('getGroups')->willReturn([
            'default' => new FormGroup('default', 'Default', ['standard']),
            'blog-group' => new FormGroup('blog-group', 'Blog', ['blog', 'news']),
        ]);

        $this->assertSame('blog-group', $this->resolver->resolveByTemplate('blog'));
        $this->assertSame('blog-group', $this->resolver->resolveByTemplate('news'));
        $this->assertSame('default', $this->resolver->resolveByTemplate('standard'));
    }

    public function testResolveByTemplateFallsBackToDefaultForUnknownTemplate(): void
    {
        $this->groupProvider->method('getGroups')->willReturn([
            'blog-group' => new FormGroup('blog-group', 'Blog', ['blog']),
        ]);

        $this->assertSame('default', $this->resolver->resolveByTemplate('unknown'));
    }

    public function testResolveByArticleDerivesGroupFromDraftTemplate(): void
    {
        $article = $this->createMock(ArticleInterface::class);

        $dimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->expects($this->once())
            ->method('resolve')
            ->with($article, [
                'locale' => 'en',
                'stage' => DimensionContentInterface::STAGE_DRAFT,
            ])
            ->willReturn($dimensionContent);
        $this->contentManager->method('normalize')->willReturn(['template' => 'blog']);

        $this->groupProvider->method('getGroups')->willReturn([
            'blog-group' => new FormGroup('blog-group', 'Blog', ['blog']),
        ]);

        $this->assertSame('blog-group', $this->resolver->resolveByArticle($article, 'en'));
    }

    public function testResolveByArticleReturnsDefaultWhenTemplateMissing(): void
    {
        $article = $this->createMock(ArticleInterface::class);

        $dimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->method('resolve')->willReturn($dimensionContent);
        $this->contentManager->method('normalize')->willReturn([]);

        $this->assertSame('default', $this->resolver->resolveByArticle($article, 'en'));
    }

    public function testResolveByArticleFallsBackToDefaultOnException(): void
    {
        $article = $this->createMock(ArticleInterface::class);

        $this->contentManager->method('resolve')
            ->willThrowException(new \RuntimeException('boom'));

        $this->assertSame('default', $this->resolver->resolveByArticle($article, 'en'));
    }
}
