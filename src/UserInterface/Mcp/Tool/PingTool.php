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
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * @internal
 */
class PingTool
{
    public function __construct(
        private readonly WebspaceManagerInterface $webspaceManager,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly WebspacePermissionResolver $webspacePermissionResolver,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'sulu_ping',
        title: 'Ping Server',
        description: 'Verify MCP connection and authentication. Returns server info, the authenticated user, and available webspaces.',
    )]
    public function ping(): array
    {
        $token = $this->tokenStorage->getToken();
        $username = $token?->getUserIdentifier();

        $result = [
            'status' => 'ok',
            'server' => 'sulu-mcp-server',
            'version' => '0.1.0',
            'user' => $username,
            'webspaces' => [],
        ];

        $permitted = $this->webspacePermissionResolver->permittedWebspaceKeys(PermissionTypes::VIEW);

        $webspaces = $this->webspaceManager->getWebspaceCollection()->getWebspaces();
        foreach ($webspaces as $webspace) {
            if (!\in_array($webspace->getKey(), $permitted, true)) {
                continue;
            }

            $locales = \array_map(
                fn ($l) => $l->getLocale(),
                $webspace->getAllLocalizations()
            );
            $result['webspaces'][] = [
                'key' => $webspace->getKey(),
                'name' => $webspace->getName(),
                'locales' => $locales,
            ];
        }

        return $result;
    }
}
