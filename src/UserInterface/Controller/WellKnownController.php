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

namespace Sulu\Mcp\UserInterface\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RFC 9728 Protected Resource Metadata (PRM) and RFC 8414 Authorization Server Metadata.
 *
 * These well-known endpoints enable MCP clients (e.g., Claude.ai) to discover
 * the OAuth authorization server and its capabilities for authenticating with
 * the MCP resource server.
 *
 * @internal
 */
class WellKnownController
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        private readonly string $serverUrl,
        private readonly string $mcpPath = '/admin/_mcp',
        private readonly array $scopes = ['mcp:tools', 'mcp:resources'],
    ) {
    }

    /**
     * RFC 9728 - OAuth 2.0 Protected Resource Metadata.
     *
     * Returns metadata about the MCP resource server, including which
     * authorization servers protect it and what scopes are supported.
     */
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

    /**
     * RFC 8414 - OAuth 2.0 Authorization Server Metadata.
     *
     * Returns metadata about the OAuth authorization server, including
     * authorization and token endpoints, supported grant types, and PKCE support.
     */
    #[Route('/.well-known/oauth-authorization-server', name: 'sulu_mcp_as_metadata', methods: ['GET'])]
    public function authorizationServerMetadata(): JsonResponse
    {
        $base = rtrim($this->serverUrl, '/');

        return new JsonResponse([
            'issuer' => $base,
            'authorization_endpoint' => $base.'/admin/mcp/authorize',
            'token_endpoint' => $base.'/mcp/token',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic', 'none'],
            'scopes_supported' => $this->scopes,
            'registration_endpoint' => $base.'/mcp/register',
        ]);
    }
}
