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
use Sulu\Mcp\UserInterface\Mcp\Tool\Block\BlockReorderTool;
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

#[CoversClass(BlockReorderTool::class)]
#[CoversClass(ContentTypeResolver::class)]
final class BlockReorderToolTest extends TestCase
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
    private BlockReorderTool $tool;

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
        $this->tool = new BlockReorderTool(
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
    public function testReorderBlocksDispatchesCorrectMessagePerType(string $type, string $expectedMessageClass): void
    {
        $this->setupEntityWithBlocks($type, [
            ['type' => 'text', 'title' => 'First'],
            ['type' => 'image', 'src' => '/img.jpg'],
            ['type' => 'text', 'title' => 'Third'],
        ]);

        $dispatchedEnvelope = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use (&$dispatchedEnvelope) {
                /** @var Envelope $envelope */
                $envelope = $args[0];
                $dispatchedEnvelope = $envelope;

                return $envelope->with(new HandledStamp(null, 'handler'));
            });

        $result = $this->tool->reorderBlocks($type, 'test-uuid', 'en', 'blocks', [2, 0, 1]);

        $this->assertInstanceOf(Envelope::class, $dispatchedEnvelope);
        $this->assertInstanceOf($expectedMessageClass, $dispatchedEnvelope->getMessage());
        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['blockCount']);
        $this->assertSame([2, 0, 1], $result['order']);
        $this->assertSame('test-uuid', $result['uuid']);
    }

    public function testReorderBlocksReturnsErrorForUnsupportedType(): void
    {
        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->reorderBlocks('media', 'test-uuid', 'en', 'blocks', [0]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Unsupported content type', $result['error']);
    }

    public function testReorderBlocksReturnsErrorWhenEntityNotFound(): void
    {
        $this->pageRepository->getOneBy(Argument::cetera())->willThrow(new \RuntimeException('not found'));
        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->reorderBlocks('page', 'missing-uuid', 'en', 'blocks', [0]);

        $this->assertArrayHasKey('error', $result);
    }

    public function testReorderBlocksReturnsErrorForWrongLength(): void
    {
        $this->setupEntityWithBlocks('page', [
            ['type' => 'text', 'title' => 'First'],
            ['type' => 'text', 'title' => 'Second'],
            ['type' => 'text', 'title' => 'Third'],
        ]);

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->reorderBlocks('page', 'test-uuid', 'en', 'blocks', [0, 1]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('does not match block count', $result['error']);
    }

    public function testReorderBlocksReturnsErrorForDuplicateIndices(): void
    {
        $this->setupEntityWithBlocks('page', [
            ['type' => 'text', 'title' => 'First'],
            ['type' => 'text', 'title' => 'Second'],
            ['type' => 'text', 'title' => 'Third'],
        ]);

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->reorderBlocks('page', 'test-uuid', 'en', 'blocks', [0, 0, 1]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('exactly once', $result['error']);
    }

    public function testReorderBlocksReturnsErrorForOutOfRangeIndex(): void
    {
        $this->setupEntityWithBlocks('page', [
            ['type' => 'text', 'title' => 'First'],
            ['type' => 'text', 'title' => 'Second'],
            ['type' => 'text', 'title' => 'Third'],
        ]);

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->reorderBlocks('page', 'test-uuid', 'en', 'blocks', [0, 1, 5]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('exactly once', $result['error']);
    }

    public function testReorderBlocksAcceptsNumericStringIndices(): void
    {
        $this->setupEntityWithBlocks('page', [
            ['type' => 'text', 'title' => 'First'],
            ['type' => 'text', 'title' => 'Second'],
        ]);

        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) {
                /** @var Envelope $envelope */
                $envelope = $args[0];

                return $envelope->with(new HandledStamp(null, 'handler'));
            });

        $result = $this->tool->reorderBlocks('page', 'test-uuid', 'en', 'blocks', ['1', '0']);

        $this->assertTrue($result['success']);
        $this->assertSame([1, 0], $result['order']);
    }

    public function testReorderBlocksRejectsNonIntegerIndices(): void
    {
        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->reorderBlocks('page', 'test-uuid', 'en', 'blocks', ['first']);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('integer indices', $result['error']);
    }

    public function testReorderBlocksMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(BlockReorderTool::class, 'reorderBlocks');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes, 'reorderBlocks() must have exactly one #[McpTool] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_block_reorder', $instance->name);
    }

    public function testNewOrderParameterIsAdvertisedAsIntegerArraySchema(): void
    {
        $reflection = new \ReflectionMethod(BlockReorderTool::class, 'reorderBlocks');
        $parameter = $reflection->getParameters()[4];
        $attributes = $parameter->getAttributes(Schema::class);

        $this->assertCount(1, $attributes);

        $schema = $attributes[0]->newInstance();
        $this->assertSame('array', $schema->type);
        $this->assertSame(['type' => 'integer'], $schema->items);
    }

    public function testReorderByBlockIdsReordersByIdentity(): void
    {
        $this->setupEntityWithBlocks('page', [
            ['_id' => 'a', 'type' => 'text', 'title' => 'First'],
            ['_id' => 'b', 'type' => 'image', 'src' => '/img.jpg'],
            ['_id' => 'c', 'type' => 'text', 'title' => 'Third'],
        ]);

        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) {
                /** @var Envelope $envelope */
                $envelope = $args[0];

                return $envelope->with(new HandledStamp(null, 'handler'));
            });

        $result = $this->tool->reorderBlocks('page', 'test-uuid', 'en', 'blocks', null, ['c', 'a', 'b']);

        $this->assertTrue($result['success']);
        $this->assertSame([2, 0, 1], $result['order']);
    }

    public function testReorderByBlockIdsRejectsUnknownId(): void
    {
        $this->setupEntityWithBlocks('page', [
            ['_id' => 'a', 'type' => 'text', 'title' => 'First'],
            ['_id' => 'b', 'type' => 'text', 'title' => 'Second'],
        ]);

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->reorderBlocks('page', 'test-uuid', 'en', 'blocks', null, ['a', 'missing']);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('missing', $result['error']);
    }

    public function testReorderRequiresNewOrderOrBlockIds(): void
    {
        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->reorderBlocks('page', 'test-uuid', 'en', 'blocks');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('newOrder', $result['error']);
        $this->assertStringContainsString('blockIds', $result['error']);
    }

    public function testReorderRejectsBothNewOrderAndBlockIds(): void
    {
        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->reorderBlocks('page', 'test-uuid', 'en', 'blocks', [0, 1], ['a', 'b']);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('not both', $result['error']);
    }

    public function testReorderBlocksThrowsToolCallExceptionWhenPermissionDenied(): void
    {
        $this->setupEntityWithBlocks('page', [
            ['type' => 'text', 'title' => 'First'],
        ]);

        $this->permissionChecker->denyAll();

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(ToolCallException::class);

        $this->tool->reorderBlocks('page', 'test-uuid', 'en', 'blocks', [0]);
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private function setupEntityWithBlocks(string $type, array $blocks): void
    {
        $entity = $this->createEntity($type);

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

    private function createEntity(string $type): object
    {
        if ('article' === $type) {
            return new Article('test-uuid');
        }

        if ('snippet' === $type) {
            return new Snippet('test-uuid');
        }

        $page = new Page('test-uuid');
        $page->setWebspaceKey('example');

        return $page;
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

        $result = $this->tool->reorderBlocks('page', 'uuid-1', 'en', 'blocks', null, ['block-1']);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('has no "en" content yet', $result['error']);
        $this->assertStringContainsString('sulu_page_update', $result['hint']);
        $this->assertStringContainsString('de', $result['hint']);
    }
}
