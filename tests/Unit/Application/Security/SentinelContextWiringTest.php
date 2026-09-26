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

namespace Sulu\Mcp\Tests\Unit\Application\Security;

use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Capability\RegistryInterface;
use Mcp\Exception\ToolNotFoundException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceCollection;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Component\Webspace\Webspace;
use Sulu\Mcp\Application\Security\ToolPermissionChecker;
use Sulu\Mcp\Application\Security\ToolVisibilityResolver;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Infrastructure\Mcp\PermissionAwareCallToolHandler;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\Infrastructure\Sulu\Security\SnippetSecurityContextResolver;
use Sulu\Mcp\Tests\Application\TestBundle\Metadata\TestGroupProvider;
use Sulu\Mcp\Tests\Unit\Fixture\FakeToolPermissionChecker;
use Sulu\Mcp\Tests\Unit\Fixture\TestUser;

/**
 * Every `ANY_*_CONTEXT` sentinel has to be expanded by BOTH `grants()` methods: the one
 * deciding visibility and the one gating the call. A sentinel handled on one side only
 * reaches the permission checker, which refuses any context containing `#`, so the tool
 * is listed but denied to everyone. The sentinels are collected from src/, so a new one
 * is covered without touching this test.
 */
#[CoversClass(ToolVisibilityResolver::class)]
#[CoversClass(PermissionAwareCallToolHandler::class)]
final class SentinelContextWiringTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @return iterable<string, array{string}>
     */
    public static function sentinels(): iterable
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 4) . '/src'));

        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }

            \preg_match_all("/const (ANY_\\w+_CONTEXT) = '([^']+)'/", (string) \file_get_contents($file->getPathname()), $matches, \PREG_SET_ORDER);

            foreach ($matches as $match) {
                yield $match[1] => [$match[2]];
            }
        }
    }

    public function testSentinelsAreFound(): void
    {
        // Guards against the provider silently yielding nothing.
        self::assertGreaterThanOrEqual(3, \iterator_count(self::sentinels()));
    }

    #[DataProvider('sentinels')]
    public function testVisibilityResolverExpandsTheSentinel(string $sentinel): void
    {
        $resolver = new ToolVisibilityResolver(
            $this->permissionMap($sentinel),
            $this->checker(),
            $this->webspaceResolver(),
            new ArticleSecurityContextResolver(TestGroupProvider::singleGroup()),
            new SnippetSecurityContextResolver(TestGroupProvider::singleGroup()),
            [],
            [],
        );

        self::assertTrue($resolver->isVisible('sentinel_tool', 'en'), \sprintf('"%s" reached the permission checker unexpanded.', $sentinel));
    }

    #[DataProvider('sentinels')]
    public function testCallHandlerExpandsTheSentinel(string $sentinel): void
    {
        $registry = $this->prophesize(RegistryInterface::class);
        $registry->getTool(Argument::any())->willThrow(new ToolNotFoundException('sentinel_tool'));

        $handler = new PermissionAwareCallToolHandler(
            $registry->reveal(),
            new ReferenceHandler(null),
            $this->checker(),
            $this->webspaceResolver(),
            new ArticleSecurityContextResolver(TestGroupProvider::singleGroup()),
            new SnippetSecurityContextResolver(TestGroupProvider::singleGroup()),
            $this->permissionMap($sentinel),
            [],
            [],
        );

        $request = CallToolRequest::fromArray([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'sentinel_tool', 'arguments' => ['locale' => 'en']],
        ]);

        // Past the preflight, the inner handler reports the unregistered tool as an Error.
        self::assertInstanceOf(
            Error::class,
            $handler->handle($request, $this->prophesize(SessionInterface::class)->reveal()),
            \sprintf('"%s" reached the permission checker unexpanded.', $sentinel),
        );
    }

    /**
     * @return array<string, array{name: string, requirements: list<array{context: string, permission: string}>, contextArgument: ?string, contextResolver: ?string, objectResolved: bool, discoveryContexts: list<string>}>
     */
    private function permissionMap(string $sentinel): array
    {
        return [
            'sentinel_tool' => [
                'name' => 'sentinel_tool',
                'requirements' => [['context' => 'unused', 'permission' => PermissionTypes::VIEW]],
                'contextArgument' => null,
                'contextResolver' => null,
                'objectResolved' => true,
                'discoveryContexts' => [$sentinel],
            ],
        ];
    }

    /**
     * Grants every real context and, like ToolPermissionChecker, refuses a sentinel.
     */
    private function checker(): FakeToolPermissionChecker
    {
        return FakeToolPermissionChecker::grantingAll()
            ->grantWhen(static fn (string $context): bool => !\str_contains($context, '#'));
    }

    private function webspaceResolver(): WebspacePermissionResolver
    {
        $webspace = new Webspace();
        $webspace->setKey('example');

        $webspaceManager = $this->prophesize(WebspaceManagerInterface::class);
        $webspaceManager->getWebspaceCollection()->willReturn(new WebspaceCollection(['example' => $webspace]));

        $securityChecker = $this->prophesize(SecurityCheckerInterface::class);
        $securityChecker->hasPermission(Argument::cetera())->willReturn(true);

        return new WebspacePermissionResolver(
            $webspaceManager->reveal(),
            new ToolPermissionChecker($securityChecker->reveal(), (new TestUser())->inTokenStorage()),
        );
    }
}
