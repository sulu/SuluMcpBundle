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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceCollection;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Component\Webspace\Webspace;
use Sulu\Mcp\Application\Metadata\ExtensionFieldsProvider;
use Sulu\Mcp\Application\Metadata\FieldValueExampleProvider;
use Sulu\Mcp\Application\Security\ToolPermissionChecker;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Application\Security\ToolVisibilityResolver;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\Tests\Application\TestBundle\Metadata\TestGroupProvider;
use Sulu\Mcp\UserInterface\Mcp\Resource\BlocksResource;
use Sulu\Mcp\UserInterface\Mcp\Resource\TemplatesResource;
use Sulu\Mcp\UserInterface\Mcp\Resource\WebspacesResource;
use Sulu\Mcp\UserInterface\Mcp\Tool\GetContextTool;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

#[CoversClass(GetContextTool::class)]
final class GetContextToolTest extends TestCase
{
    /**
     * Real ToolVisibilityResolver (final) with a mocked checker that denies
     * everything, mirroring ToolVisibilityResolverTest's helper.
     */
    private function toolVisibilityResolver(): ToolVisibilityResolver
    {
        $checker = $this->createMock(ToolPermissionCheckerInterface::class);
        $checker->method('has')->willReturn(false);

        $webspaceManager = $this->createMock(WebspaceManagerInterface::class);
        $webspaceManager->method('getWebspaceCollection')->willReturn(new WebspaceCollection([]));

        $securityChecker = $this->createMock(SecurityCheckerInterface::class);
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($this->createMock(UserInterface::class));
        $tokenStorage->method('getToken')->willReturn($token);

        $webspacePermissionResolver = new WebspacePermissionResolver(
            $webspaceManager,
            new ToolPermissionChecker($securityChecker, $tokenStorage),
        );

        return new ToolVisibilityResolver(
            [
                'sulu_tag_create' => [
                    'name' => 'sulu_tag_create',
                    'requirements' => [['context' => 'sulu.settings.tags', 'permission' => PermissionTypes::ADD]],
                    'contextArgument' => null, 'contextResolver' => null,
                    'objectResolved' => false, 'discoveryContexts' => [],
                ],
            ],
            $checker,
            $webspacePermissionResolver,
            new ArticleSecurityContextResolver(TestGroupProvider::singleGroup()),
            [],
            ['sulu_ping', 'sulu_get_context'],
        );
    }

    /**
     * A real WebspacePermissionResolver (it's final) granting EDIT only on
     * $permittedKeys, over a WebspaceManagerInterface mock returning $allKeys.
     *
     * @param list<string> $permittedKeys
     * @param list<string> $allKeys
     */
    private function webspacePermissionResolver(array $permittedKeys = [], array $allKeys = []): WebspacePermissionResolver
    {
        $webspaces = [];
        foreach ($allKeys as $key) {
            $webspace = new Webspace();
            $webspace->setKey($key);
            $webspaces[$key] = $webspace;
        }

        $webspaceManager = $this->createMock(WebspaceManagerInterface::class);
        $webspaceManager->method('getWebspaceCollection')->willReturn(new WebspaceCollection($webspaces));

        $securityChecker = $this->createMock(SecurityCheckerInterface::class);
        $securityChecker->method('hasPermission')->willReturnCallback(
            static fn ($condition): bool => \in_array(\str_replace('sulu.webspaces.', '', $condition->getSecurityContext()), $permittedKeys, true),
        );

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($this->createMock(UserInterface::class));
        $tokenStorage->method('getToken')->willReturn($token);

        return new WebspacePermissionResolver($webspaceManager, new ToolPermissionChecker($securityChecker, $tokenStorage));
    }

    public function testGetContextAddsDedupedFieldTypeLegend(): void
    {
        $templates = $this->createMock(TemplatesResource::class);
        $blocks = $this->createMock(BlocksResource::class);
        $webspaces = $this->createMock(WebspacesResource::class);
        $extensionFields = $this->createMock(ExtensionFieldsProvider::class);

        $templates->method('getTemplates')->willReturn([
            'page' => [
                'default' => [
                    'key' => 'default',
                    'fields' => [
                        ['name' => 'title', 'type' => 'text_line'],
                        ['name' => 'url', 'type' => 'route'],
                        ['name' => 'blocks', 'type' => 'block', 'types' => [
                            'text' => ['key' => 'text', 'fields' => [
                                ['name' => 'content', 'type' => 'text_editor'],
                            ]],
                        ]],
                    ],
                ],
            ],
        ]);
        $blocks->method('getBlocks')->willReturn([]);
        $webspaces->method('getWebspaces')->willReturn([]);
        $extensionFields->method('getExtensionFields')->willReturn(['seo' => [], 'excerpt' => []]);

        $tool = new GetContextTool($templates, $blocks, $webspaces, new FieldValueExampleProvider(), $extensionFields, $this->toolVisibilityResolver(), $this->webspacePermissionResolver());

        $result = $tool->getContext();

        $this->assertArrayHasKey('fieldTypes', $result);
        $this->assertArrayHasKey('text_line', $result['fieldTypes']);
        $this->assertArrayHasKey('text_editor', $result['fieldTypes']);
        $this->assertSame('Example text', $result['fieldTypes']['text_line']['example']);
        $this->assertStringContainsString('<sulu-link', (string) $result['fieldTypes']['text_editor']['example']);
        $this->assertArrayHasKey('hint', $result['fieldTypes']['text_editor']);

        // Types without example data are omitted (route, block, …)
        $this->assertArrayNotHasKey('route', $result['fieldTypes']);
        $this->assertArrayNotHasKey('block', $result['fieldTypes']);

        // Fields no longer carry inline examples (deduped into the legend)
        $titleField = $result['templates']['page']['default']['fields'][0];
        $this->assertArrayNotHasKey('valueExample', $titleField);
        $this->assertArrayNotHasKey('valueHint', $titleField);

        $this->assertArrayHasKey('seoFields', $result);
        $this->assertArrayHasKey('excerptFields', $result);
    }

    public function testGetContextOmitsLegendWhenNoKnownTypesPresent(): void
    {
        $templates = $this->createMock(TemplatesResource::class);
        $blocks = $this->createMock(BlocksResource::class);
        $webspaces = $this->createMock(WebspacesResource::class);
        $extensionFields = $this->createMock(ExtensionFieldsProvider::class);

        $templates->method('getTemplates')->willReturn([
            'page' => ['default' => ['key' => 'default', 'fields' => [
                ['name' => 'image', 'type' => 'media_selection'],
            ]]],
        ]);
        $blocks->method('getBlocks')->willReturn([]);
        $webspaces->method('getWebspaces')->willReturn([]);
        $extensionFields->method('getExtensionFields')->willReturn(['seo' => [], 'excerpt' => []]);

        $tool = new GetContextTool($templates, $blocks, $webspaces, new FieldValueExampleProvider(), $extensionFields, $this->toolVisibilityResolver(), $this->webspacePermissionResolver());

        $result = $tool->getContext();

        $this->assertSame([], $result['fieldTypes']);
        $this->assertArrayHasKey('seoFields', $result);
        $this->assertArrayHasKey('excerptFields', $result);
    }

    public function testGetContextIncludesToolCatalogue(): void
    {
        $templates = $this->createMock(TemplatesResource::class);
        $blocks = $this->createMock(BlocksResource::class);
        $webspaces = $this->createMock(WebspacesResource::class);
        $extensionFields = $this->createMock(ExtensionFieldsProvider::class);

        $templates->method('getTemplates')->willReturn([]);
        $blocks->method('getBlocks')->willReturn([]);
        $webspaces->method('getWebspaces')->willReturn([]);
        $extensionFields->method('getExtensionFields')->willReturn(['seo' => [], 'excerpt' => []]);

        $tool = new GetContextTool($templates, $blocks, $webspaces, new FieldValueExampleProvider(), $extensionFields, $this->toolVisibilityResolver(), $this->webspacePermissionResolver());

        $result = $tool->getContext();

        $this->assertArrayHasKey('tools', $result);
        $this->assertNotEmpty($result['tools']);
        foreach ($result['tools'] as $row) {
            $this->assertArrayHasKey('name', $row);
            $this->assertArrayHasKey('available', $row);
        }

        $byName = array_column($result['tools'], null, 'name');
        $this->assertFalse($byName['sulu_tag_create']['available']);
        $this->assertNotNull($byName['sulu_tag_create']['reason']);
        $this->assertTrue($byName['sulu_get_context']['available']);
    }

    /**
     * A null-locale permission check ignores locale-restricted roles
     * (AccessControlManager::getRolesForLocale), so the catalogue must be
     * evaluated for the caller's actual locale or it advertises tools that get denied.
     */
    public function testGetContextEvaluatesAvailabilityForTheRequestedLocale(): void
    {
        $templates = $this->createMock(TemplatesResource::class);
        $blocks = $this->createMock(BlocksResource::class);
        $webspaces = $this->createMock(WebspacesResource::class);
        $extensionFields = $this->createMock(ExtensionFieldsProvider::class);

        $templates->method('getTemplates')->willReturn([]);
        $blocks->method('getBlocks')->willReturn([]);
        $webspaces->method('getWebspaces')->willReturn([]);
        $extensionFields->method('getExtensionFields')->willReturn(['seo' => [], 'excerpt' => []]);

        $seenLocales = [];
        $checker = $this->createMock(ToolPermissionCheckerInterface::class);
        $checker
            ->method('has')
            ->willReturnCallback(static function (string $context, string $permission, ?string $locale = null) use (&$seenLocales): bool {
                $seenLocales[] = $locale;

                return false;
            });

        $visibilityResolver = new ToolVisibilityResolver(
            [
                'sulu_tag_create' => [
                    'name' => 'sulu_tag_create',
                    'requirements' => [['context' => 'sulu.settings.tags', 'permission' => PermissionTypes::ADD]],
                    'contextArgument' => null, 'contextResolver' => null,
                    'objectResolved' => false, 'discoveryContexts' => [],
                ],
            ],
            $checker,
            $this->webspacePermissionResolver(),
            new ArticleSecurityContextResolver(TestGroupProvider::singleGroup()),
            [],
            ['sulu_ping', 'sulu_get_context'],
        );

        $tool = new GetContextTool($templates, $blocks, $webspaces, new FieldValueExampleProvider(), $extensionFields, $visibilityResolver, $this->webspacePermissionResolver());

        $tool->getContext('de');

        $this->assertNotEmpty($seenLocales, 'The catalogue must consult the permission checker.');
        $this->assertSame(['de'], array_values(array_unique($seenLocales)));
    }

    public function testGetContextFiltersWebspacesToPermittedOnly(): void
    {
        $templates = $this->createMock(TemplatesResource::class);
        $blocks = $this->createMock(BlocksResource::class);
        $webspaces = $this->createMock(WebspacesResource::class);
        $extensionFields = $this->createMock(ExtensionFieldsProvider::class);

        $templates->method('getTemplates')->willReturn([]);
        $blocks->method('getBlocks')->willReturn([]);
        $webspaces->method('getWebspaces')->willReturn([
            ['key' => 'example', 'name' => 'Example', 'locales' => ['en'], 'url' => null],
            ['key' => 'blog', 'name' => 'Blog', 'locales' => ['en'], 'url' => null],
        ]);
        $extensionFields->method('getExtensionFields')->willReturn(['seo' => [], 'excerpt' => []]);

        $resolver = $this->webspacePermissionResolver(['example'], ['example', 'blog']);

        $tool = new GetContextTool($templates, $blocks, $webspaces, new FieldValueExampleProvider(), $extensionFields, $this->toolVisibilityResolver(), $resolver);

        $result = $tool->getContext();

        $this->assertCount(1, $result['webspaces']);
        $this->assertSame('example', $result['webspaces'][0]['key']);
    }
}
