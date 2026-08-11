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

namespace Sulu\Mcp\UserInterface\Mcp\Tool\Navigation;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;
use Sulu\Page\Domain\Repository\NavigationRepositoryInterface;

/**
 * @internal
 */
class NavigationGetTool
{
    public function __construct(
        private readonly NavigationRepositoryInterface $navigationRepository,
        private readonly WebspacePermissionResolver $webspacePermissionResolver,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'sulu_navigation_get',
        description: 'Get the published navigation tree of a webspace for one navigation context. Returns nodes with title, url, targetType, and nested "children". Only published (live) pages that are assigned to the given navigation context appear — a page missing here may simply be unpublished or not assigned to the context. Context keys are defined per webspace in config/webspaces/*.xml under <navigation><contexts> (commonly "main" or "footer"); use sulu_get_context to discover webspaces. Use sulu_page_tree instead when you need the full page hierarchy including drafts.',
    )]
    #[RequiresPermission(
        requirements: [new PermissionRequirement('sulu.webspaces.#context#', PermissionTypes::VIEW)],
        objectResolved: true,
        discoveryContexts: [WebspacePermissionResolver::ANY_WEBSPACE_CONTEXT],
    )]
    public function getNavigation(
        string $webspace,
        string $locale,
        #[Schema(description: 'Navigation context key as defined in the webspace XML, e.g. "main" or "footer".')]
        string $navigationContext = 'main',
        #[Schema(description: 'Maximum nesting depth of the returned tree.')]
        int $depth = 2,
    ): array {
        $permitted = $this->webspacePermissionResolver->permittedWebspaceKeys(PermissionTypes::VIEW, $locale);
        if (!\in_array($webspace, $permitted, true)) {
            return [
                'webspace' => $webspace,
                'navigation' => [],
                'hint' => 'Webspace not accessible: it does not exist or your Sulu role lacks view permission for it.',
            ];
        }

        try {
            // The property map values must name real content property paths --
            // empty values make the content resolver return no "nav" group and
            // NavigationRepository fails with 'Undefined array key "nav"'. This
            // is core's default map from NavigationTwigExtension; targetType
            // drives the repository's link-page fallback.
            $tree = $this->navigationRepository->getNavigationTree(
                $navigationContext,
                $locale,
                $webspace,
                null,
                $depth,
                ['title' => 'title', 'url' => 'url', 'targetType' => 'object.linkData[provider]'],
            );

            return [
                'navigation' => $tree,
                'webspace' => $webspace,
                'locale' => $locale,
                'context' => $navigationContext,
            ];
        } catch (\Throwable $e) {
            return [
                'error' => \sprintf('Failed to get navigation for webspace "%s": %s', $webspace, $e->getMessage()),
                'hint' => \sprintf('Verify the navigation context "%s" is defined in the webspace XML under <navigation><contexts> and that pages are published.', $navigationContext),
            ];
        }
    }
}
