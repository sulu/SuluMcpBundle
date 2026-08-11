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

namespace Sulu\Mcp\UserInterface\Mcp\Tool\Snippet;

use Mcp\Capability\Attribute\McpTool;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;

/**
 * @internal
 */
class SnippetListTool
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
        private readonly SnippetRepositoryInterface $snippetRepository,
        private readonly ContentManagerInterface $contentManager,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'sulu_snippet_list',
        description: 'List snippets with optional template filter. Snippets are global reusable content. Returns lightweight summaries (title, template, workflow state, dates) — no blocks or HTML content. Use sulu_snippet_get with a UUID to fetch the full content of a specific snippet. Results are paginated — use "page" and "limit" to control.',
    )]
    #[RequiresPermission(requirements: [
        new PermissionRequirement('sulu.snippet.snippets', PermissionTypes::VIEW),
    ])]
    public function listSnippets(string $locale, ?string $template = null, int $page = 1, int $limit = 20): array
    {
        try {
            $filters = [
                'locale' => $locale,
                'stage' => DimensionContentInterface::STAGE_DRAFT,
                'page' => $page,
                'limit' => $limit,
            ];

            if (null !== $template) {
                $filters['templateKeys'] = [$template];
            }

            $selects = [
                SnippetRepositoryInterface::GROUP_SELECT_SNIPPET_ADMIN => true,
            ];

            $total = $this->snippetRepository->countBy($filters);

            // Two-step paging; see PageListTool. A limit on the admin select truncates
            // fetch-joined SQL rows rather than snippets.
            $uuids = [...$this->snippetRepository->findIdentifiersBy($filters, [])];
            if ([] === $uuids) {
                return ['snippets' => [], 'total' => $total, 'page' => $page, 'limit' => $limit];
            }

            $snippets = $this->snippetRepository->findBy(
                ['uuids' => $uuids, 'locale' => $locale, 'stage' => DimensionContentInterface::STAGE_DRAFT],
                [],
                $selects,
            );

            $results = [];
            foreach ($snippets as $snippet) {
                $dimensionContent = $this->contentManager->resolve($snippet, [
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
                    'uuid' => $snippet->getUuid(),
                    'data' => $summary,
                ];
            }

            return [
                'snippets' => $results,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
            ];
        } catch (\Throwable $e) {
            return [
                'error' => \sprintf('Failed to list snippets: %s', $e->getMessage()),
            ];
        }
    }
}
