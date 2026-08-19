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

namespace Sulu\Mcp\Infrastructure\Symfony\Security\EntryPoint;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Returns 401 + WWW-Authenticate for unauthenticated MCP requests, pointing clients
 * to the PRM endpoint (RFC 9728) and adding `error="invalid_token"` whenever the
 * request carried a bearer. Priority 10 so it runs before McpExceptionListener (5);
 * the response hook completes the bare `WWW-Authenticate: Bearer` league sends.
 *
 * @internal
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 10)]
#[AsEventListener(event: KernelEvents::RESPONSE)]
class McpAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        private readonly string $serverUrl,
        private readonly string $mcpPath = '/admin/mcp',
        private readonly array $scopes = ['mcp:tools', 'mcp:resources'],
    ) {
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof AuthenticationException) {
            return;
        }

        $request = $event->getRequest();
        if (\rawurldecode($request->getPathInfo()) !== $this->mcpPath) {
            return;
        }

        $event->setResponse($this->start($request, $exception));
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (\rawurldecode($request->getPathInfo()) !== $this->mcpPath) {
            return;
        }

        $response = $event->getResponse();
        if (401 !== $response->getStatusCode()) {
            return;
        }

        $challenge = $response->headers->get('WWW-Authenticate');
        // Only the bare scheme league's OAuth2AuthenticationException sends is completed.
        if (null !== $challenge && 'bearer' !== \strtolower(\trim($challenge))) {
            return;
        }

        $event->setResponse($this->start($request));
    }

    /**
     * RFC 9728 section 3; keep in sync with the sulu_mcp_prm route.
     */
    public function resourceMetadataUrl(): string
    {
        return \rtrim($this->serverUrl, '/') . '/.well-known/oauth-protected-resource' . $this->mcpPath;
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        $response = new JsonResponse([
            'jsonrpc' => '2.0',
            'error' => [
                'code' => -32001,
                'message' => 'Unauthorized',
            ],
            'id' => null,
        ], 401);

        $response->headers->set(
            'WWW-Authenticate',
            \sprintf(
                'Bearer %sresource_metadata="%s", scope="%s"',
                $this->carriesBearer($request) ? 'error="invalid_token", ' : '',
                $this->resourceMetadataUrl(),
                \implode(' ', $this->scopes),
            )
        );

        return $response;
    }

    private function carriesBearer(Request $request): bool
    {
        return 'bearer' === \strtolower(\explode(' ', (string) $request->headers->get('Authorization'), 2)[0]);
    }
}
