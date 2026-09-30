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
use Sulu\Article\Domain\Model\Article;
use Sulu\Article\Domain\Model\ArticleDimensionContent;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormGroup;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Application\Security\ContentSecurityContextResolver;
use Sulu\Mcp\Domain\Content\ContentSecurity;
use Sulu\Mcp\Tests\Application\TestBundle\Metadata\TestGroupProvider;
use Sulu\Mcp\Tests\Unit\Fixture\ContentTypes;
use Sulu\Mcp\Tests\Unit\Fixture\FakeContentTypeExtension;
use Sulu\Page\Domain\Model\Page;

#[CoversClass(ContentSecurityContextResolver::class)]
final class ContentSecurityContextResolverTest extends TestCase
{
    public function testForEntityReturnsPageSecurity(): void
    {
        $page = new Page();
        $page->setWebspaceKey('example');

        self::assertEquals(
            new ContentSecurity('sulu.webspaces.example', Page::class, 'example'),
            $this->resolver()->forEntity('page', $page),
        );
    }

    public function testForEntityReturnsEmptyContextWhenPageAggregateIsNotAPage(): void
    {
        self::assertSame('', $this->resolver()->forEntity('page', new \stdClass())->context);
    }

    public function testForEntityDerivesTheArticleGroupFromTheAggregateTemplateKey(): void
    {
        $article = $this->articleWithTemplateKey('blog_article');

        self::assertEquals(new ContentSecurity('sulu.article.articles_blog'), $this->multiGroupResolver()->forEntity('article', $article));
    }

    public function testForEntityIgnoresLiveDimensionContentsOfAnArticle(): void
    {
        $article = new Article();
        $live = new ArticleDimensionContent($article);
        $live->setStage(DimensionContentInterface::STAGE_LIVE);
        $live->setTemplateKey('blog_article');
        $article->addDimensionContent($live);

        self::assertSame('', $this->multiGroupResolver()->forEntity('article', $article)->context);
    }

    public function testForEntityFailsClosedForAnArticleWithoutTemplateKeyInAMultiGroupInstall(): void
    {
        self::assertSame('', $this->multiGroupResolver()->forEntity('article', new Article())->context);
    }

    public function testForEntityUsesTheBaseContextForAnArticleWithoutTemplateKeyInASingleGroupInstall(): void
    {
        self::assertSame('sulu.article.articles', $this->resolver()->forEntity('article', new Article())->context);
    }

    public function testForEntityReturnsSnippetsContextRegardlessOfAggregate(): void
    {
        self::assertEquals(new ContentSecurity('sulu.snippet.snippets'), $this->resolver()->forEntity('snippet', new \stdClass()));
    }

    public function testForEntityDefaultsToEmptyContextForUnknownType(): void
    {
        self::assertEquals(new ContentSecurity(''), $this->resolver()->forEntity('unknown', new \stdClass()));
    }

    public function testForEntityDelegatesToARegisteredExtension(): void
    {
        $groupProvider = new TestGroupProvider([
            (new FormGroup('default', 'Default'))->withTemplate('default'),
        ]);
        $resolver = ContentTypes::securityResolver($groupProvider, [new FakeContentTypeExtension()]);

        self::assertSame('sulu.widget.widgets', $resolver->forEntity('widget', new \stdClass())->context);
    }

    private function articleWithTemplateKey(string $templateKey): Article
    {
        $article = new Article();
        $dimensionContent = new ArticleDimensionContent($article);
        $dimensionContent->setLocale('en');
        $dimensionContent->setTemplateKey($templateKey);
        $article->addDimensionContent($dimensionContent);

        return $article;
    }

    private function resolver(): ContentSecurityContextResolver
    {
        return ContentTypes::securityResolver(new TestGroupProvider([
            (new FormGroup('default', 'Default'))->withTemplate('default'),
        ]));
    }

    private function multiGroupResolver(): ContentSecurityContextResolver
    {
        return ContentTypes::securityResolver(new TestGroupProvider([
            (new FormGroup('default', 'Default'))->withTemplate('default'),
            (new FormGroup('blog', 'Blog'))->withTemplate('blog_article'),
        ]));
    }
}
