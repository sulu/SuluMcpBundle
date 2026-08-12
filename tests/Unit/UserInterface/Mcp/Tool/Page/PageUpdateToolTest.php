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
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\Infrastructure\Sulu\AdminLink\PageAdminLinkProvider;
use Sulu\Mcp\Infrastructure\Symfony\Routing\AdminLinkGenerator;
use Sulu\Mcp\Tests\Application\TestBundle\Admin\TestViewRegistry;
use Sulu\Mcp\UserInterface\Mcp\Tool\Page\PageUpdateTool;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Page\Application\Message\ModifyPageMessage;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\RouterInterface;

#[CoversClass(PageUpdateTool::class)]
final class PageUpdateToolTest extends TestCase
{
    private MessageBusInterface&MockObject $messageBus;
    private ContentManagerInterface&MockObject $contentManager;
    private PageRepositoryInterface&MockObject $pageRepository;
    private MetadataProviderInterface&MockObject $formMetadataProvider;
    private MetadataProviderInterface&MockObject $mapperMetadataProvider;
    private BlockIdGeneratorInterface&MockObject $blockIdGenerator;
    private AdminLinkGenerator $adminLinkGenerator;
    private ToolPermissionCheckerInterface&MockObject $permissionChecker;
    private PageUpdateTool $tool;

    protected function setUp(): void
    {
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->contentManager = $this->createMock(ContentManagerInterface::class);
        $this->pageRepository = $this->createMock(PageRepositoryInterface::class);
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
        $this->permissionChecker = $this->createMock(ToolPermissionCheckerInterface::class);

        $this->tool = new PageUpdateTool(
            $this->messageBus,
            $this->contentManager,
            $this->pageRepository,
            new BlockDataValidator($this->formMetadataProvider),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider),
            $this->adminLinkGenerator,
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
        $form->method('getItems')->willReturn($items);

        return $form;
    }

    private function setUpReadModifyWrite(string $uuid, string $locale, array $currentData = []): PageInterface&MockObject
    {
        $existingPage = $this->createMock(PageInterface::class);
        $existingPage->method('getUuid')->willReturn($uuid);

        $this->pageRepository->method('getOneBy')
            ->with(
                [
                    'uuid' => $uuid,
                    'locale' => $locale,
                    'stage' => DimensionContentInterface::STAGE_DRAFT,
                ],
                [PageRepositoryInterface::GROUP_SELECT_PAGE_ADMIN => true],
            )
            ->willReturn($existingPage);

        $currentDimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->method('resolve')
            ->willReturn($currentDimensionContent);
        $this->contentManager->method('normalize')
            ->willReturn($currentData);

        return $existingPage;
    }

    public function testUpdatePageReadsCurrentStateBeforeModifying(): void
    {
        $this->setUpReadModifyWrite('uuid-1', 'en', ['template' => 'default', 'title' => 'Old Title']);

        $mockUpdatedPage = $this->createMock(PageInterface::class);
        $mockUpdatedPage->method('getUuid')->willReturn('uuid-1');

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function(Envelope $envelope) use ($mockUpdatedPage) {
                $message = $envelope->getMessage();
                $this->assertInstanceOf(ModifyPageMessage::class, $message);

                $stamps = $envelope->all();
                $this->assertArrayHasKey(EnableFlushStamp::class, $stamps);

                return $envelope->with(new HandledStamp($mockUpdatedPage, 'handler'));
            });

        $result = $this->tool->updatePage('uuid-1', 'en', 'New Title');

        $this->assertTrue($result['success']);
    }

    public function testUpdatePageIncludesTemplateFromCurrentState(): void
    {
        $this->setUpReadModifyWrite('uuid-1', 'en', [
            'template' => 'default',
            'title' => 'Existing',
            'article' => '<p>Existing content</p>',
        ]);

        $mockPage = $this->createMock(PageInterface::class);
        $mockPage->method('getUuid')->willReturn('uuid-1');

        $capturedData = null;
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function(Envelope $envelope) use ($mockPage, &$capturedData) {
                $message = $envelope->getMessage();
                $this->assertInstanceOf(ModifyPageMessage::class, $message);
                $capturedData = $message->getData();

                return $envelope->with(new HandledStamp($mockPage, 'handler'));
            });

        $this->tool->updatePage('uuid-1', 'en', null, null, null, ['article' => '<p>Updated</p>']);

        $this->assertSame('default', $capturedData['template']);
        $this->assertSame('<p>Updated</p>', $capturedData['article']);
    }

    public function testUpdatePageMergesContentWithExistingData(): void
    {
        $this->setUpReadModifyWrite('uuid-1', 'en', [
            'template' => 'default',
            'title' => 'Old Title',
            'article' => '<p>Old content</p>',
        ]);

        $mockPage = $this->createMock(PageInterface::class);
        $mockPage->method('getUuid')->willReturn('uuid-1');

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(fn (Envelope $envelope) => $envelope->with(new HandledStamp($mockPage, 'handler')));

        $result = $this->tool->updatePage(
            'uuid-1',
            'en',
            null,
            null,
            null,
            ['article' => '<p>New content</p>'],
        );

        $this->assertTrue($result['success']);
    }

    public function testUpdatePageReturnsSuccessWithUuid(): void
    {
        $this->setUpReadModifyWrite('uuid-1', 'en', ['template' => 'default', 'title' => 'Title']);

        $mockPage = $this->createMock(PageInterface::class);
        $mockPage->method('getUuid')->willReturn('uuid-1');

        $this->messageBus->method('dispatch')
            ->willReturnCallback(fn (Envelope $envelope) => $envelope->with(new HandledStamp($mockPage, 'handler')));

        $mockPage->method('getWebspaceKey')->willReturn('example');

        $result = $this->tool->updatePage('uuid-1', 'en', 'Updated Title');

        $this->assertTrue($result['success']);
        $this->assertSame('uuid-1', $result['uuid']);
        $this->assertSame(
            'https://example.com/admin/#/webspaces/example/pages/en/uuid-1',
            $result['admin_url'],
        );
    }

    public function testUpdatePageReturnsErrorOnException(): void
    {
        $this->pageRepository->method('getOneBy')
            ->willThrowException(new \RuntimeException('Page not found'));

        $result = $this->tool->updatePage('non-existent', 'en', 'Title');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Page not found', $result['error']);
    }

    public function testUpdatePageMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(PageUpdateTool::class, 'updatePage');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes, 'updatePage() must have exactly one #[McpTool] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_page_update', $instance->name);
    }

    public function testUpdatePageThrowsToolCallExceptionWhenPermissionDenied(): void
    {
        $this->setUpReadModifyWrite('uuid-1', 'en', ['template' => 'default']);

        $this->permissionChecker
            ->method('check')
            ->willThrowException(new PermissionDeniedException('sulu.webspaces.example', PermissionTypes::EDIT, 'en'));

        $this->messageBus->expects($this->never())->method('dispatch');

        $this->expectException(ToolCallException::class);

        $this->tool->updatePage('uuid-1', 'en', 'New Title');
    }

    public function testUpdatePagePassesConcretePageClassAsObjectType(): void
    {
        // Regression guard: Sulu ACLs key off the concrete Page class (getSecuredClass()),
        // not PageInterface -- using the interface silently falls back to the webspace grant.
        $existingPage = $this->createMock(PageInterface::class);
        $existingPage->method('getUuid')->willReturn('uuid-1');
        $existingPage->method('getWebspaceKey')->willReturn('example');

        $this->pageRepository->method('getOneBy')->willReturn($existingPage);

        $currentDimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->method('resolve')->willReturn($currentDimensionContent);
        $this->contentManager->method('normalize')->willReturn(['template' => 'default']);

        $this->permissionChecker
            ->expects($this->once())
            ->method('check')
            ->with(
                'sulu.webspaces.example',
                PermissionTypes::EDIT,
                'en',
                Page::class,
                'uuid-1',
            )
            ->willThrowException(new PermissionDeniedException('sulu.webspaces.example', PermissionTypes::EDIT, 'en'));

        $this->messageBus->expects($this->never())->method('dispatch');

        $this->expectException(ToolCallException::class);

        $this->tool->updatePage('uuid-1', 'en', 'New Title');
    }

    public function testNormalizeContentPassesThroughFlatMap(): void
    {
        $input = ['article' => '<p>Hello</p>', 'title' => 'Test'];
        $this->assertSame($input, PageUpdateTool::normalizeContent($input));
    }

    public function testNormalizeContentFlattensListOfObjects(): void
    {
        // AI sends: [{"article": "<p>Hello</p>"}]
        $input = [['article' => '<p>Hello</p>']];
        $this->assertSame(['article' => '<p>Hello</p>'], PageUpdateTool::normalizeContent($input));
    }

    public function testNormalizeContentHandlesNameValueFormat(): void
    {
        // AI sends: [{"name": "article", "value": "<p>Hello</p>"}]
        $input = [['name' => 'article', 'value' => '<p>Hello</p>']];
        $this->assertSame(['article' => '<p>Hello</p>'], PageUpdateTool::normalizeContent($input));
    }

    public function testNormalizeContentMergesMultipleListItems(): void
    {
        $input = [
            ['article' => '<p>Content</p>'],
            ['subtitle' => 'Sub'],
        ];
        $this->assertSame(
            ['article' => '<p>Content</p>', 'subtitle' => 'Sub'],
            PageUpdateTool::normalizeContent($input),
        );
    }

    public function testNormalizeContentHandlesEmptyArray(): void
    {
        $this->assertSame([], PageUpdateTool::normalizeContent([]));
    }

    public function testUpdatePageAssignsBlockIdsToNestedBlocks(): void
    {
        $this->setUpReadModifyWrite('uuid-1', 'en', ['template' => 'default', 'title' => 'Title']);

        $mockPage = $this->createMock(PageInterface::class);
        $mockPage->method('getUuid')->willReturn('uuid-1');

        $capturedData = null;
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function(Envelope $envelope) use ($mockPage, &$capturedData) {
                $message = $envelope->getMessage();
                $this->assertInstanceOf(ModifyPageMessage::class, $message);
                $capturedData = $message->getData();

                return $envelope->with(new HandledStamp($mockPage, 'handler'));
            });

        $this->tool->updatePage(
            'uuid-1',
            'en',
            null,
            null,
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

    public function testUpdatePageRejectsInvalidBlocksBeforeWrite(): void
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

        $this->tool = new PageUpdateTool(
            $this->messageBus,
            $this->contentManager,
            $this->pageRepository,
            new BlockDataValidator($this->formMetadataProvider),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider),
            $this->adminLinkGenerator,
            $this->permissionChecker,
        );

        // Set up the read side so we reach the content branch
        $existingPage = $this->createMock(PageInterface::class);
        $existingPage->method('getUuid')->willReturn('uuid-1');
        $this->pageRepository->method('getOneBy')->willReturn($existingPage);
        $currentDimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->method('resolve')->willReturn($currentDimensionContent);
        $this->contentManager->method('normalize')->willReturn(['template' => 'default', 'title' => 'Title']);

        $this->messageBus->expects($this->never())->method('dispatch');

        $result = $this->tool->updatePage(
            'uuid-1',
            'en',
            null,
            null,
            null,
            ['blocks' => [['type' => 'text', 'bogus' => 'x']]],
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('bogus', $result['error']);
    }

    public function testUpdatePageReturnsCompactedData(): void
    {
        $this->setUpReadModifyWrite('uuid-1', 'en', [
            'title' => 'New Title',
            'id' => 99,
            'blocks' => [['_id' => 'b1', 'type' => 'text', 'content' => '<p>HTML</p>']],
        ]);

        $mockPage = $this->createMock(PageInterface::class);
        $mockPage->method('getUuid')->willReturn('uuid-1');

        $this->messageBus->method('dispatch')
            ->willReturnCallback(fn (Envelope $envelope) => $envelope->with(new HandledStamp($mockPage, 'handler')));

        $result = $this->tool->updatePage('uuid-1', 'en', 'New Title');

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('id', $result['data']);
        $this->assertSame('New Title', $result['data']['title']);
        // Blocks are summarized to index/type, not full content
        $this->assertSame('text', $result['data']['blocks'][0]['type']);
        $this->assertArrayNotHasKey('content', $result['data']['blocks'][0]);
    }

    public function testUpdatePageForcesAuthorizedLocaleOverContentSmuggling(): void
    {
        $this->setUpReadModifyWrite('uuid-1', 'en', ['template' => 'default', 'title' => 'Old Title']);

        $mockPage = $this->createMock(PageInterface::class);
        $mockPage->method('getUuid')->willReturn('uuid-1');

        $capturedData = null;
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function(Envelope $envelope) use ($mockPage, &$capturedData) {
                $capturedData = $envelope->getMessage()->getData();

                return $envelope->with(new HandledStamp($mockPage, 'handler'));
            });

        // Caller is authorized for locale 'en' only; content.locale attempts to smuggle 'de'.
        $result = $this->tool->updatePage('uuid-1', 'en', null, null, null, ['locale' => 'de', 'article' => '<p>New</p>']);

        $this->assertTrue($result['success']);
        $this->assertSame('en', $capturedData['locale']);
    }

    public function testUpdatePageAppliesExcerptAndSeoToDispatchedMessage(): void
    {
        $mockPage = $this->createMock(PageInterface::class);
        $mockPage->method('getUuid')->willReturn('uuid-1');

        $existingPage = $this->createMock(PageInterface::class);
        $existingPage->method('getUuid')->willReturn('uuid-1');
        $this->pageRepository->method('getOneBy')->willReturn($existingPage);

        $currentDimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->method('resolve')->willReturn($currentDimensionContent);
        $this->contentManager->method('normalize')->willReturn(['template' => 'default']);

        $capturedData = null;
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function(Envelope $envelope) use ($mockPage, &$capturedData) {
                $message = $envelope->getMessage();
                $this->assertInstanceOf(ModifyPageMessage::class, $message);
                $capturedData = $message->getData();

                return $envelope->with(new HandledStamp($mockPage, 'handler'));
            });

        $this->tool->updatePage(
            'uuid-1',
            'en',
            null,
            null,
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
}
