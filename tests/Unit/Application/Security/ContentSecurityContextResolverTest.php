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

namespace Sulu\Mcp\Tests\Unit\Application\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormGroup;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\ContentRichEntityInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\TemplateInterface;
use Sulu\Mcp\Application\Security\ContentSecurityContextResolver;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\Tests\Application\TestBundle\Metadata\TestGroupProvider;
use Sulu\Page\Domain\Model\Page;

#[CoversClass(ContentSecurityContextResolver::class)]
final class ContentSecurityContextResolverTest extends TestCase
{
    use ProphecyTrait;

    public function testForEntityReturnsPageWebspaceContext(): void
    {
        $page = new Page();
        $page->setWebspaceKey('example');

        $resolver = $this->resolver();

        self::assertSame('sulu.webspaces.example', $resolver->forEntity('page', $page));
    }

    public function testForEntityReturnsEmptyStringWhenPageAggregateIsNotAPage(): void
    {
        $resolver = $this->resolver();

        self::assertSame('', $resolver->forEntity('page', new \stdClass()));
    }

    public function testForEntityDelegatesArticleTemplateKeyToArticleResolver(): void
    {
        $groupProvider = new TestGroupProvider([
            (new FormGroup('default', 'Default'))->withTemplate('default'),
            (new FormGroup('blog', 'Blog'))->withTemplate('blog_article'),
        ]);
        $resolver = new ContentSecurityContextResolver(
            new ArticleSecurityContextResolver($groupProvider),
            $this->prophesize(ContentManagerInterface::class)->reveal(),
        );

        $dimensionContent = $this->prophesize(TemplateInterface::class);
        $dimensionContent->getTemplateKey(Argument::cetera())->willReturn('blog_article');

        self::assertSame('sulu.article.articles_blog', $resolver->forEntity('article', new \stdClass(), $dimensionContent->reveal()));
    }

    public function testForEntityUsesEmptyTemplateKeyWhenDimensionContentIsMissing(): void
    {
        $resolver = $this->resolver();

        self::assertSame('sulu.article.articles', $resolver->forEntity('article', new \stdClass()));
    }

    public function testForEntityReturnsSnippetsContextRegardlessOfAggregate(): void
    {
        $resolver = $this->resolver();

        self::assertSame('sulu.snippet.snippets', $resolver->forEntity('snippet', new \stdClass()));
    }

    public function testForEntityDefaultsToEmptyStringForUnknownType(): void
    {
        $resolver = $this->resolver();

        self::assertSame('', $resolver->forEntity('unknown', new \stdClass()));
    }

    public function testForEntityInLocaleResolvesArticleGroupFromTheGhostSourceLocale(): void
    {
        $article = $this->prophesize(ContentRichEntityInterface::class)->reveal();

        $sourceDimensionContent = $this->prophesize(DimensionContentInterface::class);
        $sourceDimensionContent->willImplement(TemplateInterface::class);
        $sourceDimensionContent->getTemplateKey(Argument::cetera())->willReturn('blog_article');

        $contentManager = $this->prophesize(ContentManagerInterface::class);
        $contentManager->resolve($article, ['locale' => 'en', 'stage' => DimensionContentInterface::STAGE_DRAFT])
            ->willReturn($sourceDimensionContent->reveal())
            ->shouldBeCalled();

        $ghost = $this->prophesize(DimensionContentInterface::class);
        $ghost->getLocale(Argument::cetera())->willReturn(null);
        $ghost->getGhostLocale(Argument::cetera())->willReturn('en');

        $resolver = $this->multiGroupResolver($contentManager->reveal());

        self::assertSame(
            'sulu.article.articles_blog',
            $resolver->forEntityInLocale('article', $article, $ghost->reveal(), 'de'),
        );
    }

    public function testForEntityInLocaleUsesTheRequestedLocaleWhenTheTranslationExists(): void
    {
        $article = $this->prophesize(ContentRichEntityInterface::class)->reveal();

        $contentManager = $this->prophesize(ContentManagerInterface::class);
        $contentManager->resolve(Argument::cetera())->shouldNotBeCalled();

        $dimensionContent = $this->prophesize(DimensionContentInterface::class);
        $dimensionContent->willImplement(TemplateInterface::class);
        $dimensionContent->getLocale(Argument::cetera())->willReturn('de');
        $dimensionContent->getGhostLocale(Argument::cetera())->willReturn('en');
        $dimensionContent->getTemplateKey(Argument::cetera())->willReturn('blog_article');

        $resolver = $this->multiGroupResolver($contentManager->reveal());

        self::assertSame(
            'sulu.article.articles_blog',
            $resolver->forEntityInLocale('article', $article, $dimensionContent->reveal(), 'de'),
        );
    }

    public function testForEntityInLocaleFailsClosedForAnArticleWithoutAnySourceLocale(): void
    {
        $article = $this->prophesize(ContentRichEntityInterface::class)->reveal();

        $contentManager = $this->prophesize(ContentManagerInterface::class);
        $contentManager->resolve(Argument::cetera())->shouldNotBeCalled();

        $ghost = $this->prophesize(DimensionContentInterface::class);
        $ghost->getLocale(Argument::cetera())->willReturn(null);
        $ghost->getGhostLocale(Argument::cetera())->willReturn(null);

        $resolver = $this->multiGroupResolver($contentManager->reveal());

        self::assertSame('', $resolver->forEntityInLocale('article', $article, $ghost->reveal(), 'de'));
    }

    public function testForEntityInLocaleKeepsThePageContextOnTheAggregateForAGhost(): void
    {
        $page = new Page();
        $page->setWebspaceKey('example');

        $contentManager = $this->prophesize(ContentManagerInterface::class);
        $contentManager->resolve(Argument::cetera())->shouldNotBeCalled();

        $ghost = $this->prophesize(DimensionContentInterface::class);
        $ghost->getLocale(Argument::cetera())->willReturn(null);
        $ghost->getGhostLocale(Argument::cetera())->willReturn('en');

        $resolver = $this->multiGroupResolver($contentManager->reveal());

        self::assertSame('sulu.webspaces.example', $resolver->forEntityInLocale('page', $page, $ghost->reveal(), 'de'));
    }

    private function resolver(): ContentSecurityContextResolver
    {
        $groupProvider = new TestGroupProvider([
            (new FormGroup('default', 'Default'))->withTemplate('default'),
        ]);

        return new ContentSecurityContextResolver(
            new ArticleSecurityContextResolver($groupProvider),
            $this->prophesize(ContentManagerInterface::class)->reveal(),
        );
    }

    private function multiGroupResolver(ContentManagerInterface $contentManager): ContentSecurityContextResolver
    {
        $groupProvider = new TestGroupProvider([
            (new FormGroup('default', 'Default'))->withTemplate('default'),
            (new FormGroup('blog', 'Blog'))->withTemplate('blog_article'),
        ]);

        return new ContentSecurityContextResolver(new ArticleSecurityContextResolver($groupProvider), $contentManager);
    }
}
