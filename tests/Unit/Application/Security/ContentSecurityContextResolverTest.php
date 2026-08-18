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
        $resolver = new ContentSecurityContextResolver(new ArticleSecurityContextResolver($groupProvider));

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

    private function resolver(): ContentSecurityContextResolver
    {
        $groupProvider = new TestGroupProvider([
            (new FormGroup('default', 'Default'))->withTemplate('default'),
        ]);

        return new ContentSecurityContextResolver(new ArticleSecurityContextResolver($groupProvider));
    }
}
