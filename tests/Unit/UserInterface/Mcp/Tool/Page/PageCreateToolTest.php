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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\Page;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Application\Content\BlockDataValidator;
use Sulu\Mcp\Application\Content\ContentMetadataMapper;
use Sulu\Mcp\Application\Metadata\MetadataLocaleResolver;
use Sulu\Mcp\Infrastructure\Sulu\AdminLink\PageAdminLinkProvider;
use Sulu\Mcp\Infrastructure\Symfony\Routing\AdminLinkGenerator;
use Sulu\Mcp\Tests\Application\TestBundle\Admin\TestViewRegistry;
use Sulu\Mcp\Tests\Unit\Fixture\ArrayMetadataProvider;
use Sulu\Mcp\Tests\Unit\Fixture\FakeToolPermissionChecker;
use Sulu\Mcp\Tests\Unit\Fixture\FixedBlockIdGenerator;
use Sulu\Mcp\UserInterface\Mcp\Tool\Page\PageCreateTool;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Page\Application\Message\CreatePageMessage;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Model\PageDimensionContent;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Loader\ClosureLoader;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Router;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

#[CoversClass(PageCreateTool::class)]
final class PageCreateToolTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<MessageBusInterface> */
    private ObjectProphecy $messageBus;

    /** @var ObjectProphecy<ContentManagerInterface> */
    private ObjectProphecy $contentManager;

    private ArrayMetadataProvider $formMetadataProvider;
    private ArrayMetadataProvider $mapperMetadataProvider;
    private FixedBlockIdGenerator $blockIdGenerator;

    /** @var ObjectProphecy<PageRepositoryInterface> */
    private ObjectProphecy $pageRepository;

    private FakeToolPermissionChecker $permissionChecker;
    private AdminLinkGenerator $adminLinkGenerator;
    private PageCreateTool $tool;

    protected function setUp(): void
    {
        $this->messageBus = $this->prophesize(MessageBusInterface::class);
        $this->contentManager = $this->prophesize(ContentManagerInterface::class);
        $this->formMetadataProvider = new ArrayMetadataProvider();
        // Default: provider returns a non-typed metadata so the validator skips strict checks.
        $this->formMetadataProvider->setDefault(new FormMetadata());
        $this->mapperMetadataProvider = new ArrayMetadataProvider();
        // Provide Sulu's native SEO/excerpt field names so the mapper places them correctly.
        $this->mapperMetadataProvider->set('content_seo_metadata', $this->makeFormMeta(['seo/title', 'seo/description', 'seo/keywords', 'seo/canonicalUrl', 'seoNoIndex', 'seoNoFollow', 'seoHideInSitemap']));
        $this->mapperMetadataProvider->set('content_excerpt_metadata', $this->makeFormMeta(['excerpt/title', 'excerpt/more', 'excerpt/description', 'excerpt/icon', 'excerpt/image']));
        $this->mapperMetadataProvider->set('content_excerpt_taxonomies', $this->makeFormMeta(['excerptCategories', 'excerptTags']));
        $this->mapperMetadataProvider->setDefault($this->makeFormMeta([]));
        $this->blockIdGenerator = new FixedBlockIdGenerator('gen-id');

        $this->adminLinkGenerator = new AdminLinkGenerator($this->router(), [new PageAdminLinkProvider(new TestViewRegistry())]);

        $this->pageRepository = $this->prophesize(PageRepositoryInterface::class);
        // Default: parent resolves into the same webspace used across the existing
        // tests below ('example'), so the new parent checks are transparent to them.
        $parentPage = new Page();
        $parentPage->setWebspaceKey('example');
        $this->pageRepository->getOneBy(Argument::cetera())->willReturn($parentPage);

        $this->permissionChecker = FakeToolPermissionChecker::grantingAll();

        $this->tool = new PageCreateTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            new BlockDataValidator($this->formMetadataProvider, new MetadataLocaleResolver(new TokenStorage(), 'en')),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider),
            $this->adminLinkGenerator,
            $this->pageRepository->reveal(),
            $this->permissionChecker,
        );
    }

    private function router(): Router
    {
        $routes = new RouteCollection();
        $routes->add('sulu_admin', new Route('/admin/'));

        return new Router(
            new ClosureLoader(),
            static fn () => $routes,
            [],
            new RequestContext(host: 'example.com', scheme: 'https'),
        );
    }

    /** @param list<string> $names */
    private function makeFormMeta(array $names): FormMetadata
    {
        $form = new FormMetadata();
        foreach ($names as $name) {
            $form->addItem(new FieldMetadata($name));
        }

        return $form;
    }

    public function testCreatePageDispatchesCreatePageMessage(): void
    {
        $mockPage = new Page('page-uuid-123');
        $mockPage->setWebspaceKey('example');

        $capturedEnvelope = null;
        $this->messageBus->dispatch(Argument::type(Envelope::class), Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use ($mockPage, &$capturedEnvelope) {
                $capturedEnvelope = $args[0];

                return $args[0]->with(new HandledStamp($mockPage, 'handler'));
            });

        $mockDimensionContent = new PageDimensionContent(new Page());
        $this->contentManager->resolve(Argument::cetera())->willReturn($mockDimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn(['title' => 'Test Page']);

        $result = $this->tool->createPage('example', 'en', 'default', 'Test Page', 'parent-uuid');

        $this->assertTrue($result['success']);
        $this->assertSame('page-uuid-123', $result['uuid']);

        $this->assertInstanceOf(CreatePageMessage::class, $capturedEnvelope->getMessage());
        $this->assertArrayHasKey(EnableFlushStamp::class, $capturedEnvelope->all());
    }

    public function testCreatePageIncludesLocaleInData(): void
    {
        $mockPage = new Page('uuid-1');
        $mockPage->setWebspaceKey('example');

        $capturedEnvelope = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use ($mockPage, &$capturedEnvelope) {
                $capturedEnvelope = $args[0];

                return $args[0]->with(new HandledStamp($mockPage, 'handler'));
            });

        $mockDimensionContent = new PageDimensionContent(new Page());
        $this->contentManager->resolve(Argument::cetera())->willReturn($mockDimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([]);

        $this->tool->createPage('example', 'en', 'default', 'Test', 'parent-uuid');

        $this->assertInstanceOf(CreatePageMessage::class, $capturedEnvelope->getMessage());
    }

    public function testCreatePageGeneratesUrlFromTitleWhenUrlIsNull(): void
    {
        $mockPage = new Page('uuid-1');
        $mockPage->setWebspaceKey('example');

        $capturedMessage = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use ($mockPage, &$capturedMessage) {
                $capturedMessage = $args[0]->getMessage();

                return $args[0]->with(new HandledStamp($mockPage, 'handler'));
            });

        $mockDimensionContent = new PageDimensionContent(new Page());
        $this->contentManager->resolve(Argument::cetera())->willReturn($mockDimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([]);

        $this->tool->createPage('example', 'en', 'default', 'My Test Page', 'parent-uuid');

        $this->assertInstanceOf(CreatePageMessage::class, $capturedMessage);
    }

    public function testCreatePageMergesContentIntoData(): void
    {
        $mockPage = new Page('uuid-1');
        $mockPage->setWebspaceKey('example');

        $this->messageBus->dispatch(Argument::cetera())
            ->will(fn (array $args) => $args[0]->with(new HandledStamp($mockPage, 'handler')));

        $mockDimensionContent = new PageDimensionContent(new Page());
        $this->contentManager->resolve(Argument::cetera())->willReturn($mockDimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([]);

        $result = $this->tool->createPage(
            'example',
            'en',
            'default',
            'Test',
            'parent-uuid',
            null,
            ['excerpt' => 'Test excerpt'],
        );

        $this->assertTrue($result['success']);
    }

    public function testCreatePageResolvesAndNormalizesResult(): void
    {
        $mockPage = new Page('uuid-1');
        $mockPage->setWebspaceKey('example');

        $this->messageBus->dispatch(Argument::cetera())
            ->will(fn (array $args) => $args[0]->with(new HandledStamp($mockPage, 'handler')));

        $mockDimensionContent = new PageDimensionContent(new Page());
        $this->contentManager->resolve($mockPage, [
            'locale' => 'en',
            'stage' => DimensionContentInterface::STAGE_DRAFT,
        ])
            ->shouldBeCalledOnce()
            ->willReturn($mockDimensionContent);

        $this->contentManager->normalize($mockDimensionContent)
            ->shouldBeCalledOnce()
            ->willReturn(['title' => 'Resolved Title']);

        $result = $this->tool->createPage('example', 'en', 'default', 'Test', 'parent-uuid');

        $this->assertSame(['title' => 'Resolved Title'], $result['data']);
    }

    public function testCreatePageReturnsSuccessWithUuid(): void
    {
        $mockPage = new Page('new-page-uuid');
        $mockPage->setWebspaceKey('example');

        $this->messageBus->dispatch(Argument::cetera())
            ->will(fn (array $args) => $args[0]->with(new HandledStamp($mockPage, 'handler')));

        $mockDimensionContent = new PageDimensionContent(new Page());
        $this->contentManager->resolve(Argument::cetera())->willReturn($mockDimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([]);

        $result = $this->tool->createPage('example', 'en', 'default', 'Test', 'parent-uuid');

        $this->assertTrue($result['success']);
        $this->assertSame('new-page-uuid', $result['uuid']);
        $this->assertArrayHasKey('data', $result);
        $this->assertSame(
            'https://example.com/admin/#/webspaces/example/pages/en/new-page-uuid',
            $result['admin_url'],
        );
    }

    public function testCreatePageReturnsErrorOnException(): void
    {
        $this->messageBus->dispatch(Argument::cetera())
            ->willThrow(new \RuntimeException('Page creation failed'));

        $result = $this->tool->createPage('example', 'en', 'default', 'Test', 'parent-uuid');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Page creation failed', $result['error']);
        $this->assertArrayNotHasKey('success', $result);
    }

    public function testCreatePageMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(PageCreateTool::class, 'createPage');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes, 'createPage() must have exactly one #[McpTool] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_page_create', $instance->name);
    }

    public function testCreatePageAssignsBlockIdsToNestedBlocks(): void
    {
        $mockPage = new Page('uuid-1');
        $mockPage->setWebspaceKey('example');

        $capturedEnvelope = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use ($mockPage, &$capturedEnvelope) {
                $capturedEnvelope = $args[0];

                return $args[0]->with(new HandledStamp($mockPage, 'handler'));
            });

        $mockDimensionContent = new PageDimensionContent(new Page());
        $this->contentManager->resolve(Argument::cetera())->willReturn($mockDimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([]);

        $this->tool->createPage(
            'example',
            'en',
            'default',
            'Test',
            'parent-uuid',
            null,
            [
                'blocks' => [
                    ['type' => 'text', 'title' => 'A'],
                    ['type' => 'section', 'title' => 'S', 'blocks' => [
                        ['type' => 'text', 'title' => 'N'],
                    ]],
                ],
            ],
        );

        $message = $capturedEnvelope->getMessage();
        $this->assertInstanceOf(CreatePageMessage::class, $message);
        $capturedData = (new \ReflectionProperty($message, 'data'))->getValue($message);

        $this->assertNotNull($capturedData);
        $blocks = $capturedData['blocks'];
        $this->assertNotEmpty($blocks[0]['_id']);
        $this->assertNotEmpty($blocks[1]['_id']);
        $this->assertNotEmpty($blocks[1]['blocks'][0]['_id']);
    }

    public function testCreatePageRejectsInvalidBlocksBeforeWrite(): void
    {
        $titleField = new FieldMetadata('title');
        $titleField->setType('text_line');
        $textBlock = new FormMetadata();
        $textBlock->setKey('text');
        $textBlock->addItem($titleField);

        $blocksField = new FieldMetadata('blocks');
        $blocksField->setType('block');
        $blocksField->addType($textBlock);

        $template = new FormMetadata();
        $template->setKey('default');
        $template->addItem($blocksField);

        $typed = new TypedFormMetadata();
        $typed->addForm('default', $template);

        $this->formMetadataProvider = new ArrayMetadataProvider();
        $this->formMetadataProvider->set('page', $typed);

        $this->tool = new PageCreateTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            new BlockDataValidator($this->formMetadataProvider, new MetadataLocaleResolver(new TokenStorage(), 'en')),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider),
            $this->adminLinkGenerator,
            $this->pageRepository->reveal(),
            $this->permissionChecker,
        );

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->createPage(
            'example',
            'en',
            'default',
            'Test',
            'parent-uuid',
            null,
            ['blocks' => [['type' => 'text', 'bogus' => 'x']]],
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('bogus', $result['error']);
    }

    public function testCreatePageReturnsMapperErrorWithoutDispatchingWhenUnknownSeoField(): void
    {
        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->createPage(
            'example',
            'en',
            'default',
            'Test',
            'parent-uuid',
            null,
            null,
            null,
            ['bogusField' => 'x'],
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('bogusField', $result['error']);
    }

    public function testCreatePageAppliesExcerptAndSeoToDispatchedMessage(): void
    {
        $mockPage = new Page('uuid-1');
        $mockPage->setWebspaceKey('example');

        $capturedEnvelope = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use ($mockPage, &$capturedEnvelope) {
                $capturedEnvelope = $args[0];

                return $args[0]->with(new HandledStamp($mockPage, 'handler'));
            });

        $mockDimensionContent = new PageDimensionContent(new Page());
        $this->contentManager->resolve(Argument::cetera())->willReturn($mockDimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([]);

        $this->tool->createPage(
            'example',
            'en',
            'default',
            'Test',
            'parent-uuid',
            null,
            null,
            ['title' => 'T', 'description' => '<p>D</p>', 'image' => ['id' => 5]],
            ['title' => 'S', 'description' => 'meta', 'seoNoIndex' => true],
        );

        $message = $capturedEnvelope->getMessage();
        $this->assertInstanceOf(CreatePageMessage::class, $message);
        $capturedData = (new \ReflectionProperty($message, 'data'))->getValue($message);

        $this->assertNotNull($capturedData);
        $this->assertSame('T', $capturedData['excerpt']['title']);
        $this->assertSame(['id' => 5], $capturedData['excerpt']['image']);
        $this->assertSame('S', $capturedData['seo']['title']);
        $this->assertTrue($capturedData['seoNoIndex']);
    }

    public function testCreatePageLoadsParentWithCorrectFilters(): void
    {
        $mockPage = new Page('uuid-1');
        $mockPage->setWebspaceKey('example');

        $this->pageRepository = $this->prophesize(PageRepositoryInterface::class);
        $parentPage = new Page();
        $parentPage->setWebspaceKey('example');

        $this->pageRepository
            ->getOneBy(
                [
                    'uuid' => 'parent-uuid',
                    'locale' => 'en',
                    'stage' => DimensionContentInterface::STAGE_DRAFT,
                ],
                [
                    PageRepositoryInterface::GROUP_SELECT_PAGE_ADMIN => true,
                ],
            )
            ->shouldBeCalledOnce()
            ->willReturn($parentPage);

        $this->tool = new PageCreateTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            new BlockDataValidator($this->formMetadataProvider, new MetadataLocaleResolver(new TokenStorage(), 'en')),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider),
            $this->adminLinkGenerator,
            $this->pageRepository->reveal(),
            $this->permissionChecker,
        );

        $this->messageBus->dispatch(Argument::cetera())
            ->will(fn (array $args) => $args[0]->with(new HandledStamp($mockPage, 'handler')));

        $mockDimensionContent = new PageDimensionContent(new Page());
        $this->contentManager->resolve(Argument::cetera())->willReturn($mockDimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([]);

        $this->tool->createPage('example', 'en', 'default', 'Test', 'parent-uuid');
    }

    public function testCreatePageChecksObjectPermissionOnParent(): void
    {
        $mockPage = new Page('uuid-1');
        $mockPage->setWebspaceKey('example');

        $this->messageBus->dispatch(Argument::cetera())
            ->will(fn (array $args) => $args[0]->with(new HandledStamp($mockPage, 'handler')));

        $mockDimensionContent = new PageDimensionContent(new Page());
        $this->contentManager->resolve(Argument::cetera())->willReturn($mockDimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([]);

        $this->tool->createPage('example', 'en', 'default', 'Test', 'parent-uuid');

        self::assertSame([[
            'context' => 'sulu.webspaces.example',
            'permissions' => [PermissionTypes::EDIT, PermissionTypes::ADD],
            'locale' => 'en',
            'objectType' => Page::class,
            'objectId' => 'parent-uuid',
        ]], $this->permissionChecker->calls());
    }

    public function testCreatePageDeniesWhenParentInDifferentWebspace(): void
    {
        $parentPage = new Page();
        $parentPage->setWebspaceKey('other-webspace');
        $this->pageRepository = $this->prophesize(PageRepositoryInterface::class);
        $this->pageRepository->getOneBy(Argument::cetera())->willReturn($parentPage);

        $this->tool = new PageCreateTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            new BlockDataValidator($this->formMetadataProvider, new MetadataLocaleResolver(new TokenStorage(), 'en')),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider),
            $this->adminLinkGenerator,
            $this->pageRepository->reveal(),
            $this->permissionChecker,
        );

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        try {
            $this->tool->createPage('example', 'en', 'default', 'Test', 'parent-uuid');
            $this->fail('Expected ' . ToolCallException::class);
        } catch (ToolCallException) {
            $this->assertSame([], $this->permissionChecker->calls());
        }
    }

    public function testCreatePageForcesTrustedLocaleAndTemplateOverMetadataClobbering(): void
    {
        // Regression guard: excerpt/seo fields literally named "locale"/"template" let
        // ContentMetadataMapper::place() clobber the trusted args that passed the EDIT preflight.
        $mockPage = new Page('uuid-1');
        $mockPage->setWebspaceKey('example');

        $mapperMetadataProvider = new ArrayMetadataProvider();
        $mapperMetadataProvider->set('content_excerpt_metadata', $this->makeFormMeta(['locale']));
        $mapperMetadataProvider->set('content_seo_metadata', $this->makeFormMeta(['template']));
        $mapperMetadataProvider->setDefault($this->makeFormMeta([]));

        $tool = new PageCreateTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            new BlockDataValidator($this->formMetadataProvider, new MetadataLocaleResolver(new TokenStorage(), 'en')),
            $this->blockIdGenerator,
            new ContentMetadataMapper($mapperMetadataProvider),
            $this->adminLinkGenerator,
            $this->pageRepository->reveal(),
            $this->permissionChecker,
        );

        $capturedEnvelope = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use ($mockPage, &$capturedEnvelope) {
                $capturedEnvelope = $args[0];

                return $args[0]->with(new HandledStamp($mockPage, 'handler'));
            });

        $mockDimensionContent = new PageDimensionContent(new Page());
        $this->contentManager->resolve(Argument::cetera())->willReturn($mockDimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([]);

        $result = $tool->createPage(
            'example',
            'en',
            'default',
            'Test',
            'parent-uuid',
            null,
            null,
            ['locale' => 'de'],
            ['template' => 'smuggled'],
        );

        $message = $capturedEnvelope->getMessage();
        $this->assertInstanceOf(CreatePageMessage::class, $message);
        $capturedData = (new \ReflectionProperty($message, 'data'))->getValue($message);

        $this->assertTrue($result['success']);
        $this->assertSame('en', $capturedData['locale']);
        $this->assertSame('default', $capturedData['template']);
    }

    public function testCreatePageDeniesWhenParentAclDenied(): void
    {
        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $this->permissionChecker->denyAll();

        $this->expectException(ToolCallException::class);

        $this->tool->createPage('example', 'en', 'default', 'Test', 'parent-uuid');
    }
}
