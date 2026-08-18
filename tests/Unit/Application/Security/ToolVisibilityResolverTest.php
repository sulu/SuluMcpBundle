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
use Sulu\Mcp\Application\Security\ToolContextResolverInterface;
use Sulu\Mcp\Application\Security\ToolPermissionChecker;
use Sulu\Mcp\Application\Security\ToolVisibilityResolver;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\Infrastructure\Sulu\Security\ContactSecurityContextResolver;
use Sulu\Mcp\Tests\Application\TestBundle\Metadata\TestGroupProvider;
use Sulu\Mcp\Tests\Unit\Fixture\FakeToolPermissionChecker;
use Sulu\Mcp\Tests\Unit\Fixture\TestUser;

#[CoversClass(ToolVisibilityResolver::class)]
final class ToolVisibilityResolverTest extends TestCase
{
    use ProphecyTrait;

    private FakeToolPermissionChecker $checker;

    protected function setUp(): void
    {
        $this->checker = FakeToolPermissionChecker::grantingAll();
    }

    /**
     * @param array<string, array{name: string, requirements: list<array{context: string, permission: string}>, contextArgument: ?string, contextResolver: ?string, objectResolved: bool, discoveryContexts: list<string>}> $map
     * @param array<string, ToolContextResolverInterface> $contextResolvers
     */
    private function resolver(
        array $map,
        ?WebspacePermissionResolver $webspacePermissionResolver = null,
        array $contextResolvers = [],
    ): ToolVisibilityResolver {
        return new ToolVisibilityResolver(
            $map,
            $this->checker,
            $webspacePermissionResolver ?? $this->webspaceResolver([]),
            new ArticleSecurityContextResolver(TestGroupProvider::singleGroup()),
            $contextResolvers,
            ['sulu_ping', 'sulu_get_context'],
        );
    }

    /**
     * WebspacePermissionResolver is final, so this builds a real instance over
     * mocked collaborators (mirrors WebspacePermissionResolverTest).
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

        /** @var ObjectProphecy<WebspaceManagerInterface> $webspaceManager */
        $webspaceManager = $this->prophesize(WebspaceManagerInterface::class);
        $webspaceManager->getWebspaceCollection()->willReturn(new WebspaceCollection($webspaces));

        /** @var ObjectProphecy<SecurityCheckerInterface> $securityChecker */
        $securityChecker = $this->prophesize(SecurityCheckerInterface::class);
        $securityChecker->hasPermission(Argument::cetera())->will(
            fn (array $args): bool => \in_array(
                \str_replace('sulu.webspaces.', '', $args[0]->getSecurityContext()),
                $grantedWebspaceKeys,
                true,
            ),
        );

        $tokenStorage = (new TestUser())->inTokenStorage();

        return new WebspacePermissionResolver($webspaceManager->reveal(), new ToolPermissionChecker($securityChecker->reveal(), $tokenStorage));
    }

    public function testAllowlistedToolIsAlwaysVisible(): void
    {
        $resolver = $this->resolver([]);

        self::assertTrue($resolver->isVisible('sulu_ping'));

        self::assertSame([], $this->checker->calls(), 'the permission checker must not be consulted');
    }

    public function testUndeclaredToolIsHidden(): void
    {
        $resolver = $this->resolver([]);

        self::assertFalse($resolver->isVisible('sulu_mystery_tool'));
    }

    public function testEmptyRequirementsIsHidden(): void
    {
        $resolver = $this->resolver([
            'sulu_no_requirements' => [
                'name' => 'sulu_no_requirements',
                'requirements' => [],
                'contextArgument' => null, 'contextResolver' => null,
                'objectResolved' => false, 'discoveryContexts' => [],
            ],
        ]);

        self::assertFalse($resolver->isVisible('sulu_no_requirements'));

        self::assertSame([], $this->checker->calls(), 'the permission checker must not be consulted');
    }

    public function testStaticContextToolIsHiddenWhenUserLacksPermission(): void
    {
        $this->checker->denyAll();
        $resolver = $this->resolver([
            'sulu_tag_create' => [
                'name' => 'sulu_tag_create',
                'requirements' => [['context' => 'sulu.settings.tags', 'permission' => PermissionTypes::ADD]],
                'contextArgument' => null, 'contextResolver' => null,
                'objectResolved' => false, 'discoveryContexts' => [],
            ],
        ]);

        self::assertFalse($resolver->isVisible('sulu_tag_create'));
    }

    public function testStaticContextToolIsVisibleWhenUserHasPermission(): void
    {
        $this->checker->grantingNoneExcept()->grant('sulu.settings.tags', PermissionTypes::ADD);
        $resolver = $this->resolver([
            'sulu_tag_create' => [
                'name' => 'sulu_tag_create',
                'requirements' => [['context' => 'sulu.settings.tags', 'permission' => PermissionTypes::ADD]],
                'contextArgument' => null, 'contextResolver' => null,
                'objectResolved' => false, 'discoveryContexts' => [],
            ],
        ]);

        self::assertTrue($resolver->isVisible('sulu_tag_create'));
    }

    public function testToolGrantedOnOnlyOneOfTwoCandidatesIsVisible(): void
    {
        // ContactSecurityContextResolver::candidates() => ['sulu.contact.people', 'sulu.contact.organizations'];
        // only the second is granted.
        $this->checker->grantingNoneExcept()->grant('sulu.contact.organizations', PermissionTypes::VIEW);
        $resolver = $this->resolver(
            [
                'sulu_contact_list' => [
                    'name' => 'sulu_contact_list',
                    'requirements' => [['context' => 'sulu.contact.#context#', 'permission' => PermissionTypes::VIEW]],
                    'contextArgument' => null, 'contextResolver' => 'sulu_mcp.contact_context_resolver',
                    'objectResolved' => false, 'discoveryContexts' => [],
                ],
            ],
            contextResolvers: ['sulu_mcp.contact_context_resolver' => new ContactSecurityContextResolver()],
        );

        self::assertTrue($resolver->isVisible('sulu_contact_list'));
    }

    public function testNoCandidateGrantsIsHidden(): void
    {
        $this->checker->denyAll();
        $resolver = $this->resolver(
            [
                'sulu_contact_list' => [
                    'name' => 'sulu_contact_list',
                    'requirements' => [['context' => 'sulu.contact.#context#', 'permission' => PermissionTypes::VIEW]],
                    'contextArgument' => null, 'contextResolver' => 'sulu_mcp.contact_context_resolver',
                    'objectResolved' => false, 'discoveryContexts' => [],
                ],
            ],
            contextResolvers: ['sulu_mcp.contact_context_resolver' => new ContactSecurityContextResolver()],
        );

        self::assertFalse($resolver->isVisible('sulu_contact_list'));
    }

    public function testAnyWebspaceSentinelVisibleWhenAWebspaceIsGranted(): void
    {
        $resolver = $this->resolver(
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

        self::assertTrue($resolver->isVisible('sulu_page_get'));

        self::assertSame([], $this->checker->calls(), 'the permission checker must not be consulted');
    }

    public function testAnyWebspaceSentinelHiddenWhenNoWebspaceIsGranted(): void
    {
        $resolver = $this->resolver(
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

        self::assertFalse($resolver->isVisible('sulu_page_get'));
    }

    public function testDescribeReturnsReasonWhenUnavailable(): void
    {
        $this->checker->denyAll();
        $resolver = $this->resolver([
            'sulu_tag_create' => [
                'name' => 'sulu_tag_create',
                'requirements' => [['context' => 'sulu.settings.tags', 'permission' => PermissionTypes::ADD]],
                'contextArgument' => null, 'contextResolver' => null,
                'objectResolved' => false, 'discoveryContexts' => [],
            ],
        ]);

        $description = $resolver->describe('sulu_tag_create');

        self::assertSame('sulu_tag_create', $description['name']);
        self::assertFalse($description['available']);
        self::assertSame([['context' => 'sulu.settings.tags', 'permission' => PermissionTypes::ADD]], $description['requires']);
        self::assertNotNull($description['reason']);
    }

    /**
     * A compound declaration (delete needs view AND delete) must be reported in
     * full -- advertising only the first understates what the caller needs.
     */
    public function testDescribeReportsEveryRequirementNotJustTheFirst(): void
    {
        $this->checker->denyAll();
        $requirements = [
            ['context' => 'sulu.settings.categories', 'permission' => PermissionTypes::VIEW],
            ['context' => 'sulu.settings.categories', 'permission' => PermissionTypes::DELETE],
        ];
        $resolver = $this->resolver([
            'sulu_category_delete' => [
                'name' => 'sulu_category_delete',
                'requirements' => $requirements,
                'contextArgument' => null, 'contextResolver' => null,
                'objectResolved' => false, 'discoveryContexts' => [],
            ],
        ]);

        $description = $resolver->describe('sulu_category_delete');

        self::assertSame($requirements, $description['requires']);
        self::assertStringContainsString(PermissionTypes::DELETE, (string) $description['reason']);
    }

    public function testDescribeHasNoReasonWhenAvailable(): void
    {
        $resolver = $this->resolver([]);

        $description = $resolver->describe('sulu_ping');

        self::assertTrue($description['available']);
        self::assertNull($description['reason']);
    }

    public function testDescribeAllCoversPermissionMapAndAllowlist(): void
    {
        $this->checker->denyAll();
        $resolver = $this->resolver([
            'sulu_tag_create' => [
                'name' => 'sulu_tag_create',
                'requirements' => [['context' => 'sulu.settings.tags', 'permission' => PermissionTypes::ADD]],
                'contextArgument' => null, 'contextResolver' => null,
                'objectResolved' => false, 'discoveryContexts' => [],
            ],
        ]);

        $rows = $resolver->describeAll();
        $byName = \array_column($rows, null, 'name');

        self::assertArrayHasKey('sulu_tag_create', $byName);
        self::assertFalse($byName['sulu_tag_create']['available']);
        self::assertNotNull($byName['sulu_tag_create']['reason']);

        self::assertArrayHasKey('sulu_ping', $byName);
        self::assertTrue($byName['sulu_ping']['available']);
        self::assertNull($byName['sulu_ping']['reason']);
        self::assertArrayHasKey('sulu_get_context', $byName);
        self::assertTrue($byName['sulu_get_context']['available']);

        self::assertSame(['sulu_get_context', 'sulu_ping', 'sulu_tag_create'], \array_column($rows, 'name'));
    }
}
