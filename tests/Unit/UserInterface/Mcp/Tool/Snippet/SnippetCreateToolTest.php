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
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Application\Content\BlockDataValidator;
use Sulu\Mcp\Application\Metadata\MetadataLocaleResolver;
use Sulu\Mcp\Infrastructure\Sulu\AdminLink\SnippetAdminLinkProvider;
use Sulu\Mcp\Infrastructure\Symfony\Routing\AdminLinkGenerator;
use Sulu\Mcp\Tests\Application\TestBundle\Admin\TestViewRegistry;
use Sulu\Mcp\Tests\Unit\Fixture\ArrayMetadataProvider;
use Sulu\Mcp\Tests\Unit\Fixture\FixedBlockIdGenerator;
use Sulu\Mcp\UserInterface\Mcp\Tool\Snippet\SnippetCreateTool;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Snippet\Application\Message\CreateSnippetMessage;
use Sulu\Snippet\Domain\Model\Snippet;
use Sulu\Snippet\Domain\Model\SnippetDimensionContent;
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

#[CoversClass(SnippetCreateTool::class)]
final class SnippetCreateToolTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<MessageBusInterface> */
    private ObjectProphecy $messageBus;

    /** @var ObjectProphecy<ContentManagerInterface> */
    private ObjectProphecy $contentManager;

    private ArrayMetadataProvider $formMetadataProvider;
    private FixedBlockIdGenerator $blockIdGenerator;
    private SnippetCreateTool $tool;

    protected function setUp(): void
    {
        $this->messageBus = $this->prophesize(MessageBusInterface::class);
        $this->contentManager = $this->prophesize(ContentManagerInterface::class);
        $this->formMetadataProvider = new ArrayMetadataProvider();
        // Default: provider returns a non-typed metadata so the validator skips strict checks.
        $this->formMetadataProvider->setDefault(new FormMetadata());
        $this->blockIdGenerator = new FixedBlockIdGenerator('gen-id');

        $adminLinkGenerator = new AdminLinkGenerator($this->router(), [new SnippetAdminLinkProvider(new TestViewRegistry())]);

        $this->tool = new SnippetCreateTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            new BlockDataValidator($this->formMetadataProvider, new MetadataLocaleResolver(new TokenStorage(), 'en')),
            $this->blockIdGenerator,
            $adminLinkGenerator,
        );
    }

    public function testCreateSnippetDispatchesCreateSnippetMessage(): void
    {
        $snippet = new Snippet('snippet-uuid-123');

        $capturedEnvelope = null;
        $this->messageBus->dispatch(Argument::type(Envelope::class), Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use ($snippet, &$capturedEnvelope) {
                $capturedEnvelope = $args[0];

                return $args[0]->with(new HandledStamp($snippet, 'handler'));
            });

        $dimensionContent = new SnippetDimensionContent(new Snippet());
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn(['title' => 'Test Snippet']);

        $result = $this->tool->createSnippet('en', 'default', 'Test Snippet');

        $this->assertInstanceOf(CreateSnippetMessage::class, $capturedEnvelope->getMessage());
        $this->assertArrayHasKey(EnableFlushStamp::class, $capturedEnvelope->all());

        $this->assertTrue($result['success']);
        $this->assertSame('snippet-uuid-123', $result['uuid']);
        $this->assertSame('https://example.com/admin/#/snippets/en/snippet-uuid-123', $result['admin_url']);
    }

    public function testCreateSnippetMergesLocaleTemplateAndTitleIntoData(): void
    {
        $snippet = new Snippet('uuid-1');

        $capturedEnvelope = null;
        $this->messageBus->dispatch(Argument::type(Envelope::class), Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use ($snippet, &$capturedEnvelope) {
                $capturedEnvelope = $args[0];

                return $args[0]->with(new HandledStamp($snippet, 'handler'));
            });

        $dimensionContent = new SnippetDimensionContent(new Snippet());
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([]);

        $this->tool->createSnippet('en', 'default', 'My Snippet');

        $this->assertInstanceOf(CreateSnippetMessage::class, $capturedEnvelope->getMessage());
        $capturedData = $capturedEnvelope->getMessage()->getData();
        $this->assertSame('en', $capturedData['locale']);
        $this->assertSame('default', $capturedData['template']);
        $this->assertSame('My Snippet', $capturedData['title']);
    }

    public function testCreateSnippetMergesContentIntoData(): void
    {
        $snippet = new Snippet('uuid-1');

        $capturedData = null;
        $this->messageBus->dispatch(Argument::type(Envelope::class), Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use ($snippet, &$capturedData) {
                $capturedData = $args[0]->getMessage()->getData();

                return $args[0]->with(new HandledStamp($snippet, 'handler'));
            });

        $dimensionContent = new SnippetDimensionContent(new Snippet());
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([]);

        $this->tool->createSnippet('en', 'default', 'Test', ['body' => '<p>Hello</p>']);

        $this->assertSame('<p>Hello</p>', $capturedData['body']);
    }

    public function testCreateSnippetResolvesAndNormalizesResult(): void
    {
        $snippet = new Snippet('uuid-1');

        $this->messageBus->dispatch(Argument::type(Envelope::class), Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(fn (array $args) => $args[0]->with(new HandledStamp($snippet, 'handler')));

        $dimensionContent = new SnippetDimensionContent(new Snippet());
        $this->contentManager
            ->resolve($snippet, [
                'locale' => 'en',
                'stage' => DimensionContentInterface::STAGE_DRAFT,
            ])
            ->shouldBeCalledOnce()
            ->willReturn($dimensionContent);

        $this->contentManager
            ->normalize($dimensionContent)
            ->shouldBeCalledOnce()
            ->willReturn(['title' => 'Resolved Title']);

        $result = $this->tool->createSnippet('en', 'default', 'Test');

        $this->assertSame(['title' => 'Resolved Title'], $result['data']);
    }

    public function testCreateSnippetReturnsSuccessWithUuid(): void
    {
        $snippet = new Snippet('new-snippet-uuid');

        $this->messageBus->dispatch(Argument::type(Envelope::class), Argument::cetera())
            ->will(fn (array $args) => $args[0]->with(new HandledStamp($snippet, 'handler')));

        $dimensionContent = new SnippetDimensionContent(new Snippet());
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([]);

        $result = $this->tool->createSnippet('en', 'default', 'Test');

        $this->assertTrue($result['success']);
        $this->assertSame('new-snippet-uuid', $result['uuid']);
        $this->assertArrayHasKey('data', $result);
    }

    public function testCreateSnippetReturnsErrorOnException(): void
    {
        $this->messageBus->dispatch(Argument::cetera())
            ->willThrow(new \RuntimeException('Snippet creation failed'));

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
        $snippet = new Snippet('uuid-1');

        $capturedEnvelope = null;
        $this->messageBus->dispatch(Argument::type(Envelope::class), Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use ($snippet, &$capturedEnvelope) {
                $capturedEnvelope = $args[0];

                return $args[0]->with(new HandledStamp($snippet, 'handler'));
            });

        $dimensionContent = new SnippetDimensionContent(new Snippet());
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([]);

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

        $this->assertInstanceOf(CreateSnippetMessage::class, $capturedEnvelope->getMessage());
        $capturedData = $capturedEnvelope->getMessage()->getData();

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

        $this->formMetadataProvider = new ArrayMetadataProvider();
        $this->formMetadataProvider->set('snippet', $typed);

        $this->tool = new SnippetCreateTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            new BlockDataValidator($this->formMetadataProvider, new MetadataLocaleResolver(new TokenStorage(), 'en')),
            $this->blockIdGenerator,
            new AdminLinkGenerator($this->router(), [new SnippetAdminLinkProvider(new TestViewRegistry())]),
        );

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

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
}
