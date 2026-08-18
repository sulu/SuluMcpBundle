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

namespace Sulu\Mcp\Tests\Unit\Infrastructure\Symfony\HttpKernel\EventListener;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\LoggerInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\Infrastructure\Symfony\HttpKernel\EventListener\McpExceptionListener;
use Sulu\Mcp\Infrastructure\Symfony\Security\EntryPoint\McpAuthenticationEntryPoint;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

#[CoversClass(McpExceptionListener::class)]
#[CoversClass(McpAuthenticationEntryPoint::class)]
final class McpExceptionListenerTest extends TestCase
{
    use ProphecyTrait;

    private McpExceptionListener $listener;
    private McpAuthenticationEntryPoint $authListener;

    protected function setUp(): void
    {
        $this->listener = new McpExceptionListener('/_mcp');
        $this->authListener = new McpAuthenticationEntryPoint('https://sulu.example.com', '/_mcp');
    }

    private function createExceptionEvent(\Throwable $exception, string $pathInfo = '/_mcp'): ExceptionEvent
    {
        $kernel = new class() implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };
        $request = Request::create($pathInfo);

        return new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);
    }

    public function testPermissionDeniedExceptionReturns403WithPermissionDeniedType(): void
    {
        $exception = new PermissionDeniedException('sulu.webspaces.example', PermissionTypes::VIEW, 'en');
        $event = $this->createExceptionEvent($exception);

        $this->listener->onKernelException($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(403, $response->getStatusCode());

        $body = \json_decode($response->getContent(), true);
        $this->assertSame('2.0', $body['jsonrpc']);
        $this->assertSame(-32603, $body['error']['code']);
        $this->assertSame('permission_denied', $body['error']['data']['type']);
        $this->assertSame('sulu.webspaces.example', $body['error']['data']['required_permission']);
    }

    public function testInvalidArgumentExceptionReturns400WithInvalidParamsType(): void
    {
        $exception = new \InvalidArgumentException('Invalid webspace "foo"');
        $event = $this->createExceptionEvent($exception);

        $this->listener->onKernelException($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(400, $response->getStatusCode());

        $body = \json_decode($response->getContent(), true);
        $this->assertSame(-32602, $body['error']['code']);
        $this->assertSame('invalid_params', $body['error']['data']['type']);
    }

    public function testGenericExceptionReturns500WithInternalErrorType(): void
    {
        $exception = new \RuntimeException('Something went wrong');
        $event = $this->createExceptionEvent($exception);

        $this->listener->onKernelException($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(500, $response->getStatusCode());

        $body = \json_decode($response->getContent(), true);
        $this->assertSame(-32603, $body['error']['code']);
        $this->assertSame('internal_error', $body['error']['data']['type']);
    }

    public function testGenericExceptionInDebugModeIncludesExceptionMessageInDetail(): void
    {
        $exception = new \RuntimeException('Something went wrong');
        $event = $this->createExceptionEvent($exception);
        $listener = new McpExceptionListener('/_mcp', true);

        $listener->onKernelException($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);

        $body = \json_decode($response->getContent(), true);
        $this->assertSame('Something went wrong', $body['error']['data']['detail']);
    }

    public function testGenericExceptionInProductionModeHidesExceptionMessage(): void
    {
        $exception = new \RuntimeException('Something went wrong');
        $event = $this->createExceptionEvent($exception);
        $listener = new McpExceptionListener('/_mcp', false);

        $listener->onKernelException($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);

        $body = \json_decode($response->getContent(), true);
        $this->assertSame('An internal error occurred.', $body['error']['data']['detail']);
        $this->assertStringNotContainsString('Something went wrong', $response->getContent());
    }

    public function testGenericExceptionIsLogged(): void
    {
        $exception = new \RuntimeException('Something went wrong');
        $event = $this->createExceptionEvent($exception);

        /** @var ObjectProphecy<LoggerInterface> $logger */
        $logger = $this->prophesize(LoggerInterface::class);
        $logger->error(Argument::type('string'), ['exception' => $exception])->shouldBeCalledOnce();

        $listener = new McpExceptionListener('/_mcp', false, $logger->reveal());

        $listener->onKernelException($event);
    }

    public function testAccessDeniedExceptionIsLeftToTheSecurityLayer(): void
    {
        // Thrown by Symfony's AccessListener when the access_control rule on the
        // MCP path denies an unauthenticated request. Swallowing it here would
        // pre-empt the firewall's ExceptionListener (priority 1), which turns it
        // into the RFC 9728 401 via the configured entry point.
        $exception = new AccessDeniedException('Access Denied. The user is not appropriately authenticated.');
        $event = $this->createExceptionEvent($exception);

        $this->listener->onKernelException($event);

        $this->assertNull($event->getResponse());
    }

    public function testAuthenticationExceptionIsLeftToTheAuthenticationListener(): void
    {
        $exception = new AuthenticationException('Full authentication is required');
        $event = $this->createExceptionEvent($exception);

        $this->listener->onKernelException($event);

        $this->assertNull($event->getResponse());
    }

    public function testExceptionOnNonMcpPathDoesNotSetResponse(): void
    {
        $exception = new PermissionDeniedException('sulu.webspaces.example', PermissionTypes::VIEW, 'en');
        $event = $this->createExceptionEvent($exception, '/admin');

        $this->listener->onKernelException($event);

        $this->assertNull($event->getResponse());
    }

    public function testExceptionOnAdjacentPathSharingPrefixDoesNotSetResponse(): void
    {
        $exception = new PermissionDeniedException('sulu.webspaces.example', PermissionTypes::VIEW, 'en');
        $event = $this->createExceptionEvent($exception, '/_mcpfoo');

        $this->listener->onKernelException($event);

        $this->assertNull($event->getResponse());
    }

    public function testExceptionOnSubPathOfMcpPathDoesNotSetResponse(): void
    {
        // The mcp-bundle route loader registers exactly one route at the
        // configured path -- no sub-paths are served.
        $exception = new PermissionDeniedException('sulu.webspaces.example', PermissionTypes::VIEW, 'en');
        $event = $this->createExceptionEvent($exception, '/_mcp/nested');

        $this->listener->onKernelException($event);

        $this->assertNull($event->getResponse());
    }

    public function testAuthenticationExceptionOnMcpPathReturns401WithWwwAuthenticate(): void
    {
        $exception = new AuthenticationException('Full authentication is required');
        $event = $this->createExceptionEvent($exception);

        $this->authListener->onKernelException($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(401, $response->getStatusCode());

        $wwwAuth = $response->headers->get('WWW-Authenticate');
        $this->assertNotNull($wwwAuth);
        $this->assertStringContainsString('oauth-protected-resource', $wwwAuth);
        $this->assertStringContainsString('https://sulu.example.com', $wwwAuth);

        $body = \json_decode($response->getContent(), true);
        $this->assertSame('2.0', $body['jsonrpc']);
        $this->assertSame(-32001, $body['error']['code']);
    }

    public function testAuthenticationExceptionOnNonMcpPathDoesNotSetResponse(): void
    {
        $exception = new AuthenticationException('Full authentication is required');
        $event = $this->createExceptionEvent($exception, '/admin');

        $this->authListener->onKernelException($event);

        $this->assertNull($event->getResponse());
    }

    public function testAuthenticationExceptionOnAdjacentPathSharingPrefixDoesNotSetResponse(): void
    {
        $exception = new AuthenticationException('Full authentication is required');
        $event = $this->createExceptionEvent($exception, '/_mcpfoo');

        $this->authListener->onKernelException($event);

        $this->assertNull($event->getResponse());
    }

    public function testAuthenticationExceptionOnSubPathOfMcpPathDoesNotSetResponse(): void
    {
        // The mcp-bundle route loader registers exactly one route at the
        // configured path -- no sub-paths are served.
        $exception = new AuthenticationException('Full authentication is required');
        $event = $this->createExceptionEvent($exception, '/_mcp/nested');

        $this->authListener->onKernelException($event);

        $this->assertNull($event->getResponse());
    }

    public function testNonAuthenticationExceptionDoesNotTriggerAuthListener(): void
    {
        $exception = new \RuntimeException('Something else');
        $event = $this->createExceptionEvent($exception);

        $this->authListener->onKernelException($event);

        $this->assertNull($event->getResponse());
    }
}
