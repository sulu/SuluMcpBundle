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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Application\BlockIdGenerator\BlockIdGeneratorInterface;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataInterface;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Application\Content\BlockDataValidator;
use Sulu\Mcp\Application\Content\ContentMetadataMapper;
use Sulu\Mcp\Application\Metadata\MetadataLocaleResolver;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\Infrastructure\Sulu\AdminLink\PageAdminLinkProvider;
use Sulu\Mcp\Infrastructure\Symfony\Routing\AdminLinkGenerator;
use Sulu\Mcp\Tests\Application\TestBundle\Admin\TestViewRegistry;
use Sulu\Mcp\UserInterface\Mcp\Tool\Page\PageCreateTool;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Page\Application\Message\CreatePageMessage;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

#[CoversClass(PageCreateTool::class)]
final class PageCreateToolTest extends TestCase
{
    private MessageBusInterface&MockObject $messageBus;
    private ContentManagerInterface&MockObject $contentManager;
    private MetadataProviderInterface&MockObject $formMetadataProvider;
    private MetadataProviderInterface&MockObject $mapperMetadataProvider;
    private BlockIdGeneratorInterface&MockObject $blockIdGenerator;
    private PageRepositoryInterface&MockObject $pageRepository;
    private ToolPermissionCheckerInterface&MockObject $permissionChecker;
    private AdminLinkGenerator $adminLinkGenerator;
    private PageCreateTool $tool;

    protected function setUp(): void
    {
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->contentManager = $this->createMock(ContentManagerInterface::class);
        $this->formMetadataProvider = $this->createMock(MetadataProviderInterface::class);
        // Default: provider returns a non-typed metadata so the validator skips strict checks.
        $this->formMetadataProvider->method('getMetadata')->willReturn($this->createMock(MetadataInterface::class));
        $this->mapperMetadataProvider = $this->createMock(MetadataProviderInterface::class);
        // Provide Sulu's native SEO/excerpt field names so the mapper places them correctly.
        $this->mapperMetadataProvider->method('getMetadata')->willReturnCallback(
            fn (string $key) => match ($key) {
                'content_seo_metadata' => $this->makeFormMeta(['seo/title', 'seo/description', 'seo/keywords', 'seo/canonicalUrl', 'seoNoIndex', 'seoNoFollow', 'seoHideInSitemap']),
                'content_excerpt_metadata' => $this->makeFormMeta(['excerpt/title', 'excerpt/more', 'excerpt/description', 'excerpt/icon', 'excerpt/image']),
                'content_excerpt_taxonomies' => $this->makeFormMeta(['excerptCategories', 'excerptTags']),
                default => $this->makeFormMeta([]),
            },
        );
        $this->blockIdGenerator = $this->createMock(BlockIdGeneratorInterface::class);
        $this->blockIdGenerator->method('generateId')->willReturn('gen-id');

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturn('https://example.com/admin/');
        $this->adminLinkGenerator = new AdminLinkGenerator($router, [new PageAdminLinkProvider(new TestViewRegistry())]);

        $this->pageRepository = $this->createMock(PageRepositoryInterface::class);
        // Default: parent resolves into the same webspace used across the existing
        // tests below ('example'), so the new parent checks are transparent to them.
        $parentPage = $this->createMock(PageInterface::class);
        $parentPage->method('getWebspaceKey')->willReturn('example');
        $this->pageRepository->method('getOneBy')->willReturn($parentPage);

        $this->permissionChecker = $this->createMock(ToolPermissionCheckerInterface::class);

        $this->tool = new PageCreateTool(
            $this->messageBus,
            $this->contentManager,
            new BlockDataValidator($this->formMetadataProvider, new MetadataLocaleResolver(new TokenStorage(), 'en')),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider),
            $this->adminLinkGenerator,
            $this->pageRepository,
            $this->permissionChecker,
        );
    }

    /** @param list<string> $names */
    private function makeFormMeta(array $names): FormMetadata
    {
        $items = [];
        foreach ($names as $name) {
            $field = $this->createMock(FieldMetadata::class);
            $field->method('getName')->willReturn($name);
            $items[$name] = $field;
        }
        $form = $this->createMock(FormMetadata::class);
        $form->method('getFlatFieldMetadata')->willReturn($items);

        return $form;
    }

    public function testCreatePageDispatchesCreatePageMessage(): void
    {
        $mockPage = $this->createMock(PageInterface::class);
        $mockPage->method('getUuid')->willReturn('page-uuid-123');

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function(Envelope $envelope) use ($mockPage) {
                $message = $envelope->getMessage();
                $this->assertInstanceOf(CreatePageMessage::class, $message);

                $stamps = $envelope->all();
                $this->assertArrayHasKey(EnableFlushStamp::class, $stamps);

                return $envelope->with(new HandledStamp($mockPage, 'handler'));
            });

        $mockDimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->method('resolve')->willReturn($mockDimensionContent);
        $this->contentManager->method('normalize')->willReturn(['title' => 'Test Page']);

        $result = $this->tool->createPage('example', 'en', 'default', 'Test Page', 'parent-uuid');

        $this->assertTrue($result['success']);
        $this->assertSame('page-uuid-123', $result['uuid']);
    }

    public function testCreatePageIncludesLocaleInData(): void
    {
        $mockPage = $this->createMock(PageInterface::class);
        $mockPage->method('getUuid')->willReturn('uuid-1');

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function(Envelope $envelope) use ($mockPage) {
                /** @var CreatePageMessage $message */
                $message = $envelope->getMessage();
                $this->assertInstanceOf(CreatePageMessage::class, $message);

                return $envelope->with(new HandledStamp($mockPage, 'handler'));
            });

        $mockDimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->method('resolve')->willReturn($mockDimensionContent);
        $this->contentManager->method('normalize')->willReturn([]);

        $this->tool->createPage('example', 'en', 'default', 'Test', 'parent-uuid');
    }

    public function testCreatePageGeneratesUrlFromTitleWhenUrlIsNull(): void
    {
        $mockPage = $this->createMock(PageInterface::class);
        $mockPage->method('getUuid')->willReturn('uuid-1');

        $capturedMessage = null;
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function(Envelope $envelope) use ($mockPage, &$capturedMessage) {
                $capturedMessage = $envelope->getMessage();

                return $envelope->with(new HandledStamp($mockPage, 'handler'));
            });

        $mockDimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->method('resolve')->willReturn($mockDimensionContent);
        $this->contentManager->method('normalize')->willReturn([]);

        $this->tool->createPage('example', 'en', 'default', 'My Test Page', 'parent-uuid');

        $this->assertInstanceOf(CreatePageMessage::class, $capturedMessage);
    }

    public function testCreatePageMergesContentIntoData(): void
    {
        $mockPage = $this->createMock(PageInterface::class);
        $mockPage->method('getUuid')->willReturn('uuid-1');

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(fn (Envelope $envelope) => $envelope->with(new HandledStamp($mockPage, 'handler')));

        $mockDimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->method('resolve')->willReturn($mockDimensionContent);
        $this->contentManager->method('normalize')->willReturn([]);

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
        $mockPage = $this->createMock(PageInterface::class);
        $mockPage->method('getUuid')->willReturn('uuid-1');

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(fn (Envelope $envelope) => $envelope->with(new HandledStamp($mockPage, 'handler')));

        $mockDimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->expects($this->once())
            ->method('resolve')
            ->with($mockPage, [
                'locale' => 'en',
                'stage' => DimensionContentInterface::STAGE_DRAFT,
            ])
            ->willReturn($mockDimensionContent);

        $this->contentManager->expects($this->once())
            ->method('normalize')
            ->with($mockDimensionContent)
            ->willReturn(['title' => 'Resolved Title']);

        $result = $this->tool->createPage('example', 'en', 'default', 'Test', 'parent-uuid');

        $this->assertSame(['title' => 'Resolved Title'], $result['data']);
    }

    public function testCreatePageReturnsSuccessWithUuid(): void
    {
        $mockPage = $this->createMock(PageInterface::class);
        $mockPage->method('getUuid')->willReturn('new-page-uuid');

        $this->messageBus->method('dispatch')
            ->willReturnCallback(fn (Envelope $envelope) => $envelope->with(new HandledStamp($mockPage, 'handler')));

        $mockDimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->method('resolve')->willReturn($mockDimensionContent);
        $this->contentManager->method('normalize')->willReturn([]);

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
        $this->messageBus->method('dispatch')
            ->willThrowException(new \RuntimeException('Page creation failed'));

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
        $mockPage = $this->createMock(PageInterface::class);
        $mockPage->method('getUuid')->willReturn('uuid-1');

        $capturedData = null;
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function(Envelope $envelope) use ($mockPage, &$capturedData) {
                $message = $envelope->getMessage();
                $this->assertInstanceOf(CreatePageMessage::class, $message);
                $capturedData = (new \ReflectionProperty($message, 'data'))->getValue($message);

                return $envelope->with(new HandledStamp($mockPage, 'handler'));
            });

        $mockDimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->method('resolve')->willReturn($mockDimensionContent);
        $this->contentManager->method('normalize')->willReturn([]);

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

        $this->formMetadataProvider = $this->createMock(MetadataProviderInterface::class);
        $this->formMetadataProvider->method('getMetadata')
            ->willReturnCallback(fn (string $key) => 'page' === $key ? $typed : null);

        $this->tool = new PageCreateTool(
            $this->messageBus,
            $this->contentManager,
            new BlockDataValidator($this->formMetadataProvider, new MetadataLocaleResolver(new TokenStorage(), 'en')),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider),
            $this->adminLinkGenerator,
            $this->pageRepository,
            $this->permissionChecker,
        );

        $this->messageBus->expects($this->never())->method('dispatch');

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
        $this->messageBus->expects($this->never())->method('dispatch');

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
        $mockPage = $this->createMock(PageInterface::class);
        $mockPage->method('getUuid')->willReturn('uuid-1');

        $capturedData = null;
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function(Envelope $envelope) use ($mockPage, &$capturedData) {
                $message = $envelope->getMessage();
                $this->assertInstanceOf(CreatePageMessage::class, $message);
                $capturedData = (new \ReflectionProperty($message, 'data'))->getValue($message);

                return $envelope->with(new HandledStamp($mockPage, 'handler'));
            });

        $mockDimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->method('resolve')->willReturn($mockDimensionContent);
        $this->contentManager->method('normalize')->willReturn([]);

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

        $this->assertNotNull($capturedData);
        $this->assertSame('T', $capturedData['excerpt']['title']);
        $this->assertSame(['id' => 5], $capturedData['excerpt']['image']);
        $this->assertSame('S', $capturedData['seo']['title']);
        $this->assertTrue($capturedData['seoNoIndex']);
    }

    public function testCreatePageLoadsParentWithCorrectFilters(): void
    {
        $mockPage = $this->createMock(PageInterface::class);
        $mockPage->method('getUuid')->willReturn('uuid-1');

        $this->pageRepository = $this->createMock(PageRepositoryInterface::class);
        $parentPage = $this->createMock(PageInterface::class);
        $parentPage->method('getWebspaceKey')->willReturn('example');

        $this->pageRepository
            ->expects($this->once())
            ->method('getOneBy')
            ->with(
                [
                    'uuid' => 'parent-uuid',
                    'locale' => 'en',
                    'stage' => DimensionContentInterface::STAGE_DRAFT,
                ],
                [
                    PageRepositoryInterface::GROUP_SELECT_PAGE_ADMIN => true,
                ],
            )
            ->willReturn($parentPage);

        $this->tool = new PageCreateTool(
            $this->messageBus,
            $this->contentManager,
            new BlockDataValidator($this->formMetadataProvider, new MetadataLocaleResolver(new TokenStorage(), 'en')),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider),
            $this->adminLinkGenerator,
            $this->pageRepository,
            $this->permissionChecker,
        );

        $this->messageBus->method('dispatch')
            ->willReturnCallback(fn (Envelope $envelope) => $envelope->with(new HandledStamp($mockPage, 'handler')));

        $mockDimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->method('resolve')->willReturn($mockDimensionContent);
        $this->contentManager->method('normalize')->willReturn([]);

        $this->tool->createPage('example', 'en', 'default', 'Test', 'parent-uuid');
    }

    public function testCreatePageChecksObjectPermissionOnParent(): void
    {
        $mockPage = $this->createMock(PageInterface::class);
        $mockPage->method('getUuid')->willReturn('uuid-1');

        $this->messageBus->method('dispatch')
            ->willReturnCallback(fn (Envelope $envelope) => $envelope->with(new HandledStamp($mockPage, 'handler')));

        $mockDimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->method('resolve')->willReturn($mockDimensionContent);
        $this->contentManager->method('normalize')->willReturn([]);

        $checked = [];
        $this->permissionChecker
            ->expects($this->once())
            ->method('check')
            ->willReturnCallback(function(string $context, string|array $permissions, ?string $locale, ?string $type, mixed $id) use (&$checked): void {
                self::assertSame('sulu.webspaces.example', $context);
                self::assertSame('en', $locale);
                self::assertSame(Page::class, $type);
                self::assertSame('parent-uuid', $id);
                $checked = (array) $permissions;
            });

        $this->tool->createPage('example', 'en', 'default', 'Test', 'parent-uuid');

        self::assertSame([PermissionTypes::EDIT, PermissionTypes::ADD], $checked);
    }

    public function testCreatePageDeniesWhenParentInDifferentWebspace(): void
    {
        $parentPage = $this->createMock(PageInterface::class);
        $parentPage->method('getWebspaceKey')->willReturn('other-webspace');
        $this->pageRepository = $this->createMock(PageRepositoryInterface::class);
        $this->pageRepository->method('getOneBy')->willReturn($parentPage);

        $this->tool = new PageCreateTool(
            $this->messageBus,
            $this->contentManager,
            new BlockDataValidator($this->formMetadataProvider, new MetadataLocaleResolver(new TokenStorage(), 'en')),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider),
            $this->adminLinkGenerator,
            $this->pageRepository,
            $this->permissionChecker,
        );

        $this->messageBus->expects($this->never())->method('dispatch');
        $this->permissionChecker->expects($this->never())->method('check');

        $this->expectException(ToolCallException::class);

        $this->tool->createPage('example', 'en', 'default', 'Test', 'parent-uuid');
    }

    public function testCreatePageForcesTrustedLocaleAndTemplateOverMetadataClobbering(): void
    {
        // Regression guard: excerpt/seo fields literally named "locale"/"template" let
        // ContentMetadataMapper::place() clobber the trusted args that passed the EDIT preflight.
        $mockPage = $this->createMock(PageInterface::class);
        $mockPage->method('getUuid')->willReturn('uuid-1');

        $mapperMetadataProvider = $this->createMock(MetadataProviderInterface::class);
        $mapperMetadataProvider->method('getMetadata')->willReturnCallback(
            fn (string $key) => match ($key) {
                'content_excerpt_metadata' => $this->makeFormMeta(['locale']),
                'content_seo_metadata' => $this->makeFormMeta(['template']),
                default => $this->makeFormMeta([]),
            },
        );

        $tool = new PageCreateTool(
            $this->messageBus,
            $this->contentManager,
            new BlockDataValidator($this->formMetadataProvider, new MetadataLocaleResolver(new TokenStorage(), 'en')),
            $this->blockIdGenerator,
            new ContentMetadataMapper($mapperMetadataProvider),
            $this->adminLinkGenerator,
            $this->pageRepository,
            $this->permissionChecker,
        );

        $capturedData = null;
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function(Envelope $envelope) use ($mockPage, &$capturedData) {
                $message = $envelope->getMessage();
                $this->assertInstanceOf(CreatePageMessage::class, $message);
                $capturedData = (new \ReflectionProperty($message, 'data'))->getValue($message);

                return $envelope->with(new HandledStamp($mockPage, 'handler'));
            });

        $mockDimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->method('resolve')->willReturn($mockDimensionContent);
        $this->contentManager->method('normalize')->willReturn([]);

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

        $this->assertTrue($result['success']);
        $this->assertSame('en', $capturedData['locale']);
        $this->assertSame('default', $capturedData['template']);
    }

    public function testCreatePageDeniesWhenParentAclDenied(): void
    {
        $this->messageBus->expects($this->never())->method('dispatch');

        $this->permissionChecker
            ->method('check')
            ->willThrowException(new PermissionDeniedException('sulu.webspaces.example', PermissionTypes::EDIT, 'en'));

        $this->expectException(ToolCallException::class);

        $this->tool->createPage('example', 'en', 'default', 'Test', 'parent-uuid');
    }
}
