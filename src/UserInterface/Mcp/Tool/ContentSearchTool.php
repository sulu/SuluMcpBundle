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

namespace Sulu\Mcp\UserInterface\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Application\Search\ContentSearch;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;

/**
 * MCP adapter for ContentSearch: declares the tool's schema and permission gate, everything
 * else is that class's job.
 *
 * @internal
 */
class ContentSearchTool
{
    public function __construct(
        private readonly ContentSearch $contentSearch,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'sulu_content_search',
        title: 'Search Content',
        description: 'Search published website content (articles, pages, and products when SuluProductBundle is installed) by keyword. Searches titles and full content text, including product code and family as free text, not a structured attribute filter. Returns matching items with their UUID and resource type. Use resourceKey to pick the right get tool (sulu_article_get, sulu_page_get, or sulu_product_get, which also resolves a variant) and resourceId as the UUID. Filter by type ("page", "article" or "product") to restrict results to one content type. Filter by webspace to scope results to one site. Only published content is searchable.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    #[RequiresPermission(
        requirements: [new PermissionRequirement('#context#', PermissionTypes::VIEW)],
        objectResolved: true,
        discoveryContexts: [WebspacePermissionResolver::ANY_WEBSPACE_CONTEXT],
    )]
    public function search(
        string $query,
        string $locale,
        #[Schema(description: 'Webspace key to restrict results to one site (e.g. "example"). Omit to search all webspaces.')]
        ?string $webspace = null,
        #[Schema(description: 'Content type to search. Valid values: "page", "article" or "product" (only when SuluProductBundle is installed). Omit to search all.', enum: ['page', 'article', 'product'])]
        ?string $type = null,
        int $page = 1,
        int $limit = 20,
    ): array {
        return $this->contentSearch->search($query, $locale, $webspace, $type, $page, $limit);
    }
}
