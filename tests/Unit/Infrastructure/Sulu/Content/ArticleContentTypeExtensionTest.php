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

namespace Sulu\Mcp\Tests\Unit\Infrastructure\Sulu\Content;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Article\Domain\Model\Article;
use Sulu\Article\Domain\Model\ArticleDimensionContent;
use Sulu\Article\Domain\Model\ArticleDimensionContentInterface;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormGroup;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Infrastructure\Sulu\Content\ArticleContentTypeExtension;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\Tests\Application\TestBundle\Metadata\TestGroupProvider;

#[CoversClass(ArticleContentTypeExtension::class)]
final class ArticleContentTypeExtensionTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<EntityRepository<ArticleDimensionContentInterface>> */
    private ObjectProphecy $dimensionRepository;

    private ArticleContentTypeExtension $extension;

    protected function setUp(): void
    {
        $this->dimensionRepository = $this->prophesize(EntityRepository::class);
        $entityManager = $this->prophesize(EntityManagerInterface::class);
        $entityManager->getRepository(ArticleDimensionContentInterface::class)->willReturn($this->dimensionRepository->reveal());

        $this->extension = new ArticleContentTypeExtension(
            $this->prophesize(ArticleRepositoryInterface::class)->reveal(),
            new ArticleSecurityContextResolver(new TestGroupProvider([
                (new FormGroup('default', 'Default'))->withTemplate('article'),
                (new FormGroup('blog', 'Blog'))->withTemplate('blog_article'),
            ])),
            $entityManager->reveal(),
        );
    }

    public function testASourceLocaleThatIsNotLoadedIsQueried(): void
    {
        $article = $this->ghostArticle();
        $source = new ArticleDimensionContent($article);
        $source->setTemplateKey('blog_article');
        $this->dimensionRepository->findOneBy(['article' => $article, 'locale' => 'de', 'stage' => DimensionContentInterface::STAGE_DRAFT])->willReturn($source);

        self::assertSame('sulu.article.articles_blog', $this->extension->getSecurity($article, 'en')->context);
    }

    public function testALoadedSourceLocaleIsNotQueried(): void
    {
        $article = $this->ghostArticle();
        $source = new ArticleDimensionContent($article);
        $source->setLocale('de');
        $source->setTemplateKey('blog_article');
        $article->addDimensionContent($source);
        $this->dimensionRepository->findOneBy(Argument::cetera())->willReturn(null);

        self::assertSame('sulu.article.articles_blog', $this->extension->getSecurity($article, 'en')->context);
    }

    public function testAnArticleWithoutAnySourceContentFailsClosed(): void
    {
        $article = $this->ghostArticle();
        $this->dimensionRepository->findOneBy(Argument::cetera())->willReturn(null);

        self::assertSame('', $this->extension->getSecurity($article, 'en')->context);
    }

    public function testAnObjectThatIsNotAnArticleFailsClosed(): void
    {
        self::assertSame('', $this->extension->getSecurity(new \stdClass(), 'en')->context);
    }

    private function ghostArticle(): Article
    {
        $article = new Article('uuid-1');
        $unlocalized = new ArticleDimensionContent($article);
        $unlocalized->setGhostLocale('de');
        $unlocalized->addAvailableLocale('de');
        $article->addDimensionContent($unlocalized);

        return $article;
    }
}
