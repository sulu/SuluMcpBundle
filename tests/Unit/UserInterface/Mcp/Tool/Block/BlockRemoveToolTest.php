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
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Article\Application\Message\ModifyArticleMessage;
use Sulu\Article\Domain\Model\Article;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Mcp\Application\Content\ContentTypeResolver;
use Sulu\Mcp\Application\Security\ContentSecurityContextResolver;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\Tests\Application\TestBundle\Metadata\TestGroupProvider;
use Sulu\Mcp\Tests\Unit\Fixture\FakeToolPermissionChecker;
use Sulu\Mcp\UserInterface\Mcp\Tool\Block\BlockRemoveTool;
use Sulu\Page\Application\Message\ModifyPageMessage;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Model\PageDimensionContent;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Sulu\Snippet\Application\Message\ModifySnippetMessage;
use Sulu\Snippet\Domain\Model\Snippet;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[CoversClass(BlockRemoveTool::class)]
#[CoversClass(ContentTypeResolver::class)]
final class BlockRemoveToolTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<MessageBusInterface> */
    private ObjectProphecy $messageBus;

    /** @var ObjectProphecy<PageRepositoryInterface> */
    private ObjectProphecy $pageRepository;

    /** @var ObjectProphecy<ArticleRepositoryInterface> */
    private ObjectProphecy $articleRepository;

    /** @var ObjectProphecy<SnippetRepositoryInterface> */
    private ObjectProphecy $snippetRepository;

    /** @var ObjectProphecy<ContentManagerInterface> */
    private ObjectProphecy $contentManager;

    private FakeToolPermissionChecker $permissionChecker;
    private ContentSecurityContextResolver $contentSecurityContextResolver;
    private BlockRemoveTool $tool;

    protected function setUp(): void
    {
        $this->messageBus = $this->prophesize(MessageBusInterface::class);
        $this->pageRepository = $this->prophesize(PageRepositoryInterface::class);
        $this->articleRepository = $this->prophesize(ArticleRepositoryInterface::class);
        $this->snippetRepository = $this->prophesize(SnippetRepositoryInterface::class);
        $this->contentManager = $this->prophesize(ContentManagerInterface::class);
        $this->permissionChecker = FakeToolPermissionChecker::grantingAll();
        $groupProvider = new TestGroupProvider([]);
        $this->contentSecurityContextResolver = new ContentSecurityContextResolver(new ArticleSecurityContextResolver($groupProvider), $this->contentManager->reveal());
        $this->tool = new BlockRemoveTool(
            $this->messageBus->reveal(),
            new ContentTypeResolver($this->pageRepository->reveal(), $this->articleRepository->reveal(), $this->snippetRepository->reveal()),
            $this->contentManager->reveal(),
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

        $dispatched = $this->expectMessageDispatch();

        $result = $this->tool->removeBlock($type, 'test-uuid', 'en', 'blocks', blockIndex: 1);

        $this->assertInstanceOf($expectedMessageClass, $dispatched->envelope->getMessage());
        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['blockCount']);
        $this->assertSame(1, $result['removedIndex']);
        $this->assertSame('test-uuid', $result['uuid']);
    }

    public function testRemoveBlockReturnsErrorForUnsupportedType(): void
    {
        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->removeBlock('media', 'test-uuid', 'en', 'blocks', blockIndex: 0);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Unsupported content type', $result['error']);
    }

    public function testRemoveBlockReturnsErrorWhenEntityNotFound(): void
    {
        $this->pageRepository->getOneBy(Argument::cetera())->willThrow(new \RuntimeException('not found'));
        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->removeBlock('page', 'missing-uuid', 'en', 'blocks', blockIndex: 0);

        $this->assertArrayHasKey('error', $result);
    }

    public function testRemoveBlockReturnsErrorForOutOfRangeIndex(): void
    {
        $this->setupEntityWithBlocks('page', [
            ['type' => 'text', 'title' => 'First'],
            ['type' => 'text', 'title' => 'Second'],
        ]);

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->removeBlock('page', 'test-uuid', 'en', 'blocks', blockIndex: 5);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('out of range', $result['error']);
    }

    public function testRemoveBlockReturnsErrorForNegativeIndex(): void
    {
        $this->setupEntityWithBlocks('page', [
            ['type' => 'text', 'title' => 'First'],
        ]);

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

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

        $dispatched = $this->expectMessageDispatch();

        $result = $this->tool->removeBlock('page', 'test-uuid', 'en', 'blocks', blockId: 'bbb');

        $this->assertInstanceOf(ModifyPageMessage::class, $dispatched->envelope->getMessage());

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['removedIndex']);
        $this->assertSame(2, $result['blockCount']);
        $this->assertSame('test-uuid', $result['uuid']);

        $capturedData = $dispatched->envelope->getMessage()->getData();
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

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->removeBlock('page', 'test-uuid', 'en', 'blocks', blockId: 'missing');

        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('hint', $result);
        $this->assertStringContainsString('missing', $result['error']);
        $this->assertStringContainsString('sulu_block_list', $result['hint']);
    }

    public function testRemoveRequiresBlockIndexOrBlockId(): void
    {
        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->removeBlock('page', 'test-uuid', 'en', 'blocks');

        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('hint', $result);
        $this->assertStringContainsString('blockIndex', $result['error']);
        $this->assertStringContainsString('blockId', $result['error']);
    }

    public function testRemoveRejectsBothBlockIndexAndBlockId(): void
    {
        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

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

        $this->permissionChecker->denyAll();

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(ToolCallException::class);

        $this->tool->removeBlock('page', 'test-uuid', 'en', 'blocks', blockIndex: 0);
    }

    /**
     * Stubs the message bus to accept exactly one dispatch and captures the
     * dispatched envelope for assertions after the action runs.
     */
    private function expectMessageDispatch(): \stdClass
    {
        $captured = new \stdClass();
        $this->messageBus->dispatch(Argument::type(Envelope::class), Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use ($captured) {
                $captured->envelope = $args[0];

                return $args[0]->with(new HandledStamp(null, 'handler'));
            });

        return $captured;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private function setupEntityWithBlocks(string $type, array $blocks): void
    {
        $entity = match ($type) {
            'article' => new Article('test-uuid'),
            'snippet' => new Snippet('test-uuid'),
            default => (static function(): Page {
                $page = new Page('test-uuid');
                $page->setWebspaceKey('example');

                return $page;
            })(),
        };

        match ($type) {
            'article' => $this->articleRepository->getOneBy(Argument::cetera())->willReturn($entity),
            'snippet' => $this->snippetRepository->getOneBy(Argument::cetera())->willReturn($entity),
            default => $this->pageRepository->getOneBy(Argument::cetera())->willReturn($entity),
        };

        $dimensionContent = new PageDimensionContent(new Page());
        $dimensionContent->setLocale('en');
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([
            'template' => 'default',
            'title' => 'Test',
            'blocks' => $blocks,
        ]);
    }

    public function testRejectsLocaleWithoutContentInsteadOfReportingNotFound(): void
    {
        $page = new Page('uuid-1');
        $page->setWebspaceKey('example');
        $this->pageRepository->getOneBy(Argument::cetera())->willReturn($page);

        // A ghost resolves to the unlocalized dimension, so its locale stays null.
        $ghostDimensionContent = new PageDimensionContent(new Page());
        $ghostDimensionContent->addAvailableLocale('de');
        $this->contentManager->resolve(Argument::cetera())->willReturn($ghostDimensionContent);

        $result = $this->tool->removeBlock('page', 'uuid-1', 'en', 'blocks', null, 'block-1');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('has no "en" content yet', $result['error']);
        $this->assertStringContainsString('sulu_page_update', $result['hint']);
        $this->assertStringContainsString('de', $result['hint']);
    }
}
