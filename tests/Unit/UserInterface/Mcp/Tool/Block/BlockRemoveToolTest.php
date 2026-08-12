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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\Block;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sulu\Article\Application\Message\ModifyArticleMessage;
use Sulu\Article\Domain\Model\ArticleInterface;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Bundle\AdminBundle\Metadata\GroupProviderInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Application\Content\ContentTypeResolver;
use Sulu\Mcp\Application\Security\ContentSecurityContextResolver;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\UserInterface\Mcp\Tool\Block\BlockRemoveTool;
use Sulu\Page\Application\Message\ModifyPageMessage;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Sulu\Snippet\Application\Message\ModifySnippetMessage;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[CoversClass(BlockRemoveTool::class)]
#[CoversClass(ContentTypeResolver::class)]
final class BlockRemoveToolTest extends TestCase
{
    private MessageBusInterface&MockObject $messageBus;
    private PageRepositoryInterface&MockObject $pageRepository;
    private ArticleRepositoryInterface&MockObject $articleRepository;
    private SnippetRepositoryInterface&MockObject $snippetRepository;
    private ContentManagerInterface&MockObject $contentManager;
    private ToolPermissionCheckerInterface&MockObject $permissionChecker;
    private ContentSecurityContextResolver $contentSecurityContextResolver;
    private BlockRemoveTool $tool;

    protected function setUp(): void
    {
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->pageRepository = $this->createMock(PageRepositoryInterface::class);
        $this->articleRepository = $this->createMock(ArticleRepositoryInterface::class);
        $this->snippetRepository = $this->createMock(SnippetRepositoryInterface::class);
        $this->contentManager = $this->createMock(ContentManagerInterface::class);
        $this->permissionChecker = $this->createMock(ToolPermissionCheckerInterface::class);
        $groupProvider = $this->createMock(GroupProviderInterface::class);
        $groupProvider->method('getGroups')->willReturn([]);
        $this->contentSecurityContextResolver = new ContentSecurityContextResolver(new ArticleSecurityContextResolver($groupProvider));
        $this->tool = new BlockRemoveTool(
            $this->messageBus,
            new ContentTypeResolver($this->pageRepository, $this->articleRepository, $this->snippetRepository),
            $this->contentManager,
            $this->permissionChecker,
            $this->contentSecurityContextResolver,
        );
    }

    /**
     * @return iterable<string, array{string, class-string}>
     */
    public static function contentTypeProvider(): iterable
    {
        yield 'page' => ['page', ModifyPageMessage::class];
        yield 'article' => ['article', ModifyArticleMessage::class];
        yield 'snippet' => ['snippet', ModifySnippetMessage::class];
    }

    /**
     * @param class-string $expectedMessageClass
     */
    #[DataProvider('contentTypeProvider')]
    public function testRemoveBlockDispatchesCorrectMessagePerType(string $type, string $expectedMessageClass): void
    {
        $this->setupEntityWithBlocks($type, [
            ['type' => 'text', 'title' => 'First'],
            ['type' => 'image', 'src' => '/img.jpg'],
            ['type' => 'text', 'title' => 'Third'],
        ]);

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function(Envelope $envelope) use ($expectedMessageClass) {
                $this->assertInstanceOf($expectedMessageClass, $envelope->getMessage());

                return $envelope->with(new HandledStamp(null, 'handler'));
            });

        $result = $this->tool->removeBlock($type, 'test-uuid', 'en', 'blocks', blockIndex: 1);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['blockCount']);
        $this->assertSame(1, $result['removedIndex']);
        $this->assertSame('test-uuid', $result['uuid']);
    }

    public function testRemoveBlockReturnsErrorForUnsupportedType(): void
    {
        $this->messageBus->expects($this->never())->method('dispatch');

        $result = $this->tool->removeBlock('media', 'test-uuid', 'en', 'blocks', blockIndex: 0);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Unsupported content type', $result['error']);
    }

    public function testRemoveBlockReturnsErrorWhenEntityNotFound(): void
    {
        $this->pageRepository->method('getOneBy')->willThrowException(new \RuntimeException('not found'));
        $this->messageBus->expects($this->never())->method('dispatch');

        $result = $this->tool->removeBlock('page', 'missing-uuid', 'en', 'blocks', blockIndex: 0);

        $this->assertArrayHasKey('error', $result);
    }

    public function testRemoveBlockReturnsErrorForOutOfRangeIndex(): void
    {
        $this->setupEntityWithBlocks('page', [
            ['type' => 'text', 'title' => 'First'],
            ['type' => 'text', 'title' => 'Second'],
        ]);

        $this->messageBus->expects($this->never())->method('dispatch');

        $result = $this->tool->removeBlock('page', 'test-uuid', 'en', 'blocks', blockIndex: 5);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('out of range', $result['error']);
    }

    public function testRemoveBlockReturnsErrorForNegativeIndex(): void
    {
        $this->setupEntityWithBlocks('page', [
            ['type' => 'text', 'title' => 'First'],
        ]);

        $this->messageBus->expects($this->never())->method('dispatch');

        $result = $this->tool->removeBlock('page', 'test-uuid', 'en', 'blocks', blockIndex: -1);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('out of range', $result['error']);
    }

    public function testRemoveBlockMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(BlockRemoveTool::class, 'removeBlock');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes, 'removeBlock() must have exactly one #[McpTool] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_block_remove', $instance->name);
    }

    public function testRemoveByBlockIdRemovesCorrectBlock(): void
    {
        $this->setupEntityWithBlocks('page', [
            ['_id' => 'aaa', 'type' => 'text', 'title' => 'First'],
            ['_id' => 'bbb', 'type' => 'image', 'src' => '/img.jpg'],
            ['_id' => 'ccc', 'type' => 'text', 'title' => 'Third'],
        ]);

        $capturedData = null;
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function(Envelope $envelope) use (&$capturedData) {
                $this->assertInstanceOf(ModifyPageMessage::class, $envelope->getMessage());
                $capturedData = $envelope->getMessage()->getData();

                return $envelope->with(new HandledStamp(null, 'handler'));
            });

        $result = $this->tool->removeBlock('page', 'test-uuid', 'en', 'blocks', blockId: 'bbb');

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['removedIndex']);
        $this->assertSame(2, $result['blockCount']);
        $this->assertSame('test-uuid', $result['uuid']);

        $this->assertIsArray($capturedData);
        $remainingBlocks = $capturedData['blocks'];
        $this->assertCount(2, $remainingBlocks);
        $remainingIds = \array_column($remainingBlocks, '_id');
        $this->assertContains('aaa', $remainingIds);
        $this->assertContains('ccc', $remainingIds);
        $this->assertNotContains('bbb', $remainingIds);
    }

    public function testRemoveByBlockIdReturnsErrorForUnknownId(): void
    {
        $this->setupEntityWithBlocks('page', [
            ['_id' => 'aaa', 'type' => 'text', 'title' => 'First'],
            ['_id' => 'bbb', 'type' => 'text', 'title' => 'Second'],
        ]);

        $this->messageBus->expects($this->never())->method('dispatch');

        $result = $this->tool->removeBlock('page', 'test-uuid', 'en', 'blocks', blockId: 'missing');

        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('hint', $result);
        $this->assertStringContainsString('missing', $result['error']);
        $this->assertStringContainsString('sulu_block_list', $result['hint']);
    }

    public function testRemoveRequiresBlockIndexOrBlockId(): void
    {
        $this->messageBus->expects($this->never())->method('dispatch');

        $result = $this->tool->removeBlock('page', 'test-uuid', 'en', 'blocks');

        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('hint', $result);
        $this->assertStringContainsString('blockIndex', $result['error']);
        $this->assertStringContainsString('blockId', $result['error']);
    }

    public function testRemoveRejectsBothBlockIndexAndBlockId(): void
    {
        $this->messageBus->expects($this->never())->method('dispatch');

        $result = $this->tool->removeBlock('page', 'test-uuid', 'en', 'blocks', blockIndex: 0, blockId: 'aaa');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('not both', $result['error']);
    }

    public function testBlockIndexParameterHasSchemaAttribute(): void
    {
        $reflection = new \ReflectionMethod(BlockRemoveTool::class, 'removeBlock');
        $parameter = $reflection->getParameters()[4];
        $this->assertSame('blockIndex', $parameter->getName());
        $attributes = $parameter->getAttributes(Schema::class);

        $this->assertCount(1, $attributes);
        $schema = $attributes[0]->newInstance();
        $this->assertSame('integer', $schema->type);
    }

    public function testBlockIdParameterHasSchemaAttribute(): void
    {
        $reflection = new \ReflectionMethod(BlockRemoveTool::class, 'removeBlock');
        $parameter = $reflection->getParameters()[5];
        $this->assertSame('blockId', $parameter->getName());
        $attributes = $parameter->getAttributes(Schema::class);

        $this->assertCount(1, $attributes);
        $schema = $attributes[0]->newInstance();
        $this->assertSame('string', $schema->type);
    }

    public function testRemoveBlockThrowsToolCallExceptionWhenPermissionDenied(): void
    {
        $this->setupEntityWithBlocks('page', [
            ['type' => 'text', 'title' => 'First'],
        ]);

        $this->permissionChecker
            ->method('check')
            ->willThrowException(new PermissionDeniedException('sulu.webspaces.example', PermissionTypes::EDIT, 'en'));

        $this->messageBus->expects($this->never())->method('dispatch');

        $this->expectException(ToolCallException::class);

        $this->tool->removeBlock('page', 'test-uuid', 'en', 'blocks', blockIndex: 0);
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private function setupEntityWithBlocks(string $type, array $blocks): void
    {
        $entity = $this->createMock(match ($type) {
            'page' => PageInterface::class,
            'article' => ArticleInterface::class,
            'snippet' => SnippetInterface::class,
            default => PageInterface::class,
        });

        match ($type) {
            'article' => $this->articleRepository->method('getOneBy')->willReturn($entity),
            'snippet' => $this->snippetRepository->method('getOneBy')->willReturn($entity),
            default => $this->pageRepository->method('getOneBy')->willReturn($entity),
        };

        $dimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->method('resolve')->willReturn($dimensionContent);
        $this->contentManager->method('normalize')->willReturn([
            'template' => 'default',
            'title' => 'Test',
            'blocks' => $blocks,
        ]);
    }
}
