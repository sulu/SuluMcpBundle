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
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceCollection;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Component\Webspace\Webspace;
use Sulu\Mcp\Application\Security\ToolPermissionChecker;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Infrastructure\Mcp\PermissionAwareCallToolHandler;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\Tests\Application\TestBundle\Metadata\TestGroupProvider;
use Sulu\Mcp\Tests\Unit\Fixture\FakeToolPermissionChecker;
use Sulu\Mcp\Tests\Unit\Fixture\TestUser;

#[CoversClass(PermissionAwareCallToolHandler::class)]
final class PermissionAwareCallToolHandlerTest extends TestCase
{
    use ProphecyTrait;

    private FakeToolPermissionChecker $checker;

    /** @var ObjectProphecy<RegistryInterface> */
    private ObjectProphecy $registry;
    private WebspacePermissionResolver $webspacePermissionResolver;

    protected function setUp(): void
    {
        $this->checker = FakeToolPermissionChecker::grantingAll();
        $this->registry = $this->prophesize(RegistryInterface::class);
        // Default: no webspaces, so sentinel-based coarse checks fail closed
        // unless a test opts into a real resolver via handler().
        $this->webspacePermissionResolver = $this->webspaceResolver([]);
    }

    private function session(): SessionInterface
    {
        return $this->prophesize(SessionInterface::class)->reveal();
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

        $webspaceManager = $this->prophesize(WebspaceManagerInterface::class);
        $webspaceManager->getWebspaceCollection()->willReturn(new WebspaceCollection($webspaces));

        $securityChecker = $this->prophesize(SecurityCheckerInterface::class);
        $securityChecker->hasPermission(Argument::cetera())->will(
            static function(array $args) use ($grantedWebspaceKeys): bool {
                return \in_array(
                    \str_replace('sulu.webspaces.', '', $args[0]->getSecurityContext()),
                    $grantedWebspaceKeys,
                    true,
                );
            },
        );

        $tokenStorage = (new TestUser())->inTokenStorage();

        return new WebspacePermissionResolver($webspaceManager->reveal(), new ToolPermissionChecker($securityChecker->reveal(), $tokenStorage));
    }

    /**
     * @param array<string, array{name: string, requirements: list<array{context: string, permission: string}>, contextArgument: ?string, contextResolver: ?string, objectResolved: bool, discoveryContexts: list<string>}> $map
     */
    private function handler(array $map, ?WebspacePermissionResolver $webspacePermissionResolver = null): PermissionAwareCallToolHandler
    {
        return new PermissionAwareCallToolHandler(
            $this->registry->reveal(),
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
        $this->checker->denyAll();
        $handler = $this->handler([
            'sulu_tag_create' => [
                'name' => 'sulu_tag_create',
                'requirements' => [['context' => 'sulu.settings.tags', 'permission' => 'add']],
                'contextArgument' => null, 'contextResolver' => null,
                'objectResolved' => false, 'discoveryContexts' => [],
            ],
        ]);

        $request = $this->request('sulu_tag_create', ['name' => 'x']);
        $response = $handler->handle($request, $this->session());

        self::assertInstanceOf(Response::class, $response);
        $result = $response->result;
        self::assertInstanceOf(CallToolResult::class, $result);
        self::assertTrue($result->isError);
    }

    public function testUndeclaredNonAllowlistedToolIsDenied(): void
    {
        $handler = $this->handler([]);

        $request = $this->request('sulu_mystery_tool', []);
        $response = $handler->handle($request, $this->session());

        self::assertInstanceOf(Response::class, $response);
        $result = $response->result;
        self::assertInstanceOf(CallToolResult::class, $result);
        self::assertTrue($result->isError);

        self::assertSame([], $this->checker->calls(), 'the permission checker must not be consulted');
    }

    public function testAllowlistedToolNeverConsultsPermissionChecker(): void
    {
        $this->registry->getTool(Argument::any())->willThrow(new ToolNotFoundException('sulu_ping'));

        $handler = $this->handler([]);

        $request = $this->request('sulu_ping', []);
        $result = $handler->handle($request, $this->session());

        // Reached the inner handler, which reports METHOD_NOT_FOUND for the unregistered tool.
        self::assertInstanceOf(Error::class, $result);

        self::assertSame([], $this->checker->calls(), 'the permission checker must not be consulted');
    }

    public function testCoarseCheckDeniesWhenNoSingleCandidateGrantsAllRequirements(): void
    {
        $this->checker->grantingNoneExcept()
            ->grant('ctx_a', 'edit')
            ->grant('ctx_b', 'delete');

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
        $response = $handler->handle($request, $this->session());

        self::assertInstanceOf(Response::class, $response);
        $result = $response->result;
        self::assertInstanceOf(CallToolResult::class, $result);
        self::assertTrue($result->isError);
    }

    public function testCoarseCheckDelegatesWhenSingleCandidateGrantsAllRequirements(): void
    {
        $this->checker->grantingNoneExcept()
            ->grant('ctx_a', 'edit')
            ->grant('ctx_a', 'delete');
        $this->registry->getTool(Argument::any())->willThrow(new ToolNotFoundException('sulu_thing_delete'));

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
        $result = $handler->handle($request, $this->session());

        // Passed the preflight (both requirements granted on ctx_a); reaches
        // the inner handler, which reports METHOD_NOT_FOUND.
        self::assertInstanceOf(Error::class, $result);
    }

    public function testEmptyRequirementsIsDenied(): void
    {
        $handler = $this->handler([
            'sulu_no_requirements' => [
                'name' => 'sulu_no_requirements',
                'requirements' => [],
                'contextArgument' => null, 'contextResolver' => null,
                'objectResolved' => false, 'discoveryContexts' => [],
            ],
        ]);

        $request = $this->request('sulu_no_requirements', []);
        $response = $handler->handle($request, $this->session());

        self::assertInstanceOf(Response::class, $response);
        $result = $response->result;
        self::assertInstanceOf(CallToolResult::class, $result);
        self::assertTrue($result->isError);

        self::assertSame([], $this->checker->calls(), 'the permission checker must not be consulted');
    }

    public function testAnyWebspaceSentinelDelegatesWhenResolverGrantsAWebspace(): void
    {
        $this->registry->getTool(Argument::any())->willThrow(new ToolNotFoundException('sulu_page_get'));

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
        $result = $handler->handle($request, $this->session());

        // Reached the inner handler, which reports METHOD_NOT_FOUND for the unregistered tool.
        self::assertInstanceOf(Error::class, $result);

        self::assertSame([], $this->checker->calls(), 'the permission checker must not be consulted');
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
        $response = $handler->handle($request, $this->session());

        self::assertInstanceOf(Response::class, $response);
        $result = $response->result;
        self::assertInstanceOf(CallToolResult::class, $result);
        self::assertTrue($result->isError);
    }

    public function testPreflightExceptionFailsClosed(): void
    {
        $this->checker->failingWith(new \RuntimeException('boom'));

        $handler = $this->handler([
            'sulu_tag_create' => [
                'name' => 'sulu_tag_create',
                'requirements' => [['context' => 'sulu.settings.tags', 'permission' => 'add']],
                'contextArgument' => null, 'contextResolver' => null,
                'objectResolved' => false, 'discoveryContexts' => [],
            ],
        ]);

        $request = $this->request('sulu_tag_create', ['name' => 'x']);
        $response = $handler->handle($request, $this->session());

        self::assertInstanceOf(Response::class, $response);
        $result = $response->result;
        self::assertInstanceOf(CallToolResult::class, $result);
        self::assertTrue($result->isError);
    }
}
