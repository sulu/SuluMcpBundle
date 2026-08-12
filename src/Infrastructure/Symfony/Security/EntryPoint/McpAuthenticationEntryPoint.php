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
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Returns 401 + WWW-Authenticate for unauthenticated MCP requests, pointing
 * clients to the PRM endpoint (RFC 9728). Priority 10 so it runs before
 * McpExceptionListener (5).
 *
 * @internal
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 10)]
class McpAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly string $serverUrl,
        private readonly string $mcpPath = '/admin/mcp',
    ) {
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof AuthenticationException) {
            return;
        }

        $request = $event->getRequest();
        if ($request->getPathInfo() !== $this->mcpPath) {
            return;
        }

        $event->setResponse($this->start($request, $exception));
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

        $prmUrl = \rtrim($this->serverUrl, '/') . '/.well-known/oauth-protected-resource';
        $response->headers->set(
            'WWW-Authenticate',
            \sprintf('Bearer resource_metadata="%s", scope="mcp:tools mcp:resources"', $prmUrl)
        );

        return $response;
    }
}
