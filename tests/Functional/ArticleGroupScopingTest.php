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

namespace Sulu\Mcp\Tests\Functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use Sulu\Bundle\SecurityBundle\System\SystemStoreInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Application\Security\ToolVisibilityResolver;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;

/**
 * Regression guard for per-group article permissions on a MULTI-GROUP install
 * (`article` in the default group, `blog` in the `blog` group). The bug: the
 * coarse candidate check ran off `discoveryContexts`, which only held the
 * literal `sulu.article.articles`, refusing a role granted only `..._blog`.
 */
#[CoversNothing]
final class ArticleGroupScopingTest extends FunctionalTestCase
{
    /**
     * Guards the premise of every other assertion in this file: if the dev app
     * ever collapses back to a single article group, the per-group contexts stop
     * existing and the tests below would pass vacuously.
     */
    public function testDevAppIsAMultiGroupArticleInstall(): void
    {
        $resolver = self::getContainer()->get(ArticleSecurityContextResolver::class);

        self::assertSame('sulu.article.articles', $resolver->forTemplateKey('article'));
        self::assertSame('sulu.article.articles_blog', $resolver->forTemplateKey('blog'));
        self::assertContains('sulu.article.articles_blog', $resolver->candidates());
    }

    public function testRoleWithOnlyNonDefaultArticleGroupCanUseArticleTools(): void
    {
        $this->authenticateWithArticleContext('sulu.article.articles_blog', 'BlogGroupOnly', 'blog-group-only');

        $visibility = self::getContainer()->get(ToolVisibilityResolver::class);

        foreach (['sulu_article_get', 'sulu_article_list', 'sulu_article_update'] as $tool) {
            self::assertTrue(
                $visibility->isVisible($tool, 'en'),
                \sprintf('%s must be available to a role holding only the "blog" article group.', $tool),
            );
        }
    }

    /**
     * Positive control for the default group, so the assertion above cannot pass
     * merely because article tools are visible to everyone.
     */
    public function testRoleWithOnlyDefaultArticleGroupCanUseArticleTools(): void
    {
        $this->authenticateWithArticleContext('sulu.article.articles', 'DefaultGroupOnly', 'default-group-only');

        $visibility = self::getContainer()->get(ToolVisibilityResolver::class);

        self::assertTrue($visibility->isVisible('sulu_article_list', 'en'));
    }

    /**
     * Negative control: the sentinel must expand to the declared article groups
     * only -- it must not degrade into "any article permission at all".
     */
    public function testRoleWithNoArticleGroupCannotUseArticleTools(): void
    {
        $this->authenticateWithArticleContext('sulu.settings.tags', 'NoArticleGroup', 'no-article-group');

        $visibility = self::getContainer()->get(ToolVisibilityResolver::class);

        foreach (['sulu_article_get', 'sulu_article_list', 'sulu_article_update'] as $tool) {
            self::assertFalse(
                $visibility->isVisible($tool, 'en'),
                \sprintf('%s must stay hidden from a role holding no article group.', $tool),
            );
        }
    }

    private function authenticateWithArticleContext(string $context, string $roleName, string $username): void
    {
        $container = self::getContainer();

        $builder = new PermissionFixtureBuilder(
            $this->entityManager,
            $container->get('sulu_security.mask_converter'),
            $container->get('security.token_storage'),
            $container->get(SystemStoreInterface::class),
        );

        $role = $builder->role($roleName, [
            $context => [PermissionTypes::VIEW => true, PermissionTypes::EDIT => true],
        ]);

        $builder->authenticate($builder->user($username, $role));
    }
}
