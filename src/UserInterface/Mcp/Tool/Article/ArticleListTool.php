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
use Mcp\Capability\Attribute\Schema;
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

    private const ALLOWED_SORT_FIELDS = ['title', 'authored', 'created', 'changed', 'workflowPublished'];
    private const ALLOWED_SORT_ORDERS = ['asc', 'desc'];

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
        #[Schema(
            description: 'Field to sort articles by. "authored" is the field for "latest articles" — it is the editorial date shown to readers (settable by the author, so it can be backdated). "created" is the immutable database insertion timestamp and is usually NOT what "latest" means to a reader. "changed" is the last-edited timestamp. "workflowPublished" is when the article was last published. Defaults to "title".',
            enum: ['title', 'authored', 'created', 'changed', 'workflowPublished'],
        )]
        string $sortBy = 'title',
        #[Schema(description: 'Sort direction, "asc" or "desc". Defaults to "asc".', enum: ['asc', 'desc'])]
        string $sortOrder = 'asc',
    ): array {
        if (!\in_array($sortBy, self::ALLOWED_SORT_FIELDS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unsupported sortBy "%s". Supported: %s.', $sortBy, \implode(', ', self::ALLOWED_SORT_FIELDS)));
        }

        if (!\in_array($sortOrder, self::ALLOWED_SORT_ORDERS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unsupported sortOrder "%s". Supported: %s.', $sortOrder, \implode(', ', self::ALLOWED_SORT_ORDERS)));
        }

        $sortBys = [$sortBy => $sortOrder];

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
        $uuids = [...$this->articleRepository->findIdentifiersBy($filters, $sortBys)];
        if ([] === $uuids) {
            return ['articles' => [], 'total' => $total, 'page' => $page, 'limit' => $limit];
        }

        $articles = $this->articleRepository->findBy(
            ['uuids' => $uuids, 'locale' => $locale, 'stage' => DimensionContentInterface::STAGE_DRAFT],
            $sortBys,
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
