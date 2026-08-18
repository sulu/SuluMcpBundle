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
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Article\Application\Message\ModifyArticleMessage;
use Sulu\Article\Domain\Model\Article;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Mcp\Application\Content\BlockDataValidator;
use Sulu\Mcp\Application\Content\ContentTypeResolver;
use Sulu\Mcp\Application\Metadata\MetadataLocaleResolver;
use Sulu\Mcp\Application\Security\ContentSecurityContextResolver;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\Tests\Application\TestBundle\Metadata\TestGroupProvider;
use Sulu\Mcp\Tests\Unit\Fixture\ArrayMetadataProvider;
use Sulu\Mcp\Tests\Unit\Fixture\FakeToolPermissionChecker;
use Sulu\Mcp\Tests\Unit\Fixture\FixedBlockIdGenerator;
use Sulu\Mcp\UserInterface\Mcp\Tool\Block\BlockUpdateTool;
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
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

#[CoversClass(BlockUpdateTool::class)]
#[CoversClass(ContentTypeResolver::class)]
final class BlockUpdateToolTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<PageRepositoryInterface> */
    private ObjectProphecy $pageRepository;

    /** @var ObjectProphecy<ArticleRepositoryInterface> */
    private ObjectProphecy $articleRepository;

    /** @var ObjectProphecy<SnippetRepositoryInterface> */
    private ObjectProphecy $snippetRepository;

    /** @var ObjectProphecy<ContentManagerInterface> */
    private ObjectProphecy $contentManager;

    /** @var ObjectProphecy<MessageBusInterface> */
    private ObjectProphecy $messageBus;

    private FixedBlockIdGenerator $blockIdGenerator;
    private ArrayMetadataProvider $formMetadataProvider;
    private FakeToolPermissionChecker $permissionChecker;
    private ContentSecurityContextResolver $contentSecurityContextResolver;
    private BlockUpdateTool $tool;

    protected function setUp(): void
    {
        $this->pageRepository = $this->prophesize(PageRepositoryInterface::class);
        $this->articleRepository = $this->prophesize(ArticleRepositoryInterface::class);
        $this->snippetRepository = $this->prophesize(SnippetRepositoryInterface::class);
        $this->contentManager = $this->prophesize(ContentManagerInterface::class);
        $this->messageBus = $this->prophesize(MessageBusInterface::class);
        $this->blockIdGenerator = new FixedBlockIdGenerator('generated-id');
        $this->formMetadataProvider = new ArrayMetadataProvider();
        $this->formMetadataProvider->setDefault(new FormMetadata());
        $this->permissionChecker = FakeToolPermissionChecker::grantingAll();
        $groupProvider = new TestGroupProvider([]);
        $this->contentSecurityContextResolver = new ContentSecurityContextResolver(new ArticleSecurityContextResolver($groupProvider));
        $this->tool = new BlockUpdateTool(
            $this->messageBus->reveal(),
            new ContentTypeResolver($this->pageRepository->reveal(), $this->articleRepository->reveal(), $this->snippetRepository->reveal()),
            $this->contentManager->reveal(),
            $this->blockIdGenerator,
            new BlockDataValidator($this->formMetadataProvider, new MetadataLocaleResolver(new TokenStorage(), 'en')),
            $this->permissionChecker,
            $this->contentSecurityContextResolver,
        );
    }

    public function testUpdatePageBlockById(): void
    {
        $page = new Page();
        $page->setWebspaceKey('example');
        $this->pageRepository->getOneBy(Argument::cetera())->willReturn($page);

        $dimensionContent = new PageDimensionContent(new Page());
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([
            'template' => 'default',
            'title' => 'Test Page',
            'blocks' => [
                ['_id' => 'block-1', 'type' => 'text', 'title' => 'Old Title', 'description' => '<p>Old</p>'],
                ['_id' => 'block-2', 'type' => 'image', 'title' => 'Image', 'src' => '/img.jpg'],
            ],
        ]);

        $updatedPage = new Page('page-uuid');
        $updatedPage->setWebspaceKey('example');

        $capturedEnvelope = null;
        $this->messageBus->dispatch(Argument::that(function(Envelope $envelope) use (&$capturedEnvelope): bool {
            $capturedEnvelope = $envelope;

            return true;
        }), Argument::cetera())->shouldBeCalledOnce()->willReturn(new Envelope($updatedPage, [new HandledStamp($updatedPage, 'handler')]));

        $result = $this->tool->updateBlock('page', 'page-uuid', 'en', 'block-1', [
            'title' => 'New Title',
            'description' => '<p>New</p>',
        ]);

        $this->assertInstanceOf(ModifyPageMessage::class, $capturedEnvelope->getMessage());
        $this->assertTrue($result['success']);
        $this->assertSame('page-uuid', $result['uuid']);
        $this->assertSame('block-1', $result['blockId']);
        $this->assertSame('blocks', $result['blockProperty']);
        $this->assertSame([0], $result['blockPath']);
    }

    public function testUpdateArticleBlockById(): void
    {
        $article = new Article();
        $this->articleRepository->getOneBy(Argument::cetera())->willReturn($article);

        $dimensionContent = new PageDimensionContent(new Page());
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([
            'template' => 'blog',
            'title' => 'Test Article',
            'content' => [
                ['_id' => 'art-block-1', 'type' => 'text', 'body' => '<p>Hello</p>'],
            ],
        ]);

        $updatedArticle = new Article('article-uuid');

        $capturedEnvelope = null;
        $this->messageBus->dispatch(Argument::that(function(Envelope $envelope) use (&$capturedEnvelope): bool {
            $capturedEnvelope = $envelope;

            return true;
        }), Argument::cetera())->shouldBeCalledOnce()->willReturn(new Envelope($updatedArticle, [new HandledStamp($updatedArticle, 'handler')]));

        $result = $this->tool->updateBlock('article', 'article-uuid', 'en', 'art-block-1', [
            'body' => '<p>Updated</p>',
        ]);

        $this->assertInstanceOf(ModifyArticleMessage::class, $capturedEnvelope->getMessage());
        $this->assertTrue($result['success']);
        $this->assertSame('article-uuid', $result['uuid']);
        $this->assertSame('art-block-1', $result['blockId']);
        $this->assertSame('content', $result['blockProperty']);
    }

    public function testUpdateSnippetBlockById(): void
    {
        $snippet = new Snippet();
        $this->snippetRepository->getOneBy(Argument::cetera())->willReturn($snippet);

        $dimensionContent = new PageDimensionContent(new Page());
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([
            'template' => 'default',
            'title' => 'Test Snippet',
            'blocks' => [
                ['_id' => 'snip-block-1', 'type' => 'text', 'content' => '<p>Hello</p>'],
            ],
        ]);

        $updatedSnippet = new Snippet();

        $capturedEnvelope = null;
        $this->messageBus->dispatch(Argument::that(function(Envelope $envelope) use (&$capturedEnvelope): bool {
            $capturedEnvelope = $envelope;

            return true;
        }), Argument::cetera())->shouldBeCalledOnce()->willReturn(new Envelope($updatedSnippet, [new HandledStamp($updatedSnippet, 'handler')]));

        $result = $this->tool->updateBlock('snippet', 'snippet-uuid', 'en', 'snip-block-1', [
            'content' => '<p>Updated</p>',
        ]);

        $this->assertInstanceOf(ModifySnippetMessage::class, $capturedEnvelope->getMessage());
        $this->assertTrue($result['success']);
        $this->assertSame('snippet-uuid', $result['uuid']);
        $this->assertSame('snip-block-1', $result['blockId']);
        $this->assertSame('blocks', $result['blockProperty']);
    }

    public function testBlockNotFoundReturnsError(): void
    {
        $page = new Page();
        $page->setWebspaceKey('example');
        $this->pageRepository->getOneBy(Argument::cetera())->willReturn($page);

        $dimensionContent = new PageDimensionContent(new Page());
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([
            'template' => 'default',
            'title' => 'Test',
            'blocks' => [
                ['_id' => 'block-1', 'type' => 'text'],
            ],
        ]);

        $result = $this->tool->updateBlock('page', 'page-uuid', 'en', 'nonexistent', ['title' => 'New']);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('nonexistent', $result['error']);
        $this->assertArrayHasKey('hint', $result);
    }

    public function testEntityNotFoundReturnsError(): void
    {
        $this->pageRepository->getOneBy(Argument::cetera())
            ->willThrow(new \RuntimeException('Not found'));

        $result = $this->tool->updateBlock('page', 'missing-uuid', 'en', 'block-1', ['title' => 'New']);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('missing-uuid', $result['error']);
    }

    public function testInvalidTypeReturnsError(): void
    {
        $result = $this->tool->updateBlock('invalid', 'uuid', 'en', 'block-1', ['title' => 'New']);

        $this->assertArrayHasKey('error', $result);
    }

    public function testPartialMergePreservesExistingFields(): void
    {
        $page = new Page();
        $page->setWebspaceKey('example');
        $this->pageRepository->getOneBy(Argument::cetera())->willReturn($page);

        $dimensionContent = new PageDimensionContent(new Page());
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([
            'template' => 'default',
            'title' => 'Test Page',
            'blocks' => [
                ['_id' => 'block-1', 'type' => 'text', 'title' => 'Keep This', 'description' => '<p>Old</p>', 'settings' => ['color' => 'red']],
            ],
        ]);

        $updatedPage = new Page('page-uuid');
        $updatedPage->setWebspaceKey('example');

        $dispatchedBlocks = null;
        $this->messageBus->dispatch(Argument::that(function(Envelope $envelope) use (&$dispatchedBlocks): bool {
            /** @var ModifyPageMessage $message */
            $message = $envelope->getMessage();
            $data = (new \ReflectionProperty($message, 'data'))->getValue($message);
            $dispatchedBlocks = $data['blocks'];

            return true;
        }), Argument::cetera())->shouldBeCalledOnce()->willReturn(new Envelope($updatedPage, [new HandledStamp($updatedPage, 'handler')]));

        $this->tool->updateBlock('page', 'page-uuid', 'en', 'block-1', [
            'description' => '<p>New</p>',
        ]);

        $this->assertNotNull($dispatchedBlocks);
        $this->assertSame('Keep This', $dispatchedBlocks[0]['title']);
        $this->assertSame('<p>New</p>', $dispatchedBlocks[0]['description']);
        $this->assertSame(['color' => 'red'], $dispatchedBlocks[0]['settings']);
        $this->assertSame('text', $dispatchedBlocks[0]['type']);
    }

    public function testMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(BlockUpdateTool::class, 'updateBlock');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes);

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_block_update', $instance->name);
    }

    public function testBlockDataParameterIsAdvertisedAsObjectSchema(): void
    {
        $reflection = new \ReflectionMethod(BlockUpdateTool::class, 'updateBlock');
        $parameter = $reflection->getParameters()[4];
        $attributes = $parameter->getAttributes(Schema::class);

        $this->assertCount(1, $attributes);

        $schema = $attributes[0]->newInstance();
        $this->assertSame('object', $schema->type);
        $this->assertTrue($schema->additionalProperties);
    }

    public function testUpdateBlockThrowsToolCallExceptionWhenPermissionDenied(): void
    {
        $page = new Page();
        $page->setWebspaceKey('example');
        $this->pageRepository->getOneBy(Argument::cetera())->willReturn($page);

        $dimensionContent = new PageDimensionContent(new Page());
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);

        $this->permissionChecker->denyAll();

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(ToolCallException::class);

        $this->tool->updateBlock('page', 'page-uuid', 'en', 'block-1', ['title' => 'New']);
    }
}
