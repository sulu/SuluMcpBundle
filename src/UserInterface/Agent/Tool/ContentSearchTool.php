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

namespace Sulu\Mcp\UserInterface\Agent\Tool;

use Sulu\Mcp\Application\Search\ContentSearch;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Wraps ContentSearch for a symfony/ai-agent Toolbox: the agent-side counterpart to
 * Mcp\Tool\ContentSearchTool, same search, a different calling convention.
 *
 * @internal
 */
#[AsTool(
    name: 'sulu_content_search',
    description: 'Search published website content by keyword, across the resource keys "pages", "articles" and any resource key a bundle registers. Searches titles and full content text as free text, not a structured attribute filter. Returns matching items with their UUID and resource key. Pass "resourceKey" ("pages", "articles" or a registered resource key) to restrict results to one content type. Filter by webspace to scope results to one site. Only published content is searchable.',
)]
final class ContentSearchTool
{
    public function __construct(
        private readonly ContentSearch $contentSearch,
    ) {
    }

    /**
     * @param string $query search keyword
     * @param string $locale IETF locale of the request, e.g. "en", "de".
     * @param string|null $webspace Webspace key to restrict results to one site (e.g.
     *                              "example"). Omit to search all webspaces.
     * @param string|null $resourceKey ResourceKey of the content type to search: "pages",
     *                                 "articles" or the resource key a bundle registers.
     *                                 Omit to search all.
     * @param int $page page number, 1-based
     * @param int $limit maximum number of results per page
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        string $query,
        string $locale,
        ?string $webspace = null,
        ?string $resourceKey = null,
        int $page = 1,
        int $limit = 20,
    ): array {
        return $this->contentSearch->search($query, $locale, $webspace, $resourceKey, $page, $limit);
    }
}
