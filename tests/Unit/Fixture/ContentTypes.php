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

namespace Sulu\Mcp\Tests\Unit\Fixture;

use Doctrine\ORM\EntityManagerInterface;
use Prophecy\Prophet;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Bundle\AdminBundle\Metadata\GroupProviderInterface;
use Sulu\Bundle\SecurityBundle\System\SystemStoreInterface;
use Sulu\Component\Security\Authorization\AccessControl\AccessControlRepositoryInterface;
use Sulu\Mcp\Application\Content\ContentTypeExtensionRegistry;
use Sulu\Mcp\Application\Content\ContentTypeResolver;
use Sulu\Mcp\Application\Security\ContentSecurityContextResolver;
use Sulu\Mcp\Application\Security\PageDescendantPermissionChecker;
use Sulu\Mcp\Domain\Content\ContentTypeExtensionInterface;
use Sulu\Mcp\Infrastructure\Sulu\Content\ArticleContentTypeExtension;
use Sulu\Mcp\Infrastructure\Sulu\Content\PageContentTypeExtension;
use Sulu\Mcp\Infrastructure\Sulu\Content\SnippetContentTypeExtension;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\Tests\Application\TestBundle\Metadata\TestGroupProvider;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;

/**
 * @internal
 */
final class ContentTypes
{
    /**
     * @param list<ContentTypeExtensionInterface> $extensions
     */
    public static function resolver(
        PageRepositoryInterface $pageRepository,
        ArticleRepositoryInterface $articleRepository,
        SnippetRepositoryInterface $snippetRepository,
        GroupProviderInterface|ArticleSecurityContextResolver|null $groupProvider = null,
        ?PageDescendantPermissionChecker $pageDescendantChecker = null,
        array $extensions = [],
    ): ContentTypeResolver {
        return new ContentTypeResolver(self::registry($pageRepository, $articleRepository, $groupProvider, $pageDescendantChecker, $extensions, $snippetRepository));
    }

    /**
     * @param list<ContentTypeExtensionInterface> $extensions
     */
    public static function registry(
        PageRepositoryInterface $pageRepository,
        ArticleRepositoryInterface $articleRepository,
        GroupProviderInterface|ArticleSecurityContextResolver|null $groupProvider = null,
        ?PageDescendantPermissionChecker $pageDescendantChecker = null,
        array $extensions = [],
        ?SnippetRepositoryInterface $snippetRepository = null,
    ): ContentTypeExtensionRegistry {
        return new ContentTypeExtensionRegistry([
            new PageContentTypeExtension($pageRepository, $pageDescendantChecker ?? self::descendantChecker($pageRepository)),
            new ArticleContentTypeExtension($articleRepository, $groupProvider instanceof ArticleSecurityContextResolver ? $groupProvider : new ArticleSecurityContextResolver($groupProvider ?? new TestGroupProvider([])), (new Prophet())->prophesize(EntityManagerInterface::class)->reveal()),
            new SnippetContentTypeExtension($snippetRepository ?? (new Prophet())->prophesize(SnippetRepositoryInterface::class)->reveal()),
            ...$extensions,
        ]);
    }

    /**
     * @param list<ContentTypeExtensionInterface> $extensions
     */
    public static function inertResolver(array $extensions = [], GroupProviderInterface|ArticleSecurityContextResolver|null $groupProvider = null): ContentTypeResolver
    {
        $prophet = new Prophet();

        return self::resolver(
            $prophet->prophesize(PageRepositoryInterface::class)->reveal(),
            $prophet->prophesize(ArticleRepositoryInterface::class)->reveal(),
            $prophet->prophesize(SnippetRepositoryInterface::class)->reveal(),
            $groupProvider,
            null,
            $extensions,
        );
    }

    /**
     * @param list<ContentTypeExtensionInterface> $extensions
     */
    public static function securityResolver(GroupProviderInterface|ArticleSecurityContextResolver|null $groupProvider = null, array $extensions = []): ContentSecurityContextResolver
    {
        return new ContentSecurityContextResolver(self::inertResolver($extensions, $groupProvider));
    }

    private static function descendantChecker(PageRepositoryInterface $pageRepository): PageDescendantPermissionChecker
    {
        $prophet = new Prophet();

        return new PageDescendantPermissionChecker(
            $pageRepository,
            $prophet->prophesize(AccessControlRepositoryInterface::class)->reveal(),
            $prophet->prophesize(SystemStoreInterface::class)->reveal(),
            null,
            [],
        );
    }
}
