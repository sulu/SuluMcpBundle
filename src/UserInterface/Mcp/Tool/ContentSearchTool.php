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
use Sulu\Mcp\Application\Content\ContentTypeSchemaExpander;
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
        description: 'Search published website content by keyword. Searches the resource keys {searchableResourceKeys}. Searches titles and full content text as free text, not a structured attribute filter. Returns matching items with their UUID and resource key. Use the returned resourceKey to pick the right get tool (e.g. sulu_page_get for "pages", sulu_article_get for "articles", or a registered type\'s own get tool) and resourceId as the UUID. Pass "resourceKey" to restrict results to one content type. The parameter is named "resourceKey". The former "type" is no longer accepted: it is ignored and the search covers all types. Filter by webspace to scope results to one site. Only published content is searchable.',
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
        #[Schema(description: 'ResourceKey of the content type to search: {searchableResourceKeys}. Omit to search all.', enum: [ContentTypeSchemaExpander::SEARCHABLE_RESOURCE_KEYS])]
        ?string $resourceKey = null,
        int $page = 1,
        int $limit = 20,
    ): array {
        return $this->contentSearch->search($query, $locale, $webspace, $resourceKey, $page, $limit);
    }
}
