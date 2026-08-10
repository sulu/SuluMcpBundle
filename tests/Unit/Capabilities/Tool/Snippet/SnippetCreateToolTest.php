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

namespace Sulu\Bundle\McpBundle\Tests\Unit\Capabilities\Tool\Snippet;

use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Application\BlockIdGenerator\BlockIdGeneratorInterface;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataInterface;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Bundle\McpBundle\AdminLink\AdminLinkGenerator;
use Sulu\Bundle\McpBundle\AdminLink\Provider\SnippetAdminLinkProvider;
use Sulu\Bundle\McpBundle\Capabilities\Tool\Block\BlockDataValidator;
use Sulu\Bundle\McpBundle\Capabilities\Tool\Snippet\SnippetCreateTool;
use Sulu\Bundle\McpBundle\Tests\Application\TestBundle\Admin\TestViewRegistry;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Snippet\Application\Message\CreateSnippetMessage;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\RouterInterface;

#[CoversClass(SnippetCreateTool::class)]
final class SnippetCreateToolTest extends TestCase
{
    private MessageBusInterface&MockObject $messageBus;
    private ContentManagerInterface&MockObject $contentManager;
    private MetadataProviderInterface&MockObject $formMetadataProvider;
    private BlockIdGeneratorInterface&MockObject $blockIdGenerator;
    private SnippetCreateTool $tool;

    protected function setUp(): void
    {
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->contentManager = $this->createMock(ContentManagerInterface::class);
        $this->formMetadataProvider = $this->createMock(MetadataProviderInterface::class);
        // Default: provider returns a non-typed metadata so the validator skips strict checks.
        $this->formMetadataProvider->method('getMetadata')->willReturn($this->createMock(MetadataInterface::class));
        $this->blockIdGenerator = $this->createMock(BlockIdGeneratorInterface::class);
        $this->blockIdGenerator->method('generateId')->willReturn('gen-id');

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturn('https://example.com/admin/');
        $adminLinkGenerator = new AdminLinkGenerator($router, [new SnippetAdminLinkProvider(new TestViewRegistry())]);

        $this->tool = new SnippetCreateTool(
            $this->messageBus,
            $this->contentManager,
            new BlockDataValidator($this->formMetadataProvider),
            $this->blockIdGenerator,
            $adminLinkGenerator,
        );
    }

    public function testCreateSnippetDispatchesCreateSnippetMessage(): void
    {
        $mockSnippet = $this->createMock(SnippetInterface::class);
        $mockSnippet->method('getUuid')->willReturn('snippet-uuid-123');

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (Envelope $envelope) use ($mockSnippet) {
                $message = $envelope->getMessage();
                $this->assertInstanceOf(CreateSnippetMessage::class, $message);

                $stamps = $envelope->all();
                $this->assertArrayHasKey(EnableFlushStamp::class, $stamps);

                return $envelope->with(new HandledStamp($mockSnippet, 'handler'));
            });

        $mockDimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->method('resolve')->willReturn($mockDimensionContent);
        $this->contentManager->method('normalize')->willReturn(['title' => 'Test Snippet']);

        $result = $this->tool->createSnippet('en', 'default', 'Test Snippet');

        $this->assertTrue($result['success']);
        $this->assertSame('snippet-uuid-123', $result['uuid']);
        $this->assertSame('https://example.com/admin/#/snippets/en/snippet-uuid-123', $result['admin_url']);
    }

    public function testCreateSnippetMergesLocaleTemplateAndTitleIntoData(): void
    {
        $mockSnippet = $this->createMock(SnippetInterface::class);
        $mockSnippet->method('getUuid')->willReturn('uuid-1');

        $capturedData = null;
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (Envelope $envelope) use ($mockSnippet, &$capturedData) {
                $message = $envelope->getMessage();
                $this->assertInstanceOf(CreateSnippetMessage::class, $message);
                $capturedData = $message->getData();

                return $envelope->with(new HandledStamp($mockSnippet, 'handler'));
            });

        $mockDimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->method('resolve')->willReturn($mockDimensionContent);
        $this->contentManager->method('normalize')->willReturn([]);

        $this->tool->createSnippet('en', 'default', 'My Snippet');

        $this->assertSame('en', $capturedData['locale']);
        $this->assertSame('default', $capturedData['template']);
        $this->assertSame('My Snippet', $capturedData['title']);
    }

    public function testCreateSnippetMergesContentIntoData(): void
    {
        $mockSnippet = $this->createMock(SnippetInterface::class);
        $mockSnippet->method('getUuid')->willReturn('uuid-1');

        $capturedData = null;
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (Envelope $envelope) use ($mockSnippet, &$capturedData) {
                $capturedData = $envelope->getMessage()->getData();

                return $envelope->with(new HandledStamp($mockSnippet, 'handler'));
            });

        $mockDimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->method('resolve')->willReturn($mockDimensionContent);
        $this->contentManager->method('normalize')->willReturn([]);

        $this->tool->createSnippet('en', 'default', 'Test', ['body' => '<p>Hello</p>']);

        $this->assertSame('<p>Hello</p>', $capturedData['body']);
    }

    public function testCreateSnippetResolvesAndNormalizesResult(): void
    {
        $mockSnippet = $this->createMock(SnippetInterface::class);
        $mockSnippet->method('getUuid')->willReturn('uuid-1');

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(fn (Envelope $envelope) => $envelope->with(new HandledStamp($mockSnippet, 'handler')));

        $mockDimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->expects($this->once())
            ->method('resolve')
            ->with($mockSnippet, [
                'locale' => 'en',
                'stage' => DimensionContentInterface::STAGE_DRAFT,
            ])
            ->willReturn($mockDimensionContent);

        $this->contentManager->expects($this->once())
            ->method('normalize')
            ->with($mockDimensionContent)
            ->willReturn(['title' => 'Resolved Title']);

        $result = $this->tool->createSnippet('en', 'default', 'Test');

        $this->assertSame(['title' => 'Resolved Title'], $result['data']);
    }

    public function testCreateSnippetReturnsSuccessWithUuid(): void
    {
        $mockSnippet = $this->createMock(SnippetInterface::class);
        $mockSnippet->method('getUuid')->willReturn('new-snippet-uuid');

        $this->messageBus->method('dispatch')
            ->willReturnCallback(fn (Envelope $envelope) => $envelope->with(new HandledStamp($mockSnippet, 'handler')));

        $mockDimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->method('resolve')->willReturn($mockDimensionContent);
        $this->contentManager->method('normalize')->willReturn([]);

        $result = $this->tool->createSnippet('en', 'default', 'Test');

        $this->assertTrue($result['success']);
        $this->assertSame('new-snippet-uuid', $result['uuid']);
        $this->assertArrayHasKey('data', $result);
    }

    public function testCreateSnippetReturnsErrorOnException(): void
    {
        $this->messageBus->method('dispatch')
            ->willThrowException(new \RuntimeException('Snippet creation failed'));

        $result = $this->tool->createSnippet('en', 'default', 'Test');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Snippet creation failed', $result['error']);
        $this->assertArrayNotHasKey('success', $result);
    }

    public function testCreateSnippetMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(SnippetCreateTool::class, 'createSnippet');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes, 'createSnippet() must have exactly one #[McpTool] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_snippet_create', $instance->name);
    }

    public function testCreateSnippetAssignsBlockIdsToNestedBlocks(): void
    {
        $mockSnippet = $this->createMock(SnippetInterface::class);
        $mockSnippet->method('getUuid')->willReturn('uuid-1');

        $capturedData = null;
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (Envelope $envelope) use ($mockSnippet, &$capturedData) {
                $message = $envelope->getMessage();
                $this->assertInstanceOf(CreateSnippetMessage::class, $message);
                $capturedData = $message->getData();

                return $envelope->with(new HandledStamp($mockSnippet, 'handler'));
            });

        $mockDimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->method('resolve')->willReturn($mockDimensionContent);
        $this->contentManager->method('normalize')->willReturn([]);

        $this->tool->createSnippet(
            'en',
            'default',
            'Test',
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

    public function testCreateSnippetRejectsInvalidBlocksBeforeWrite(): void
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
        $this->tool = new SnippetCreateTool(
            $this->messageBus,
            $this->contentManager,
            new BlockDataValidator($this->formMetadataProvider),
            $this->blockIdGenerator,
            new AdminLinkGenerator($router, [new SnippetAdminLinkProvider(new TestViewRegistry())]),
        );

        $this->messageBus->expects($this->never())->method('dispatch');

        $result = $this->tool->createSnippet(
            'en',
            'default',
            'Test',
            ['blocks' => [['type' => 'text', 'bogus' => 'x']]],
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('bogus', $result['error']);
    }

    public function testCreateSnippetHasNoWebspaceParameter(): void
    {
        $reflection = new \ReflectionMethod(SnippetCreateTool::class, 'createSnippet');
        $params = \array_map(fn ($p) => $p->getName(), $reflection->getParameters());

        $this->assertNotContains('webspace', $params);
    }
}
