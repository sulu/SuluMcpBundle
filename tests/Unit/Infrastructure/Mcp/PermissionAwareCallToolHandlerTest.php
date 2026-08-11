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

namespace Sulu\Mcp\Tests\Unit\Infrastructure\Mcp;

use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Capability\RegistryInterface;
use Mcp\Exception\ToolNotFoundException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceCollection;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Component\Webspace\Webspace;
use Sulu\Mcp\Application\Security\ToolPermissionChecker;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Infrastructure\Mcp\PermissionAwareCallToolHandler;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\Tests\Application\TestBundle\Metadata\TestGroupProvider;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

#[CoversClass(PermissionAwareCallToolHandler::class)]
final class PermissionAwareCallToolHandlerTest extends TestCase
{
    private ToolPermissionCheckerInterface&MockObject $checker;
    private RegistryInterface&MockObject $registry;
    private WebspacePermissionResolver $webspacePermissionResolver;

    protected function setUp(): void
    {
        $this->checker = $this->createMock(ToolPermissionCheckerInterface::class);
        $this->registry = $this->createMock(RegistryInterface::class);
        // Default: no webspaces, so sentinel-based coarse checks fail closed
        // unless a test opts into a real resolver via handler().
        $this->webspacePermissionResolver = $this->webspaceResolver([]);
    }

    /**
     * WebspacePermissionResolver is final, so build it for real over mocked
     * collaborators, mirroring WebspacePermissionResolverTest.
     *
     * @param list<string> $grantedWebspaceKeys webspace keys on which EDIT is granted
     */
    private function webspaceResolver(array $grantedWebspaceKeys): WebspacePermissionResolver
    {
        $webspaces = [];
        foreach (['example', 'blog'] as $key) {
            $webspace = new Webspace();
            $webspace->setKey($key);
            $webspaces[$key] = $webspace;
        }

        $webspaceManager = $this->createMock(WebspaceManagerInterface::class);
        $webspaceManager->method('getWebspaceCollection')->willReturn(new WebspaceCollection($webspaces));

        $securityChecker = $this->createMock(SecurityCheckerInterface::class);
        $securityChecker->method('hasPermission')->willReturnCallback(
            static fn ($condition, string $permission): bool => \in_array(
                str_replace('sulu.webspaces.', '', $condition->getSecurityContext()),
                $grantedWebspaceKeys,
                true,
            ),
        );

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($this->createMock(UserInterface::class));
        $tokenStorage->method('getToken')->willReturn($token);

        return new WebspacePermissionResolver($webspaceManager, new ToolPermissionChecker($securityChecker, $tokenStorage));
    }

    /**
     * @param array<string, array{name: string, requirements: list<array{context: string, permission: string}>, contextArgument: ?string, contextResolver: ?string, objectResolved: bool, discoveryContexts: list<string>}> $map
     */
    private function handler(array $map, ?WebspacePermissionResolver $webspacePermissionResolver = null): PermissionAwareCallToolHandler
    {
        return new PermissionAwareCallToolHandler(
            $this->registry,
            new ReferenceHandler(null),
            $this->checker,
            $webspacePermissionResolver ?? $this->webspacePermissionResolver,
            new ArticleSecurityContextResolver(TestGroupProvider::singleGroup()),
            $map,
            [],
            ['sulu_ping', 'sulu_get_context'],
        );
    }

    private function request(string $name, array $arguments): CallToolRequest
    {
        return CallToolRequest::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ]);
    }

    public function testDeniedStaticContextReturnsIsError(): void
    {
        $this->checker->method('has')->willReturn(false);
        $handler = $this->handler([
            'sulu_tag_create' => [
                'name' => 'sulu_tag_create',
                'requirements' => [['context' => 'sulu.settings.tags', 'permission' => 'add']],
                'contextArgument' => null, 'contextResolver' => null,
                'objectResolved' => false, 'discoveryContexts' => [],
            ],
        ]);

        $request = $this->request('sulu_tag_create', ['name' => 'x']);
        $response = $handler->handle($request, $this->createMock(SessionInterface::class));

        self::assertInstanceOf(Response::class, $response);
        $result = $response->result;
        self::assertInstanceOf(CallToolResult::class, $result);
        self::assertTrue($result->isError);
    }

    public function testUndeclaredNonAllowlistedToolIsDenied(): void
    {
        $this->checker->expects(self::never())->method('has');
        $handler = $this->handler([]);

        $request = $this->request('sulu_mystery_tool', []);
        $response = $handler->handle($request, $this->createMock(SessionInterface::class));

        self::assertInstanceOf(Response::class, $response);
        $result = $response->result;
        self::assertInstanceOf(CallToolResult::class, $result);
        self::assertTrue($result->isError);
    }

    public function testAllowlistedToolNeverConsultsPermissionChecker(): void
    {
        $this->checker->expects(self::never())->method('has');
        $this->checker->expects(self::never())->method('check');
        $this->registry->method('getTool')->willThrowException(new ToolNotFoundException('sulu_ping'));

        $handler = $this->handler([]);

        $request = $this->request('sulu_ping', []);
        $result = $handler->handle($request, $this->createMock(SessionInterface::class));

        // Reached the inner handler, which reports METHOD_NOT_FOUND for the unregistered tool.
        self::assertInstanceOf(Error::class, $result);
    }

    public function testCoarseCheckDeniesWhenNoSingleCandidateGrantsAllRequirements(): void
    {
        $this->checker->method('has')->willReturnCallback(
            static fn (string $context, string $permission): bool => ('ctx_a' === $context && 'edit' === $permission)
                || ('ctx_b' === $context && 'delete' === $permission),
        );

        $handler = $this->handler([
            'sulu_thing_delete' => [
                'name' => 'sulu_thing_delete',
                'requirements' => [
                    ['context' => 'ctx_a', 'permission' => 'edit'],
                    ['context' => 'ctx_b', 'permission' => 'delete'],
                ],
                'contextArgument' => null, 'contextResolver' => null,
                'objectResolved' => true, 'discoveryContexts' => ['ctx_a', 'ctx_b'],
            ],
        ]);

        $request = $this->request('sulu_thing_delete', ['id' => 1]);
        $response = $handler->handle($request, $this->createMock(SessionInterface::class));

        self::assertInstanceOf(Response::class, $response);
        $result = $response->result;
        self::assertInstanceOf(CallToolResult::class, $result);
        self::assertTrue($result->isError);
    }

    public function testCoarseCheckDelegatesWhenSingleCandidateGrantsAllRequirements(): void
    {
        $this->checker->method('has')->willReturnCallback(
            static fn (string $context, string $permission): bool => 'ctx_a' === $context
                && ('edit' === $permission || 'delete' === $permission),
        );
        $this->registry->method('getTool')->willThrowException(new ToolNotFoundException('sulu_thing_delete'));

        $handler = $this->handler([
            'sulu_thing_delete' => [
                'name' => 'sulu_thing_delete',
                'requirements' => [
                    ['context' => 'ctx_a', 'permission' => 'edit'],
                    ['context' => 'ctx_a', 'permission' => 'delete'],
                ],
                'contextArgument' => null, 'contextResolver' => null,
                'objectResolved' => true, 'discoveryContexts' => ['ctx_a', 'ctx_b'],
            ],
        ]);

        $request = $this->request('sulu_thing_delete', ['id' => 1]);
        $result = $handler->handle($request, $this->createMock(SessionInterface::class));

        // Passed the preflight (both requirements granted on ctx_a); reaches
        // the inner handler, which reports METHOD_NOT_FOUND.
        self::assertInstanceOf(Error::class, $result);
    }

    public function testEmptyRequirementsIsDenied(): void
    {
        $this->checker->expects(self::never())->method('has');

        $handler = $this->handler([
            'sulu_no_requirements' => [
                'name' => 'sulu_no_requirements',
                'requirements' => [],
                'contextArgument' => null, 'contextResolver' => null,
                'objectResolved' => false, 'discoveryContexts' => [],
            ],
        ]);

        $request = $this->request('sulu_no_requirements', []);
        $response = $handler->handle($request, $this->createMock(SessionInterface::class));

        self::assertInstanceOf(Response::class, $response);
        $result = $response->result;
        self::assertInstanceOf(CallToolResult::class, $result);
        self::assertTrue($result->isError);
    }

    public function testAnyWebspaceSentinelDelegatesWhenResolverGrantsAWebspace(): void
    {
        $this->checker->expects(self::never())->method('has');
        $this->registry->method('getTool')->willThrowException(new ToolNotFoundException('sulu_page_get'));

        $handler = $this->handler(
            [
                'sulu_page_get' => [
                    'name' => 'sulu_page_get',
                    'requirements' => [['context' => 'sulu.webspaces.#context#', 'permission' => PermissionTypes::EDIT]],
                    'contextArgument' => null, 'contextResolver' => null,
                    'objectResolved' => true, 'discoveryContexts' => [WebspacePermissionResolver::ANY_WEBSPACE_CONTEXT],
                ],
            ],
            $this->webspaceResolver(['example']),
        );

        $request = $this->request('sulu_page_get', ['uuid' => 'x']);
        $result = $handler->handle($request, $this->createMock(SessionInterface::class));

        // Reached the inner handler, which reports METHOD_NOT_FOUND for the unregistered tool.
        self::assertInstanceOf(Error::class, $result);
    }

    public function testAnyWebspaceSentinelDeniesWhenResolverGrantsNoWebspace(): void
    {
        $handler = $this->handler(
            [
                'sulu_page_get' => [
                    'name' => 'sulu_page_get',
                    'requirements' => [['context' => 'sulu.webspaces.#context#', 'permission' => PermissionTypes::EDIT]],
                    'contextArgument' => null, 'contextResolver' => null,
                    'objectResolved' => true, 'discoveryContexts' => [WebspacePermissionResolver::ANY_WEBSPACE_CONTEXT],
                ],
            ],
            $this->webspaceResolver([]),
        );

        $request = $this->request('sulu_page_get', ['uuid' => 'x']);
        $response = $handler->handle($request, $this->createMock(SessionInterface::class));

        self::assertInstanceOf(Response::class, $response);
        $result = $response->result;
        self::assertInstanceOf(CallToolResult::class, $result);
        self::assertTrue($result->isError);
    }

    public function testPreflightExceptionFailsClosed(): void
    {
        $this->checker->method('has')->willThrowException(new \RuntimeException('boom'));

        $handler = $this->handler([
            'sulu_tag_create' => [
                'name' => 'sulu_tag_create',
                'requirements' => [['context' => 'sulu.settings.tags', 'permission' => 'add']],
                'contextArgument' => null, 'contextResolver' => null,
                'objectResolved' => false, 'discoveryContexts' => [],
            ],
        ]);

        $request = $this->request('sulu_tag_create', ['name' => 'x']);
        $response = $handler->handle($request, $this->createMock(SessionInterface::class));

        self::assertInstanceOf(Response::class, $response);
        $result = $response->result;
        self::assertInstanceOf(CallToolResult::class, $result);
        self::assertTrue($result->isError);
    }
}
