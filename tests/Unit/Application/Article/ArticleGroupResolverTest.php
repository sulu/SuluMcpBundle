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
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Article\Domain\Model\Article;
use Sulu\Article\Domain\Model\ArticleDimensionContent;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormGroup;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Application\Article\ArticleGroupResolver;
use Sulu\Mcp\Tests\Application\TestBundle\Metadata\TestGroupProvider;

#[CoversClass(ArticleGroupResolver::class)]
final class ArticleGroupResolverTest extends TestCase
{
    use ProphecyTrait;

    private TestGroupProvider $groupProvider;
    /** @var ObjectProphecy<ContentManagerInterface> */
    private ObjectProphecy $contentManager;
    private ArticleGroupResolver $resolver;

    protected function setUp(): void
    {
        $this->groupProvider = new TestGroupProvider();
        $this->contentManager = $this->prophesize(ContentManagerInterface::class);
        $this->resolver = new ArticleGroupResolver($this->groupProvider, $this->contentManager->reveal());
    }

    public function testResolveByTemplateReturnsDefaultWhenTemplateIsNull(): void
    {
        $this->assertSame('default', $this->resolver->resolveByTemplate(null));
    }

    public function testResolveByTemplateReturnsDefaultWhenTemplateIsEmpty(): void
    {
        $this->assertSame('default', $this->resolver->resolveByTemplate(''));
    }

    public function testResolveByTemplateReturnsGroupIdentifierForMatchingTemplate(): void
    {
        $this->resolver = new ArticleGroupResolver(new TestGroupProvider([
            'default' => new FormGroup('default', 'Default', ['standard']),
            'blog-group' => new FormGroup('blog-group', 'Blog', ['blog', 'news']),
        ]), $this->contentManager->reveal());

        $this->assertSame('blog-group', $this->resolver->resolveByTemplate('blog'));
        $this->assertSame('blog-group', $this->resolver->resolveByTemplate('news'));
        $this->assertSame('default', $this->resolver->resolveByTemplate('standard'));
    }

    public function testResolveByTemplateFallsBackToDefaultForUnknownTemplate(): void
    {
        $this->resolver = new ArticleGroupResolver(new TestGroupProvider([
            'blog-group' => new FormGroup('blog-group', 'Blog', ['blog']),
        ]), $this->contentManager->reveal());

        $this->assertSame('default', $this->resolver->resolveByTemplate('unknown'));
    }

    public function testResolveByArticleDerivesGroupFromDraftTemplate(): void
    {
        $article = new Article();

        $dimensionContent = new ArticleDimensionContent(new Article());
        $this->contentManager->resolve($article, [
            'locale' => 'en',
            'stage' => DimensionContentInterface::STAGE_DRAFT,
        ])->shouldBeCalledOnce()
            ->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn(['template' => 'blog']);

        $this->resolver = new ArticleGroupResolver(new TestGroupProvider([
            'blog-group' => new FormGroup('blog-group', 'Blog', ['blog']),
        ]), $this->contentManager->reveal());

        $this->assertSame('blog-group', $this->resolver->resolveByArticle($article, 'en'));
    }

    public function testResolveByArticleReturnsDefaultWhenTemplateMissing(): void
    {
        $article = new Article();

        $dimensionContent = new ArticleDimensionContent(new Article());
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([]);

        $this->assertSame('default', $this->resolver->resolveByArticle($article, 'en'));
    }

    public function testResolveByArticleFallsBackToDefaultOnException(): void
    {
        $article = new Article();

        $this->contentManager->resolve(Argument::cetera())->willThrow(new \RuntimeException('boom'));

        $this->assertSame('default', $this->resolver->resolveByArticle($article, 'en'));
    }
}
