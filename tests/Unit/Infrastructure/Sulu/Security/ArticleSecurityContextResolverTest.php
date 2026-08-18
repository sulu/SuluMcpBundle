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

namespace Sulu\Mcp\Tests\Unit\Infrastructure\Sulu\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormGroup;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\Tests\Application\TestBundle\Metadata\TestGroupProvider;

#[CoversClass(ArticleSecurityContextResolver::class)]
final class ArticleSecurityContextResolverTest extends TestCase
{
    public function testDefaultGroupYieldsBaseContext(): void
    {
        $groupProvider = new TestGroupProvider([
            (new FormGroup('default', 'Default'))->withTemplate('default'),
        ]);
        $resolver = new ArticleSecurityContextResolver($groupProvider);

        self::assertSame('sulu.article.articles', $resolver->forTemplateKey('default'));
    }

    public function testNamedGroupYieldsSuffixedContext(): void
    {
        $groupProvider = new TestGroupProvider([
            (new FormGroup('default', 'Default'))->withTemplate('default'),
            (new FormGroup('blog', 'Blog'))->withTemplate('blog_article'),
        ]);
        $resolver = new ArticleSecurityContextResolver($groupProvider);

        self::assertSame('sulu.article.articles_blog', $resolver->forTemplateKey('blog_article'));
    }

    public function testUnmatchedTemplateInMultiGroupInstallYieldsUnresolvableContext(): void
    {
        $groupProvider = new TestGroupProvider([
            (new FormGroup('default', 'Default'))->withTemplate('default'),
            (new FormGroup('blog', 'Blog'))->withTemplate('blog_article'),
        ]);
        $resolver = new ArticleSecurityContextResolver($groupProvider);

        self::assertSame('', $resolver->forTemplateKey('orphaned_template'));
    }

    public function testCandidatesYieldsOnlyBaseContextForSingleGroup(): void
    {
        $groupProvider = new TestGroupProvider([
            (new FormGroup('default', 'Default'))->withTemplate('default'),
        ]);
        $resolver = new ArticleSecurityContextResolver($groupProvider);

        self::assertSame(['sulu.article.articles'], $resolver->candidates());
    }

    public function testCandidatesYieldsBaseAndPerGroupContexts(): void
    {
        $groupProvider = new TestGroupProvider([
            (new FormGroup('default', 'Default'))->withTemplate('default'),
            (new FormGroup('blog', 'Blog'))->withTemplate('blog_article'),
        ]);
        $resolver = new ArticleSecurityContextResolver($groupProvider);

        self::assertSame(['sulu.article.articles', 'sulu.article.articles_blog'], $resolver->candidates());
    }
}
