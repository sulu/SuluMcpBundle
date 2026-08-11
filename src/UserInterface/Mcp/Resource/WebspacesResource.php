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

namespace Sulu\Mcp\UserInterface\Mcp\Resource;

use Mcp\Capability\Attribute\McpResource;
use Sulu\Component\Webspace\Exception\EnvironmentNotFoundException;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Component\Webspace\Webspace;

/**
 * @internal
 */
class WebspacesResource
{
    public function __construct(
        private readonly WebspaceManagerInterface $webspaceManager,
    ) {
    }

    /** @return list<array<string, mixed>> */
    #[McpResource(
        uri: 'sulu://webspaces',
        name: 'sulu_webspaces',
        description: 'Available Sulu webspaces with their locales and primary URLs.',
        mimeType: 'application/json',
    )]
    public function getWebspaces(): array
    {
        $result = [];
        foreach ($this->webspaceManager->getWebspaceCollection()->getWebspaces() as $webspace) {
            $locales = \array_map(
                fn ($l) => $l->getLocale(),
                $webspace->getAllLocalizations()
            );

            $result[] = [
                'key' => $webspace->getKey(),
                'name' => $webspace->getName(),
                'locales' => $locales,
                'url' => $this->getPrimaryUrl($webspace),
            ];
        }

        return $result;
    }

    /**
     * Returns the primary (prod environment) URL for a webspace.
     * Falls back to the first portal's first URL if no prod URL is found.
     */
    private function getPrimaryUrl(Webspace $webspace): ?string
    {
        // Look for the prod environment URL across all portals
        foreach ($webspace->getPortals() as $portal) {
            try {
                $env = $portal->getEnvironment('prod');
                $urls = $env->getUrls();
                if (!empty($urls)) {
                    return $urls[0]->getUrl();
                }
            } catch (EnvironmentNotFoundException) {
                // This portal has no 'prod' environment, continue
            }
        }

        // Fallback: return first URL from first portal's first environment
        foreach ($webspace->getPortals() as $portal) {
            foreach ($portal->getEnvironments() as $env) {
                $urls = $env->getUrls();
                if (!empty($urls)) {
                    return $urls[0]->getUrl();
                }
            }
        }

        return null;
    }
}
