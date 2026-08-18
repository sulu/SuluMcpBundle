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
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Component\Security\Authorization\PermissionTypes;
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
use Sulu\Mcp\UserInterface\Mcp\Tool\Block\BlockAddTool;
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

#[CoversClass(BlockAddTool::class)]
#[CoversClass(ContentTypeResolver::class)]
final class BlockAddToolTest extends TestCase
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

    private FixedBlockIdGenerator $blockIdGenerator;
    private ArrayMetadataProvider $formMetadataProvider;
    private FakeToolPermissionChecker $permissionChecker;
    private ContentSecurityContextResolver $contentSecurityContextResolver;
    private BlockAddTool $tool;

    protected function setUp(): void
    {
        $this->messageBus = $this->prophesize(MessageBusInterface::class);
        $this->pageRepository = $this->prophesize(PageRepositoryInterface::class);
        $this->articleRepository = $this->prophesize(ArticleRepositoryInterface::class);
        $this->snippetRepository = $this->prophesize(SnippetRepositoryInterface::class);
        $this->contentManager = $this->prophesize(ContentManagerInterface::class);
        $this->blockIdGenerator = new FixedBlockIdGenerator('generated-id');
        $this->formMetadataProvider = new ArrayMetadataProvider();
        // Default: provider returns a non-typed metadata so the validator skips strict checks.
        $this->formMetadataProvider->setDefault(new FormMetadata());
        $this->permissionChecker = FakeToolPermissionChecker::grantingAll();
        $groupProvider = new TestGroupProvider([]);
        $this->contentSecurityContextResolver = new ContentSecurityContextResolver(new ArticleSecurityContextResolver($groupProvider));
        $this->tool = new BlockAddTool(
            $this->messageBus->reveal(),
            new ContentTypeResolver($this->pageRepository->reveal(), $this->articleRepository->reveal(), $this->snippetRepository->reveal()),
            $this->contentManager->reveal(),
            $this->blockIdGenerator,
            new BlockDataValidator($this->formMetadataProvider, new MetadataLocaleResolver(new TokenStorage(), 'en')),
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
    public function testAddBlockDispatchesCorrectMessagePerType(string $type, string $expectedMessageClass): void
    {
        $this->setupEntityWithBlocks($type, []);

        $dispatched = $this->expectMessageDispatch();

        $result = $this->tool->addBlock($type, 'test-uuid', 'en', 'text', 'blocks');

        $this->assertInstanceOf($expectedMessageClass, $dispatched->envelope->getMessage());
        $this->assertTrue($result['success']);
    }

    public function testAddBlockReturnsBlockIdInResult(): void
    {
        $this->setupEntityWithBlocks('page', []);

        $this->expectMessageDispatch();

        $result = $this->tool->addBlock('page', 'test-uuid', 'en', 'text', 'blocks');

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('blockId', $result);
        $this->assertSame('generated-id', $result['blockId']);
    }

    public function testAddBlockReturnsErrorForUnsupportedType(): void
    {
        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->addBlock('media', 'test-uuid', 'en', 'text', 'blocks');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Unsupported content type', $result['error']);
    }

    public function testAddBlockReturnsErrorWhenEntityNotFound(): void
    {
        $this->pageRepository->getOneBy(Argument::cetera())->willThrow(new \RuntimeException('not found'));
        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->addBlock('page', 'missing-uuid', 'en', 'text', 'blocks');

        $this->assertArrayHasKey('error', $result);
    }

    public function testAddBlockAppendsToEnd(): void
    {
        $existingBlocks = [
            ['type' => 'text', 'title' => 'First'],
            ['type' => 'text', 'title' => 'Second'],
        ];
        $this->setupEntityWithBlocks('page', $existingBlocks);

        $dispatched = $this->expectMessageDispatch();

        $result = $this->tool->addBlock('page', 'test-uuid', 'en', 'image', 'blocks', ['src' => '/img.jpg']);

        $this->assertInstanceOf(ModifyPageMessage::class, $dispatched->envelope->getMessage());
        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['blockCount']);
        $this->assertSame(2, $result['addedAt']);
    }

    public function testAddBlockInsertsAtPosition(): void
    {
        $existingBlocks = [
            ['type' => 'text', 'title' => 'First'],
            ['type' => 'text', 'title' => 'Second'],
        ];
        $this->setupEntityWithBlocks('page', $existingBlocks);

        $dispatched = $this->expectMessageDispatch();

        $result = $this->tool->addBlock('page', 'test-uuid', 'en', 'image', 'blocks', [], 0);

        $this->assertInstanceOf(ModifyPageMessage::class, $dispatched->envelope->getMessage());
        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['blockCount']);
        $this->assertSame(0, $result['addedAt']);
    }

    public function testAddBlockSetsBlockType(): void
    {
        $this->setupEntityWithBlocks('page', []);

        $this->expectMessageDispatch();

        $result = $this->tool->addBlock('page', 'test-uuid', 'en', 'hero_block', 'blocks');

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['blockCount']);
    }

    public function testAddBlockMergesBlockData(): void
    {
        $this->setupEntityWithBlocks('page', []);

        $this->expectMessageDispatch();

        $result = $this->tool->addBlock('page', 'test-uuid', 'en', 'text', 'blocks', ['title' => 'Hello', 'description' => 'World']);

        $this->assertTrue($result['success']);
    }

    public function testAddBlockPreservesLocaleInModifyMessage(): void
    {
        $this->setupEntityWithBlocks('page', []);

        $this->expectMessageDispatch();

        $result = $this->tool->addBlock('page', 'test-uuid', 'de', 'text', 'blocks');

        $this->assertTrue($result['success']);
    }

    public function testAddBlockReturnsSuccessWithBlockCount(): void
    {
        $existingBlocks = [
            ['type' => 'text', 'title' => 'First'],
        ];
        $this->setupEntityWithBlocks('page', $existingBlocks);

        $this->expectMessageDispatch();

        $result = $this->tool->addBlock('page', 'test-uuid', 'en', 'text', 'blocks');

        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('blockCount', $result);
        $this->assertArrayHasKey('addedAt', $result);
        $this->assertArrayHasKey('uuid', $result);
        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['blockCount']);
    }

    public function testAddBlockMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(BlockAddTool::class, 'addBlock');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes, 'addBlock() must have exactly one #[McpTool] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_block_add', $instance->name);
    }

    public function testBlockDataParameterHasObjectSchemaAttribute(): void
    {
        $reflection = new \ReflectionMethod(BlockAddTool::class, 'addBlock');
        // blockData is the 6th parameter (index 5): type, uuid, locale, blockType, blockProperty, blockData
        $parameter = $reflection->getParameters()[5];
        $this->assertSame('blockData', $parameter->getName());

        $attributes = $parameter->getAttributes(Schema::class);
        $this->assertCount(1, $attributes);

        $schema = $attributes[0]->newInstance();
        $this->assertSame('object', $schema->type);
    }

    public function testAddBlockRejectsUnknownKeysAgainstTemplate(): void
    {
        $this->setupEntityWithBlocks('page', []);

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
        $this->tool = new BlockAddTool(
            $this->messageBus->reveal(),
            new ContentTypeResolver($this->pageRepository->reveal(), $this->articleRepository->reveal(), $this->snippetRepository->reveal()),
            $this->contentManager->reveal(),
            $this->blockIdGenerator,
            new BlockDataValidator($this->formMetadataProvider, new MetadataLocaleResolver(new TokenStorage(), 'en')),
            $this->permissionChecker,
            $this->contentSecurityContextResolver,
        );

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->addBlock('page', 'test-uuid', 'en', 'text', 'blocks', ['unknown_key' => 'X']);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Unknown keys', $result['error']);
        $this->assertStringContainsString('unknown_key', $result['error']);
        $this->assertStringContainsString('title', $result['error']);
    }

    public function testAddBlockRejectsNameValuePattern(): void
    {
        $this->setupEntityWithBlocks('page', []);

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->addBlock('page', 'test-uuid', 'en', 'text', 'blocks', ['name' => 'title', 'value' => 'X']);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('internal {name, value} storage shape', $result['error']);
    }

    public function testAddBlockThrowsToolCallExceptionWhenPermissionDenied(): void
    {
        $this->setupEntityWithBlocks('page', []);

        $this->permissionChecker->denyAll();

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        try {
            $this->tool->addBlock('page', 'test-uuid', 'en', 'text', 'blocks');
            self::fail('Expected ' . ToolCallException::class);
        } catch (ToolCallException) {
            self::assertSame([[
                'context' => 'sulu.webspaces.example',
                'permissions' => [PermissionTypes::EDIT],
                'locale' => 'en',
                'objectType' => Page::class,
                'objectId' => 'test-uuid',
            ]], $this->permissionChecker->calls());
        }
    }

    public function testAddBlockPassesConcretePageClassAsObjectTypeForPageType(): void
    {
        // Regression guard: Sulu stores per-page ACLs under the concrete Page class
        // (getSecuredClass()), not PageInterface — the interface matches no ACL row and
        // silently falls back to the webspace-level grant.
        $this->setupEntityWithBlocks('page', []);

        $this->permissionChecker->denyAll();

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(ToolCallException::class);

        $this->tool->addBlock('page', 'test-uuid', 'en', 'text', 'blocks');
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
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([
            'template' => 'default',
            'title' => 'Test',
            'blocks' => $blocks,
        ]);
    }
}
