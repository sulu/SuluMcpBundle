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
use Sulu\Mcp\UserInterface\Mcp\Tool\Page\PageUpdateTool;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Page\Application\Message\ModifyPageMessage;
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
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

#[CoversClass(PageUpdateTool::class)]
final class PageUpdateToolTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<MessageBusInterface> */
    private ObjectProphecy $messageBus;

    /** @var ObjectProphecy<ContentManagerInterface> */
    private ObjectProphecy $contentManager;

    /** @var ObjectProphecy<PageRepositoryInterface> */
    private ObjectProphecy $pageRepository;

    private ArrayMetadataProvider $formMetadataProvider;
    private ArrayMetadataProvider $mapperMetadataProvider;
    private FixedBlockIdGenerator $blockIdGenerator;
    private AdminLinkGenerator $adminLinkGenerator;
    private FakeToolPermissionChecker $permissionChecker;
    private PageUpdateTool $tool;

    protected function setUp(): void
    {
        $this->messageBus = $this->prophesize(MessageBusInterface::class);
        $this->contentManager = $this->prophesize(ContentManagerInterface::class);
        $this->pageRepository = $this->prophesize(PageRepositoryInterface::class);
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
        $this->permissionChecker = FakeToolPermissionChecker::grantingAll();

        $this->tool = new PageUpdateTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            $this->pageRepository->reveal(),
            new BlockDataValidator($this->formMetadataProvider, new MetadataLocaleResolver(new TokenStorage(), 'en')),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider),
            $this->adminLinkGenerator,
            $this->permissionChecker,
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

    private function router(): RouterInterface
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

    private function setUpReadModifyWrite(string $uuid, string $locale, array $currentData = []): Page
    {
        $existingPage = new Page($uuid);
        $existingPage->setWebspaceKey('example');

        $this->pageRepository->getOneBy(
            [
                'uuid' => $uuid,
                'locale' => $locale,
                'stage' => DimensionContentInterface::STAGE_DRAFT,
                'loadGhost' => true,
            ],
            [PageRepositoryInterface::GROUP_SELECT_PAGE_ADMIN => true],
        )->willReturn($existingPage);

        $currentDimensionContent = new PageDimensionContent(new Page());
        $currentDimensionContent->setLocale($locale);
        $this->contentManager->resolve(Argument::cetera())
            ->willReturn($currentDimensionContent);
        $this->contentManager->normalize(Argument::cetera())
            ->willReturn($currentData);

        return $existingPage;
    }

    public function testUpdatePageReadsCurrentStateBeforeModifying(): void
    {
        $this->setUpReadModifyWrite('uuid-1', 'en', ['template' => 'default', 'title' => 'Old Title']);

        $mockUpdatedPage = new Page('uuid-1');
        $mockUpdatedPage->setWebspaceKey('example');

        $capturedEnvelope = null;
        $this->messageBus->dispatch(Argument::that(function(Envelope $envelope) use (&$capturedEnvelope): bool {
            $capturedEnvelope = $envelope;

            return true;
        }), Argument::cetera())->shouldBeCalledOnce()
            ->willReturn(new Envelope($mockUpdatedPage, [new HandledStamp($mockUpdatedPage, 'handler')]));

        $result = $this->tool->updatePage('uuid-1', 'en', 'New Title');

        $this->assertInstanceOf(ModifyPageMessage::class, $capturedEnvelope->getMessage());
        $stamps = $capturedEnvelope->all();
        $this->assertArrayHasKey(EnableFlushStamp::class, $stamps);

        $this->assertTrue($result['success']);
    }

    public function testUpdatePageIncludesTemplateFromCurrentState(): void
    {
        $this->setUpReadModifyWrite('uuid-1', 'en', [
            'template' => 'default',
            'title' => 'Existing',
            'article' => '<p>Existing content</p>',
        ]);

        $mockPage = new Page('uuid-1');
        $mockPage->setWebspaceKey('example');

        $capturedEnvelope = null;
        $this->messageBus->dispatch(Argument::that(function(Envelope $envelope) use (&$capturedEnvelope): bool {
            $capturedEnvelope = $envelope;

            return true;
        }), Argument::cetera())->shouldBeCalledOnce()
            ->willReturn(new Envelope($mockPage, [new HandledStamp($mockPage, 'handler')]));

        $this->tool->updatePage('uuid-1', 'en', null, null, null, ['article' => '<p>Updated</p>']);

        $message = $capturedEnvelope->getMessage();
        $this->assertInstanceOf(ModifyPageMessage::class, $message);
        $capturedData = $message->getData();

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

        $mockPage = new Page('uuid-1');
        $mockPage->setWebspaceKey('example');

        $this->messageBus->dispatch(Argument::cetera())
            ->willReturn(new Envelope($mockPage, [new HandledStamp($mockPage, 'handler')]));

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

        $mockPage = new Page('uuid-1');
        $mockPage->setWebspaceKey('example');

        $this->messageBus->dispatch(Argument::cetera())
            ->willReturn(new Envelope($mockPage, [new HandledStamp($mockPage, 'handler')]));

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
        $this->pageRepository->getOneBy(Argument::cetera())
            ->willThrow(new \RuntimeException('Page not found'));

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

        $this->permissionChecker->denyAll();

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(ToolCallException::class);

        $this->tool->updatePage('uuid-1', 'en', 'New Title');
    }

    public function testUpdatePagePassesConcretePageClassAsObjectType(): void
    {
        // Regression guard: Sulu ACLs key off the concrete Page class (getSecuredClass()),
        // not PageInterface -- using the interface silently falls back to the webspace grant.
        $existingPage = new Page('uuid-1');
        $existingPage->setWebspaceKey('example');

        $this->pageRepository->getOneBy(Argument::cetera())->willReturn($existingPage);

        $currentDimensionContent = new PageDimensionContent(new Page());
        $currentDimensionContent->setLocale('en');
        $this->contentManager->resolve(Argument::cetera())->willReturn($currentDimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn(['template' => 'default']);

        $this->permissionChecker->denyAll();

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        try {
            $this->tool->updatePage('uuid-1', 'en', 'New Title');
            self::fail('Expected ' . ToolCallException::class);
        } catch (ToolCallException) {
            self::assertSame([[
                'context' => 'sulu.webspaces.example',
                'permissions' => [PermissionTypes::EDIT],
                'locale' => 'en',
                'objectType' => Page::class,
                'objectId' => 'uuid-1',
            ]], $this->permissionChecker->calls());
        }
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

        $mockPage = new Page('uuid-1');
        $mockPage->setWebspaceKey('example');

        $capturedEnvelope = null;
        $this->messageBus->dispatch(Argument::that(function(Envelope $envelope) use (&$capturedEnvelope): bool {
            $capturedEnvelope = $envelope;

            return true;
        }), Argument::cetera())->shouldBeCalledOnce()
            ->willReturn(new Envelope($mockPage, [new HandledStamp($mockPage, 'handler')]));

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

        $message = $capturedEnvelope->getMessage();
        $this->assertInstanceOf(ModifyPageMessage::class, $message);
        $capturedData = $message->getData();

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

        $this->formMetadataProvider = new ArrayMetadataProvider();
        $this->formMetadataProvider->set('page', $typed);

        $this->tool = new PageUpdateTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            $this->pageRepository->reveal(),
            new BlockDataValidator($this->formMetadataProvider, new MetadataLocaleResolver(new TokenStorage(), 'en')),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider),
            $this->adminLinkGenerator,
            $this->permissionChecker,
        );

        // Set up the read side so we reach the content branch
        $existingPage = new Page('uuid-1');
        $existingPage->setWebspaceKey('example');
        $this->pageRepository->getOneBy(Argument::cetera())->willReturn($existingPage);
        $currentDimensionContent = new PageDimensionContent(new Page());
        $currentDimensionContent->setLocale('en');
        $this->contentManager->resolve(Argument::cetera())->willReturn($currentDimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn(['template' => 'default', 'title' => 'Title']);

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

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

        $mockPage = new Page('uuid-1');
        $mockPage->setWebspaceKey('example');

        $this->messageBus->dispatch(Argument::cetera())
            ->willReturn(new Envelope($mockPage, [new HandledStamp($mockPage, 'handler')]));

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

        $mockPage = new Page('uuid-1');
        $mockPage->setWebspaceKey('example');

        $capturedEnvelope = null;
        $this->messageBus->dispatch(Argument::that(function(Envelope $envelope) use (&$capturedEnvelope): bool {
            $capturedEnvelope = $envelope;

            return true;
        }), Argument::cetera())->shouldBeCalledOnce()
            ->willReturn(new Envelope($mockPage, [new HandledStamp($mockPage, 'handler')]));

        // Caller is authorized for locale 'en' only; content.locale attempts to smuggle 'de'.
        $result = $this->tool->updatePage('uuid-1', 'en', null, null, null, ['locale' => 'de', 'article' => '<p>New</p>']);

        $capturedData = $capturedEnvelope->getMessage()->getData();

        $this->assertTrue($result['success']);
        $this->assertSame('en', $capturedData['locale']);
    }

    public function testUpdatePageAppliesExcerptAndSeoToDispatchedMessage(): void
    {
        $mockPage = new Page('uuid-1');
        $mockPage->setWebspaceKey('example');

        $existingPage = new Page('uuid-1');
        $existingPage->setWebspaceKey('example');
        $this->pageRepository->getOneBy(Argument::cetera())->willReturn($existingPage);

        $currentDimensionContent = new PageDimensionContent(new Page());
        $currentDimensionContent->setLocale('en');
        $this->contentManager->resolve(Argument::cetera())->willReturn($currentDimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn(['template' => 'default']);

        $capturedEnvelope = null;
        $this->messageBus->dispatch(Argument::that(function(Envelope $envelope) use (&$capturedEnvelope): bool {
            $capturedEnvelope = $envelope;

            return true;
        }), Argument::cetera())->shouldBeCalledOnce()
            ->willReturn(new Envelope($mockPage, [new HandledStamp($mockPage, 'handler')]));

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

        $message = $capturedEnvelope->getMessage();
        $this->assertInstanceOf(ModifyPageMessage::class, $message);
        $capturedData = $message->getData();

        $this->assertNotNull($capturedData);
        $this->assertSame('T', $capturedData['excerpt']['title']);
        $this->assertSame(['id' => 5], $capturedData['excerpt']['image']);
        $this->assertSame('S', $capturedData['seo']['title']);
        $this->assertTrue($capturedData['seoNoIndex']);
    }

    /**
     * A ghost resolves to the unlocalized dimension, so its locale stays null while
     * availableLocales names the locales that do exist.
     */
    private function setUpGhostLocale(string $uuid, array $translatedLocales = ['de']): Page
    {
        $existingPage = new Page($uuid);
        $existingPage->setWebspaceKey('example');
        $this->pageRepository->getOneBy(Argument::cetera())->willReturn($existingPage);

        $ghostDimensionContent = new PageDimensionContent(new Page());
        foreach ($translatedLocales as $translatedLocale) {
            $ghostDimensionContent->addAvailableLocale($translatedLocale);
        }
        $this->contentManager->resolve(Argument::cetera())->willReturn($ghostDimensionContent);
        $this->contentManager->normalize(Argument::cetera())
            ->willReturn(['locale' => null, 'availableLocales' => $translatedLocales]);

        return $existingPage;
    }

    public function testUpdatePageCreatesMissingLocale(): void
    {
        $this->setUpGhostLocale('uuid-1');

        $mockPage = new Page('uuid-1');
        $mockPage->setWebspaceKey('example');

        $capturedEnvelope = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use ($mockPage, &$capturedEnvelope) {
                $capturedEnvelope = $args[0];

                return $args[0]->with(new HandledStamp($mockPage, 'handler'));
            });

        $result = $this->tool->updatePage('uuid-1', 'en', 'English Title', '/english', 'default', ['article' => '<p>EN</p>']);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['created_locale']);

        $message = $capturedEnvelope->getMessage();
        $this->assertInstanceOf(ModifyPageMessage::class, $message);
        $capturedData = $message->getData();

        $this->assertSame('en', $capturedData['locale']);
        $this->assertSame('English Title', $capturedData['title']);
        $this->assertSame('/english', $capturedData['url']);
        $this->assertSame('default', $capturedData['template']);
        // The unlocalized dimension's own fields must not travel into the new locale.
        $this->assertArrayNotHasKey('availableLocales', $capturedData);
    }

    public function testUpdatePageRejectsIncompleteNewLocaleWithoutDispatching(): void
    {
        $this->setUpGhostLocale('uuid-1', ['de', 'fr']);

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->updatePage('uuid-1', 'en', 'English Title');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('has no "en" content yet', $result['error']);
        $this->assertStringContainsString('title, url and template', $result['hint']);
        $this->assertStringContainsString('de, fr', $result['hint']);
    }

    public function testUpdatePageDoesNotFlagCreatedLocaleOnPlainUpdate(): void
    {
        $this->setUpReadModifyWrite('uuid-1', 'en', ['template' => 'default', 'title' => 'Old Title']);

        $mockPage = new Page('uuid-1');
        $mockPage->setWebspaceKey('example');
        $this->messageBus->dispatch(Argument::cetera())
            ->willReturn(new Envelope($mockPage, [new HandledStamp($mockPage, 'handler')]));

        $result = $this->tool->updatePage('uuid-1', 'en', 'New Title');

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('created_locale', $result);
    }
}
