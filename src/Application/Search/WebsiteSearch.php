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

namespace Sulu\Mcp\Application\Search;

use CmsIg\Seal\EngineInterface;
use CmsIg\Seal\Search\Condition\Condition;
use CmsIg\Seal\Search\SearchBuilder;

/**
 * The `website` SEAL search builder and result projection shared by every MCP tool that
 * searches it, so each caller only adds its own filters on top.
 *
 * @internal
 */
final readonly class WebsiteSearch
{
    public function __construct(
        private EngineInterface $engine,
    ) {
    }

    public function builder(string $locale, ?string $query, int $page, int $limit): SearchBuilder
    {
        $builder = $this->engine->createSearchBuilder('website')
            ->addFilter(Condition::equal('locale', $locale))
            ->limit($limit)
            ->offset(($page - 1) * $limit);

        if (null !== $query && '' !== $query) {
            $builder->addFilter(Condition::search($query));
        }

        return $builder;
    }

    /**
     * @param list<string> $extraFields document fields to copy through verbatim, in addition to
     *                                  the fields every `website` document carries
     *
     * @return array{results: list<array<string, mixed>>, total: int, page: int, limit: int}
     */
    public function run(SearchBuilder $builder, int $page, int $limit, array $extraFields = []): array
    {
        $result = $builder->getResult();

        $results = [];
        foreach ($result as $document) {
            $row = [
                'resourceKey' => $document['resourceKey'] ?? null,
                'resourceId' => $document['resourceId'] ?? null,
                'locale' => $document['locale'] ?? null,
                'title' => $document['title'] ?? null,
                'url' => $document['url'] ?? null,
                'webspaces' => $document['webspaces'] ?? [],
                'authoredAt' => $document['authoredAt'] ?? null,
                'metadata' => $document['metadata'] ?? [],
            ];

            foreach ($extraFields as $field) {
                if (\array_key_exists($field, $document)) {
                    $row[$field] = $document[$field];
                }
            }

            $results[] = $row;
        }

        return [
            'results' => $results,
            'total' => $result->total(),
            'page' => $page,
            'limit' => $limit,
        ];
    }
}
