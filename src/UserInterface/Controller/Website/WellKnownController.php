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

namespace Sulu\Mcp\UserInterface\Controller\Website;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Discovery endpoints MCP clients use to find the OAuth authorization server:
 * RFC 9728 Protected Resource Metadata and RFC 8414 Authorization Server Metadata.
 *
 * @internal
 */
class WellKnownController
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $serverUrl,
        private readonly string $mcpPath = '/admin/mcp',
        private readonly array $scopes = ['mcp:tools', 'mcp:resources'],
    ) {
    }

    #[Route('/.well-known/oauth-protected-resource', name: 'sulu_mcp_prm', methods: ['GET'])]
    public function protectedResourceMetadata(): JsonResponse
    {
        return new JsonResponse([
            'resource' => rtrim($this->serverUrl, '/').$this->mcpPath,
            'authorization_servers' => [rtrim($this->serverUrl, '/')],
            'scopes_supported' => $this->scopes,
            'bearer_methods_supported' => ['header'],
        ]);
    }

    #[Route('/.well-known/oauth-authorization-server', name: 'sulu_mcp_as_metadata', methods: ['GET'])]
    public function authorizationServerMetadata(): JsonResponse
    {
        $base = rtrim($this->serverUrl, '/');

        return new JsonResponse([
            'issuer' => $base,
            'authorization_endpoint' => $base.$this->routePath('sulu_mcp_oauth_authorize'),
            'token_endpoint' => $base.$this->routePath('sulu_mcp_oauth_token'),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic', 'none'],
            'scopes_supported' => $this->scopes,
            'registration_endpoint' => $base.$this->routePath('sulu_mcp_client_registration'),
        ]);
    }

    private function routePath(string $route): string
    {
        return $this->urlGenerator->generate($route, [], UrlGeneratorInterface::ABSOLUTE_PATH);
    }
}
