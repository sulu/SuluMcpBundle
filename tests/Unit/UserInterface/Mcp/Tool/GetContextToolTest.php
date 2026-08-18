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
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Component\Localization\Localization;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceCollection;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Component\Webspace\Webspace;
use Sulu\Mcp\Application\Metadata\ExtensionFieldsProvider;
use Sulu\Mcp\Application\Metadata\FieldNormalizer;
use Sulu\Mcp\Application\Metadata\FieldValueExampleProvider;
use Sulu\Mcp\Application\Metadata\MetadataLocaleResolver;
use Sulu\Mcp\Application\Security\ToolPermissionChecker;
use Sulu\Mcp\Application\Security\ToolVisibilityResolver;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\Tests\Application\TestBundle\Metadata\TestGroupProvider;
use Sulu\Mcp\Tests\Unit\Fixture\ArrayMetadataProvider;
use Sulu\Mcp\Tests\Unit\Fixture\FakeToolPermissionChecker;
use Sulu\Mcp\Tests\Unit\Fixture\TestUser;
use Sulu\Mcp\UserInterface\Mcp\Resource\GlobalBlocksResource;
use Sulu\Mcp\UserInterface\Mcp\Resource\TemplatesResource;
use Sulu\Mcp\UserInterface\Mcp\Resource\WebspacesResource;
use Sulu\Mcp\UserInterface\Mcp\Tool\GetContextTool;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

#[CoversClass(GetContextTool::class)]
final class GetContextToolTest extends TestCase
{
    use ProphecyTrait;

    /**
     * A real TemplatesResource over an ArrayMetadataProvider; $pageMetadata, when
     * given, is registered under the 'page' content type.
     */
    private function templatesResource(?TypedFormMetadata $pageMetadata = null): TemplatesResource
    {
        $provider = new ArrayMetadataProvider();
        if (null !== $pageMetadata) {
            $provider->set('page', $pageMetadata);
        }

        return new TemplatesResource($provider, new FieldNormalizer(), new MetadataLocaleResolver(new TokenStorage(), 'en'));
    }

    /**
     * A real GlobalBlocksResource over an ArrayMetadataProvider registered with
     * empty 'block' metadata (GlobalBlocksResource does not catch a missing key).
     */
    private function globalBlocksResource(): GlobalBlocksResource
    {
        $provider = new ArrayMetadataProvider();
        $provider->set('block', new TypedFormMetadata());

        return new GlobalBlocksResource($provider, new FieldNormalizer(), new MetadataLocaleResolver(new TokenStorage(), 'en'));
    }

    /**
     * A real WebspacesResource over a WebspaceManagerInterface double returning $webspaces.
     *
     * @param array<string, Webspace> $webspaces
     */
    private function webspacesResource(array $webspaces = []): WebspacesResource
    {
        /** @var ObjectProphecy<WebspaceManagerInterface> $webspaceManager */
        $webspaceManager = $this->prophesize(WebspaceManagerInterface::class);
        $webspaceManager->getWebspaceCollection()->willReturn(new WebspaceCollection($webspaces));

        return new WebspacesResource($webspaceManager->reveal());
    }

    /**
     * @param list<string> $locales
     */
    private function webspace(string $key, string $name, array $locales): Webspace
    {
        $webspace = new Webspace();
        $webspace->setKey($key);
        $webspace->setName($name);
        $webspace->setLocalizations(\array_map(
            static fn (string $locale): Localization => new Localization($locale),
            $locales,
        ));

        return $webspace;
    }

    /**
     * Real ToolVisibilityResolver (final) with a permission checker that denies
     * everything, mirroring ToolVisibilityResolverTest's helper.
     */
    private function toolVisibilityResolver(): ToolVisibilityResolver
    {
        $checker = FakeToolPermissionChecker::grantingAll();
        $checker->denyAll();

        /** @var ObjectProphecy<WebspaceManagerInterface> $webspaceManager */
        $webspaceManager = $this->prophesize(WebspaceManagerInterface::class);
        $webspaceManager->getWebspaceCollection()->willReturn(new WebspaceCollection([]));

        /** @var ObjectProphecy<SecurityCheckerInterface> $securityChecker */
        $securityChecker = $this->prophesize(SecurityCheckerInterface::class);
        $securityChecker->hasPermission(Argument::cetera())->willReturn(false);

        $tokenStorage = (new TestUser())->inTokenStorage();

        $webspacePermissionResolver = new WebspacePermissionResolver(
            $webspaceManager->reveal(),
            new ToolPermissionChecker($securityChecker->reveal(), $tokenStorage),
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
     * A real WebspacePermissionResolver (it's final) granting VIEW only on
     * $permittedKeys, over a WebspaceManagerInterface double returning $allKeys.
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

        /** @var ObjectProphecy<WebspaceManagerInterface> $webspaceManager */
        $webspaceManager = $this->prophesize(WebspaceManagerInterface::class);
        $webspaceManager->getWebspaceCollection()->willReturn(new WebspaceCollection($webspaces));

        /** @var ObjectProphecy<SecurityCheckerInterface> $securityChecker */
        $securityChecker = $this->prophesize(SecurityCheckerInterface::class);
        $securityChecker->hasPermission(Argument::cetera())->will(
            static fn (array $args): bool => \in_array(\str_replace('sulu.webspaces.', '', $args[0]->getSecurityContext()), $permittedKeys, true),
        );

        $tokenStorage = (new TestUser())->inTokenStorage();

        return new WebspacePermissionResolver($webspaceManager->reveal(), new ToolPermissionChecker($securityChecker->reveal(), $tokenStorage));
    }

    public function testGetContextAddsDedupedFieldTypeLegend(): void
    {
        $titleField = new FieldMetadata('title');
        $titleField->setType('text_line');

        $urlField = new FieldMetadata('url');
        $urlField->setType('route');

        $contentField = new FieldMetadata('content');
        $contentField->setType('text_editor');

        $textType = new FormMetadata();
        $textType->setKey('text');
        $textType->addItem($contentField);

        $blocksField = new FieldMetadata('blocks');
        $blocksField->setType('block');
        $blocksField->addType($textType);

        $form = new FormMetadata();
        $form->setKey('default');
        $form->addItem($titleField);
        $form->addItem($urlField);
        $form->addItem($blocksField);

        $pageMetadata = new TypedFormMetadata();
        $pageMetadata->addForm('default', $form);

        $tool = new GetContextTool(
            $this->templatesResource($pageMetadata),
            $this->globalBlocksResource(),
            $this->webspacesResource(),
            new FieldValueExampleProvider(),
            new ExtensionFieldsProvider(new ArrayMetadataProvider(), new MetadataLocaleResolver(new TokenStorage(), 'en')),
            $this->toolVisibilityResolver(),
            $this->webspacePermissionResolver(),
        );

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
        $titleFieldResult = $result['templates']['page']['default']['fields'][0];
        $this->assertArrayNotHasKey('valueExample', $titleFieldResult);
        $this->assertArrayNotHasKey('valueHint', $titleFieldResult);

        $this->assertArrayHasKey('seoFields', $result);
        $this->assertArrayHasKey('excerptFields', $result);
    }

    public function testGetContextOmitsLegendWhenNoKnownTypesPresent(): void
    {
        $imageField = new FieldMetadata('image');
        $imageField->setType('media_selection');

        $form = new FormMetadata();
        $form->setKey('default');
        $form->addItem($imageField);

        $pageMetadata = new TypedFormMetadata();
        $pageMetadata->addForm('default', $form);

        $tool = new GetContextTool(
            $this->templatesResource($pageMetadata),
            $this->globalBlocksResource(),
            $this->webspacesResource(),
            new FieldValueExampleProvider(),
            new ExtensionFieldsProvider(new ArrayMetadataProvider(), new MetadataLocaleResolver(new TokenStorage(), 'en')),
            $this->toolVisibilityResolver(),
            $this->webspacePermissionResolver(),
        );

        $result = $tool->getContext();

        $this->assertSame([], $result['fieldTypes']);
        $this->assertArrayHasKey('seoFields', $result);
        $this->assertArrayHasKey('excerptFields', $result);
    }

    public function testGetContextIncludesToolCatalogue(): void
    {
        $tool = new GetContextTool(
            $this->templatesResource(),
            $this->globalBlocksResource(),
            $this->webspacesResource(),
            new FieldValueExampleProvider(),
            new ExtensionFieldsProvider(new ArrayMetadataProvider(), new MetadataLocaleResolver(new TokenStorage(), 'en')),
            $this->toolVisibilityResolver(),
            $this->webspacePermissionResolver(),
        );

        $result = $tool->getContext();

        $this->assertArrayHasKey('tools', $result);
        $this->assertNotEmpty($result['tools']);
        foreach ($result['tools'] as $row) {
            $this->assertArrayHasKey('name', $row);
            $this->assertArrayHasKey('available', $row);
        }

        $byName = \array_column($result['tools'], null, 'name');
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
        $checker = FakeToolPermissionChecker::grantingAll()->denyAll();

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

        $tool = new GetContextTool(
            $this->templatesResource(),
            $this->globalBlocksResource(),
            $this->webspacesResource(),
            new FieldValueExampleProvider(),
            new ExtensionFieldsProvider(new ArrayMetadataProvider(), new MetadataLocaleResolver(new TokenStorage(), 'en')),
            $visibilityResolver,
            $this->webspacePermissionResolver(),
        );

        $tool->getContext('de');

        $this->assertNotEmpty(\array_column($checker->allCalls(), 'locale'), 'The catalogue must consult the permission checker.');
        $this->assertSame(['de'], \array_values(\array_unique(\array_column($checker->allCalls(), 'locale'))));
    }

    public function testGetContextFiltersWebspacesToPermittedOnly(): void
    {
        $webspaces = [
            'example' => $this->webspace('example', 'Example', ['en']),
            'blog' => $this->webspace('blog', 'Blog', ['en']),
        ];

        $resolver = $this->webspacePermissionResolver(['example'], ['example', 'blog']);

        $tool = new GetContextTool(
            $this->templatesResource(),
            $this->globalBlocksResource(),
            $this->webspacesResource($webspaces),
            new FieldValueExampleProvider(),
            new ExtensionFieldsProvider(new ArrayMetadataProvider(), new MetadataLocaleResolver(new TokenStorage(), 'en')),
            $this->toolVisibilityResolver(),
            $resolver,
        );

        $result = $tool->getContext();

        $this->assertCount(1, $result['webspaces']);
        $this->assertSame('example', $result['webspaces'][0]['key']);
    }
}
