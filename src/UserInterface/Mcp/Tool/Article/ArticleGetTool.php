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

namespace Sulu\Mcp\UserInterface\Mcp\Tool\Article;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Sulu\Article\Domain\Exception\ArticleNotFoundException;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Application\Content\ContentNormalizerTrait;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;

/**
 * @internal
 */
class ArticleGetTool
{
    use ContentNormalizerTrait;

    public function __construct(
        private readonly ArticleRepositoryInterface $articleRepository,
        private readonly ContentManagerInterface $contentManager,
        private readonly ToolPermissionCheckerInterface $permissionChecker,
        private readonly ArticleSecurityContextResolver $articleContextResolver,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'sulu_article_get',
        description: 'Get a single article by its UUID. Returns draft metadata, template fields, block summaries (index, _id, type, title), and SEO/excerpt data. Use sulu_block_list with type="article" to fetch full block content. Always call this before sulu_article_update.',
    )]
    #[RequiresPermission(
        requirements: [new PermissionRequirement('sulu.article.articles', PermissionTypes::VIEW)],
        objectResolved: true,
        discoveryContexts: [ArticleSecurityContextResolver::ANY_ARTICLE_GROUP_CONTEXT],
    )]
    public function getArticle(string $locale, string $uuid): array
    {
        try {
            $article = $this->articleRepository->getOneBy(
                [
                    'uuid' => $uuid,
                    'locale' => $locale,
                    'stage' => DimensionContentInterface::STAGE_DRAFT,
                ],
                [
                    ArticleRepositoryInterface::GROUP_SELECT_ARTICLE_ADMIN => true,
                ],
            );

            $dimensionContent = $this->contentManager->resolve($article, [
                'locale' => $locale,
                'stage' => DimensionContentInterface::STAGE_DRAFT,
            ]);

            $templateKey = $dimensionContent->getTemplateKey() ?? '';
            $this->permissionChecker->check(
                $this->articleContextResolver->forTemplateKey($templateKey),
                PermissionTypes::VIEW,
                $locale,
            );

            $normalized = $this->contentManager->normalize($dimensionContent);

            $compacted = $this->compactContent($normalized, $this->detectBlockProperties($normalized));

            return [
                'uuid' => $article->getUuid(),
                'locale' => $locale,
                'data' => $compacted,
            ];
        } catch (PermissionDeniedException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        } catch (ArticleNotFoundException) {
            return [
                'error' => 'Article not found: ' . $uuid,
                'hint' => 'Verify the UUID and locale. Use sulu_article_list or sulu_content_search to find articles.',
            ];
        }
    }
}
