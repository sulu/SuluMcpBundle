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

namespace Sulu\Mcp\Infrastructure\Symfony\Security\EventListener;

use League\Bundle\OAuth2ServerBundle\Security\Authentication\Token\OAuth2Token;
use Sulu\Mcp\Infrastructure\Symfony\Security\EntryPoint\McpAuthenticationEntryPoint;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Rejects MCP requests whose access token lacks the scope the JSON-RPC method needs.
 *
 * The firewall only proves the bearer token is valid; it cannot check scopes, because the
 * whole transport is a single POST route and the method that says what is being accessed
 * sits in the JSON-RPC body. Without this listener every token the shared authorization
 * server issued — including one a project's own client obtained for unrelated scopes —
 * would drive tools and resources with the authorizing user's full Sulu permissions.
 *
 * @internal
 */
final readonly class McpScopeListener
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private McpAuthenticationEntryPoint $entryPoint,
        private string $mcpPath,
        private array $scopes,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        // Decoded, like the router and the firewall's PathRequestMatcher.
        if (\rawurldecode($request->getPathInfo()) !== $this->mcpPath) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if (!$token instanceof OAuth2Token) {
            return;
        }

        $content = $request->isMethod(Request::METHOD_POST) ? $request->getContent() : '';
        $messages = $this->messages($content);
        $requiredScopes = $this->requiredScopes($messages);
        $grantedScopes = $token->getScopes();

        $satisfied = [] === $requiredScopes
            ? [] !== \array_intersect($this->scopes, $grantedScopes)
            : [] === \array_diff($requiredScopes, $grantedScopes);

        if ($satisfied) {
            return;
        }

        $event->setResponse($this->insufficientScope($requiredScopes, $this->requestId($content, $messages)));
    }

    /**
     * Splits the body the way the SDK's MessageFactory::create() does: only a raw body
     * starting with "{" is a single message, anything else is dispatched element-wise.
     *
     * @return list<mixed>
     */
    private function messages(string $content): array
    {
        $decoded = \json_decode($content, true);
        if (!\is_array($decoded)) {
            return [];
        }

        return '{' === ($content[0] ?? '') ? [$decoded] : \array_values($decoded);
    }

    /**
     * Empty means any configured MCP scope will do.
     *
     * @param list<mixed> $messages
     *
     * @return list<string>
     */
    private function requiredScopes(array $messages): array
    {
        $scopes = [];
        foreach ($messages as $message) {
            $scope = \is_array($message) ? $this->methodScope($message['method'] ?? null) : null;
            if (null !== $scope && !\in_array($scope, $scopes, true)) {
                $scopes[] = $scope;
            }
        }

        return $scopes;
    }

    private function methodScope(mixed $method): ?string
    {
        if (!\is_string($method)) {
            return null;
        }

        if (\str_starts_with($method, 'tools/')) {
            return 'mcp:tools';
        }

        if (\str_starts_with($method, 'resources/')) {
            return 'mcp:resources';
        }

        return null;
    }

    /**
     * A batch has no id of its own.
     *
     * @param list<mixed> $messages
     */
    private function requestId(string $content, array $messages): mixed
    {
        if ('{' !== ($content[0] ?? '') || [] === $messages) {
            return null;
        }

        $message = $messages[0];

        return \is_array($message) ? ($message['id'] ?? null) : null;
    }

    /**
     * @param list<string> $requiredScopes
     */
    private function insufficientScope(array $requiredScopes, mixed $id): JsonResponse
    {
        $response = new JsonResponse([
            'jsonrpc' => '2.0',
            'error' => [
                'code' => -32003,
                'message' => 'Insufficient scope',
            ],
            'id' => $id,
        ], 403);

        $response->headers->set('WWW-Authenticate', \sprintf(
            'Bearer error="insufficient_scope", scope="%s", resource_metadata="%s"',
            \implode(' ', [] === $requiredScopes ? $this->scopes : $requiredScopes),
            $this->entryPoint->resourceMetadataUrl(),
        ));

        return $response;
    }
}
