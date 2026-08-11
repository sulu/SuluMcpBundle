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
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;

/**
 * @internal
 */
class ArticleListTool
{
    private const SUMMARY_FIELDS = [
        'title', 'template', 'url', 'locale', 'stage',
        'published', 'publishedState', 'workflowPlace',
        'authored', 'author', 'created', 'changed',
        'availableLocales', 'contentLocales', 'ghostLocale',
        'shadowOn', 'shadowLocale',
        'mainWebspace',
    ];

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
        name: 'sulu_article_list',
        description: 'List articles with optional filters. Returns lightweight summaries (title, template, URL, workflow state, dates) — no blocks or HTML content. Use sulu_article_get with a UUID to fetch the full content of a specific article. Use "template" to filter by template key (e.g. "blog", "default"). Results are paginated — use "page" and "limit" to control.',
    )]
    #[RequiresPermission(
        requirements: [new PermissionRequirement('sulu.article.articles', PermissionTypes::VIEW)],
        objectResolved: true,
        discoveryContexts: [ArticleSecurityContextResolver::ANY_ARTICLE_GROUP_CONTEXT],
    )]
    public function listArticles(
        string $locale,
        ?string $template = null,
        int $page = 1,
        int $limit = 20,
    ): array {
        // Constrain to the templates of every article group the user may read, so rows
        // and `total` agree. countBy() and findIdentifiersBy() build their query without
        // the admin select group, so templateKeys applies cleanly there.
        /** @var list<string> $permittedTemplates */
        $permittedTemplates = [];
        foreach ($this->articleContextResolver->templateKeysByContext() as $context => $templateKeys) {
            if ($this->permissionChecker->has($context, PermissionTypes::VIEW, $locale)) {
                $permittedTemplates = [...$permittedTemplates, ...$templateKeys];
            }
        }
        $permittedTemplates = \array_values(\array_unique($permittedTemplates));

        if (null !== $template) {
            $permittedTemplates = \array_values(\array_intersect($permittedTemplates, [$template]));
        }

        if ([] === $permittedTemplates) {
            return ['articles' => [], 'total' => 0, 'page' => $page, 'limit' => $limit];
        }

        $filters = [
            'locale' => $locale,
            'stage' => DimensionContentInterface::STAGE_DRAFT,
            'page' => $page,
            'limit' => $limit,
            'templateKeys' => $permittedTemplates,
        ];

        $total = $this->articleRepository->countBy($filters);

        // Two-step paging; see PageListTool. A limit on the admin select truncates
        // fetch-joined SQL rows rather than articles.
        $uuids = [...$this->articleRepository->findIdentifiersBy($filters, ['title' => 'asc'])];
        if ([] === $uuids) {
            return ['articles' => [], 'total' => $total, 'page' => $page, 'limit' => $limit];
        }

        $articles = $this->articleRepository->findBy(
            ['uuids' => $uuids, 'locale' => $locale, 'stage' => DimensionContentInterface::STAGE_DRAFT],
            ['title' => 'asc'],
            [ArticleRepositoryInterface::GROUP_SELECT_ARTICLE_ADMIN => true],
        );

        $results = [];
        foreach ($articles as $articleEntity) {
            $dimensionContent = $this->contentManager->resolve($articleEntity, [
                'locale' => $locale,
                'stage' => DimensionContentInterface::STAGE_DRAFT,
            ]);

            $normalized = $this->contentManager->normalize($dimensionContent);

            $summary = [];
            foreach (self::SUMMARY_FIELDS as $field) {
                if (\array_key_exists($field, $normalized)) {
                    $summary[$field] = $normalized[$field];
                }
            }

            $results[] = [
                'uuid' => $articleEntity->getUuid(),
                'data' => $summary,
            ];
        }

        return [
            'articles' => $results,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }
}
