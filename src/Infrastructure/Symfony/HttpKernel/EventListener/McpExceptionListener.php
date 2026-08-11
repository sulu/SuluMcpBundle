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

namespace Sulu\Mcp\Infrastructure\Symfony\HttpKernel\EventListener;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Converts MCP-endpoint exceptions to JSON-RPC error responses.
 *
 * Security exceptions are deliberately left to the firewall's ExceptionListener
 * (priority 1) and McpAuthenticationEntryPoint (priority 10), which turn them
 * into the RFC 9728 401 clients need for discovery. Handling them here
 * (priority 5) would replace that 401 with a 500.
 *
 * @internal
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 5)]
class McpExceptionListener
{
    public function __construct(
        private readonly string $mcpPath = '/admin/mcp',
        private readonly bool $debug = false,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->getPathInfo() !== $this->mcpPath) {
            return;
        }

        $exception = $event->getThrowable();

        if ($exception instanceof AuthenticationException || $exception instanceof AccessDeniedException) {
            return;
        }

        if ($exception instanceof PermissionDeniedException) {
            $response = new JsonResponse([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32603,
                    'message' => 'Permission denied',
                    'data' => [
                        'type' => 'permission_denied',
                        'detail' => $exception->getMessage(),
                        'required_permission' => $exception->getSecurityContext(),
                        'permission_type' => $exception->getPermissionType(),
                        'locale' => $exception->getLocale(),
                    ],
                ],
                'id' => null,
            ], 403);

            $event->setResponse($response);

            return;
        }

        if ($exception instanceof \InvalidArgumentException) {
            $response = new JsonResponse([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32602,
                    'message' => 'Invalid params',
                    'data' => [
                        'type' => 'invalid_params',
                        'detail' => $exception->getMessage(),
                    ],
                ],
                'id' => null,
            ], 400);

            $event->setResponse($response);

            return;
        }

        $this->logger->error('Unhandled exception on MCP endpoint', ['exception' => $exception]);

        $detail = $this->debug ? $exception->getMessage() : 'An internal error occurred.';

        $response = new JsonResponse([
            'jsonrpc' => '2.0',
            'error' => [
                'code' => -32603,
                'message' => 'Internal error',
                'data' => [
                    'type' => 'internal_error',
                    'detail' => $detail,
                ],
            ],
            'id' => null,
        ], 500);

        $event->setResponse($response);
    }
}
