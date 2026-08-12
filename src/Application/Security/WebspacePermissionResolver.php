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

namespace Sulu\Mcp\Application\Security;

use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;

/**
 * Resolves the webspace keys the current user holds a given permission on, over
 * the per-webspace security context `sulu.webspaces.<key>`. Mirrors
 * PageAdmin::getFirstWebspaceWithPermissions but returns every match.
 *
 * @internal
 */
final readonly class WebspacePermissionResolver
{
    /**
     * Sentinel for objectResolved page tools: the real context is only known at
     * runtime, so the coarse check asks "any webspace?" instead of a fixed
     * candidate. The `#` keeps it fail-closed if checked directly.
     */
    public const ANY_WEBSPACE_CONTEXT = 'sulu.webspaces.#any#';

    private const CONTEXT_PREFIX = 'sulu.webspaces.';

    public function __construct(
        private WebspaceManagerInterface $webspaceManager,
        private ToolPermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @return list<string>
     */
    public function permittedWebspaceKeys(string $permission, ?string $locale = null): array
    {
        $keys = [];
        foreach ($this->webspaceManager->getWebspaceCollection() as $webspace) {
            $key = $webspace->getKey();
            if ($this->permissionChecker->has(self::CONTEXT_PREFIX . $key, $permission, $locale)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
