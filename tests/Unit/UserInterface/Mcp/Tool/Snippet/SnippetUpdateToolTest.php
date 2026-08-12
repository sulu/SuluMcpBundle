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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\Snippet;

use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Bundle\AdminBundle\Application\BlockIdGenerator\BlockIdGeneratorInterface;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataInterface;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Application\Content\BlockDataValidator;
use Sulu\Mcp\Application\Content\ContentTypeResolver;
use Sulu\Mcp\Infrastructure\Sulu\AdminLink\SnippetAdminLinkProvider;
use Sulu\Mcp\Infrastructure\Symfony\Routing\AdminLinkGenerator;
use Sulu\Mcp\Tests\Application\TestBundle\Admin\TestViewRegistry;
use Sulu\Mcp\UserInterface\Mcp\Tool\Snippet\SnippetUpdateTool;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Sulu\Snippet\Application\Message\ModifySnippetMessage;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\RouterInterface;

#[CoversClass(SnippetUpdateTool::class)]
final class SnippetUpdateToolTest extends TestCase
{
    private MessageBusInterface&MockObject $messageBus;
    private ContentManagerInterface&MockObject $contentManager;
    private PageRepositoryInterface&MockObject $pageRepository;
    private ArticleRepositoryInterface&MockObject $articleRepository;
    private SnippetRepositoryInterface&MockObject $snippetRepository;
    private MetadataProviderInterface&MockObject $formMetadataProvider;
    private BlockIdGeneratorInterface&MockObject $blockIdGenerator;
    private SnippetUpdateTool $tool;

    protected function setUp(): void
    {
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->contentManager = $this->createMock(ContentManagerInterface::class);
        $this->pageRepository = $this->createMock(PageRepositoryInterface::class);
        $this->articleRepository = $this->createMock(ArticleRepositoryInterface::class);
        $this->snippetRepository = $this->createMock(SnippetRepositoryInterface::class);
        $this->formMetadataProvider = $this->createMock(MetadataProviderInterface::class);
        // Default: provider returns a non-typed metadata so the validator skips strict checks.
        $this->formMetadataProvider->method('getMetadata')->willReturn($this->createMock(MetadataInterface::class));
        $this->blockIdGenerator = $this->createMock(BlockIdGeneratorInterface::class);
        $this->blockIdGenerator->method('generateId')->willReturn('gen-id');

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturn('https://example.com/admin/');
        $adminLinkGenerator = new AdminLinkGenerator($router, [new SnippetAdminLinkProvider(new TestViewRegistry())]);

        $this->tool = new SnippetUpdateTool(
            $this->messageBus,
            $this->contentManager,
            new ContentTypeResolver($this->pageRepository, $this->articleRepository, $this->snippetRepository),
            new BlockDataValidator($this->formMetadataProvider),
            $this->blockIdGenerator,
            $adminLinkGenerator,
        );
    }

    private function setUpReadModifyWrite(string $uuid, string $locale, array $currentData = []): SnippetInterface&MockObject
    {
        $existingSnippet = $this->createMock(SnippetInterface::class);
        $existingSnippet->method('getUuid')->willReturn($uuid);

        $this->snippetRepository->method('getOneBy')
            ->with(
                [
                    'uuid' => $uuid,
                    'locale' => $locale,
                    'stage' => DimensionContentInterface::STAGE_DRAFT,
                ],
                [SnippetRepositoryInterface::GROUP_SELECT_SNIPPET_ADMIN => true],
            )
            ->willReturn($existingSnippet);

        $currentDimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->method('resolve')->willReturn($currentDimensionContent);
        $this->contentManager->method('normalize')->willReturn($currentData);

        return $existingSnippet;
    }

    public function testUpdateSnippetReadsCurrentStateBeforeModifying(): void
    {
        $this->setUpReadModifyWrite('uuid-1', 'en', ['template' => 'default', 'title' => 'Old Title']);

        $mockUpdatedSnippet = $this->createMock(SnippetInterface::class);
        $mockUpdatedSnippet->method('getUuid')->willReturn('uuid-1');

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function(Envelope $envelope) use ($mockUpdatedSnippet) {
                $message = $envelope->getMessage();
                $this->assertInstanceOf(ModifySnippetMessage::class, $message);

                $stamps = $envelope->all();
                $this->assertArrayHasKey(EnableFlushStamp::class, $stamps);

                return $envelope->with(new HandledStamp($mockUpdatedSnippet, 'handler'));
            });

        $result = $this->tool->updateSnippet('uuid-1', 'en', 'New Title');

        $this->assertTrue($result['success']);
    }

    public function testUpdateSnippetUsesUuidAsIdentifier(): void
    {
        $this->setUpReadModifyWrite('uuid-1', 'en', ['template' => 'default', 'title' => 'Old Title']);

        $mockSnippet = $this->createMock(SnippetInterface::class);
        $mockSnippet->method('getUuid')->willReturn('uuid-1');

        $capturedMessage = null;
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function(Envelope $envelope) use ($mockSnippet, &$capturedMessage) {
                $capturedMessage = $envelope->getMessage();

                return $envelope->with(new HandledStamp($mockSnippet, 'handler'));
            });

        $this->tool->updateSnippet('uuid-1', 'en', 'New Title');

        $this->assertInstanceOf(ModifySnippetMessage::class, $capturedMessage);
        $this->assertSame(['uuid' => 'uuid-1'], $capturedMessage->getIdentifier());
    }

    public function testUpdateSnippetIncludesTemplateFromCurrentState(): void
    {
        $this->setUpReadModifyWrite('uuid-1', 'en', [
            'template' => 'default',
            'title' => 'Existing',
            'body' => '<p>Existing content</p>',
        ]);

        $mockSnippet = $this->createMock(SnippetInterface::class);
        $mockSnippet->method('getUuid')->willReturn('uuid-1');

        $capturedData = null;
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function(Envelope $envelope) use ($mockSnippet, &$capturedData) {
                $message = $envelope->getMessage();
                $this->assertInstanceOf(ModifySnippetMessage::class, $message);
                $capturedData = $message->getData();

                return $envelope->with(new HandledStamp($mockSnippet, 'handler'));
            });

        $this->tool->updateSnippet('uuid-1', 'en', null, null, ['body' => '<p>Updated</p>']);

        $this->assertSame('default', $capturedData['template']);
        $this->assertSame('<p>Updated</p>', $capturedData['body']);
    }

    public function testUpdateSnippetMergesContentWithExistingData(): void
    {
        $this->setUpReadModifyWrite('uuid-1', 'en', [
            'template' => 'default',
            'title' => 'Old Title',
            'body' => '<p>Old content</p>',
        ]);

        $mockSnippet = $this->createMock(SnippetInterface::class);
        $mockSnippet->method('getUuid')->willReturn('uuid-1');

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(fn (Envelope $envelope) => $envelope->with(new HandledStamp($mockSnippet, 'handler')));

        $result = $this->tool->updateSnippet(
            'uuid-1',
            'en',
            null,
            null,
            ['body' => '<p>New content</p>'],
        );

        $this->assertTrue($result['success']);
    }

    public function testUpdateSnippetReturnsSuccessWithUuid(): void
    {
        $this->setUpReadModifyWrite('uuid-1', 'en', ['template' => 'default', 'title' => 'Title']);

        $mockSnippet = $this->createMock(SnippetInterface::class);
        $mockSnippet->method('getUuid')->willReturn('uuid-1');

        $this->messageBus->method('dispatch')
            ->willReturnCallback(fn (Envelope $envelope) => $envelope->with(new HandledStamp($mockSnippet, 'handler')));

        $result = $this->tool->updateSnippet('uuid-1', 'en', 'Updated Title');

        $this->assertTrue($result['success']);
        $this->assertSame('uuid-1', $result['uuid']);
        $this->assertSame('https://example.com/admin/#/snippets/en/uuid-1', $result['admin_url']);
    }

    public function testUpdateSnippetReturnsErrorWhenNotFound(): void
    {
        $this->snippetRepository->method('getOneBy')
            ->willThrowException(new \RuntimeException('Snippet not found'));

        $result = $this->tool->updateSnippet('non-existent', 'en', 'Title');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('not found', \strtolower((string) $result['error']));
    }

    public function testUpdateSnippetReturnsErrorOnException(): void
    {
        $this->snippetRepository->method('getOneBy')
            ->willThrowException(new \RuntimeException('Snippet not found'));

        $result = $this->tool->updateSnippet('non-existent', 'en', 'Title');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Snippet not found', $result['error']);
    }

    public function testUpdateSnippetMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(SnippetUpdateTool::class, 'updateSnippet');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes, 'updateSnippet() must have exactly one #[McpTool] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_snippet_update', $instance->name);
    }

    public function testUpdateSnippetAssignsBlockIdsToNestedBlocks(): void
    {
        $this->setUpReadModifyWrite('uuid-1', 'en', ['template' => 'default', 'title' => 'Title']);

        $mockSnippet = $this->createMock(SnippetInterface::class);
        $mockSnippet->method('getUuid')->willReturn('uuid-1');

        $capturedData = null;
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function(Envelope $envelope) use ($mockSnippet, &$capturedData) {
                $message = $envelope->getMessage();
                $this->assertInstanceOf(ModifySnippetMessage::class, $message);
                $capturedData = $message->getData();

                return $envelope->with(new HandledStamp($mockSnippet, 'handler'));
            });

        $this->tool->updateSnippet(
            'uuid-1',
            'en',
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

    public function testUpdateSnippetRejectsInvalidBlocksBeforeWrite(): void
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
            ->willReturnCallback(fn (string $key) => 'snippet' === $key ? $typed : null);

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturn('https://example.com/admin/');
        $this->tool = new SnippetUpdateTool(
            $this->messageBus,
            $this->contentManager,
            new ContentTypeResolver($this->pageRepository, $this->articleRepository, $this->snippetRepository),
            new BlockDataValidator($this->formMetadataProvider),
            $this->blockIdGenerator,
            new AdminLinkGenerator($router, [new SnippetAdminLinkProvider(new TestViewRegistry())]),
        );

        $existingSnippet = $this->createMock(SnippetInterface::class);
        $existingSnippet->method('getUuid')->willReturn('uuid-1');
        $this->snippetRepository->method('getOneBy')->willReturn($existingSnippet);
        $currentDimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->method('resolve')->willReturn($currentDimensionContent);
        $this->contentManager->method('normalize')->willReturn(['template' => 'default', 'title' => 'Title']);

        $this->messageBus->expects($this->never())->method('dispatch');

        $result = $this->tool->updateSnippet(
            'uuid-1',
            'en',
            null,
            null,
            ['blocks' => [['type' => 'text', 'bogus' => 'x']]],
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('bogus', $result['error']);
    }

    public function testUpdateSnippetReturnsCompactedData(): void
    {
        $this->setUpReadModifyWrite('uuid-1', 'en', [
            'title' => 'Footer',
            'id' => 7,
            'blocks' => [['_id' => 'b1', 'type' => 'text', 'content' => '<p>HTML</p>']],
        ]);

        $mockSnippet = $this->createMock(SnippetInterface::class);
        $mockSnippet->method('getUuid')->willReturn('uuid-1');

        $this->messageBus->method('dispatch')
            ->willReturnCallback(fn (Envelope $envelope) => $envelope->with(new HandledStamp($mockSnippet, 'handler')));

        $result = $this->tool->updateSnippet('uuid-1', 'en', 'Footer');

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('id', $result['data']);
        $this->assertSame('Footer', $result['data']['title']);
        // Blocks are summarized to index/type, not full content
        $this->assertSame('text', $result['data']['blocks'][0]['type']);
        $this->assertArrayNotHasKey('content', $result['data']['blocks'][0]);
    }

    public function testUpdateSnippetForcesAuthorizedLocaleOverContentSmuggling(): void
    {
        $this->setUpReadModifyWrite('uuid-1', 'en', ['template' => 'default', 'title' => 'Old Title']);

        $mockSnippet = $this->createMock(SnippetInterface::class);
        $mockSnippet->method('getUuid')->willReturn('uuid-1');

        $capturedData = null;
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function(Envelope $envelope) use ($mockSnippet, &$capturedData) {
                $capturedData = $envelope->getMessage()->getData();

                return $envelope->with(new HandledStamp($mockSnippet, 'handler'));
            });

        // Caller is authorized for locale 'en' only; content.locale attempts to smuggle 'de'.
        $result = $this->tool->updateSnippet('uuid-1', 'en', null, null, ['locale' => 'de', 'body' => '<p>New</p>']);

        $this->assertTrue($result['success']);
        $this->assertSame('en', $capturedData['locale']);
    }

    public function testUpdateSnippetHasNoWebspaceOrUrlParameter(): void
    {
        $reflection = new \ReflectionMethod(SnippetUpdateTool::class, 'updateSnippet');
        $params = \array_map(fn ($p) => $p->getName(), $reflection->getParameters());

        $this->assertNotContains('webspace', $params);
        $this->assertNotContains('url', $params);
    }
}
