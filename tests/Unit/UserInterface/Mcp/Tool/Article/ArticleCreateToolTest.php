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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\Article;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Article\Application\Message\CreateArticleMessage;
use Sulu\Article\Domain\Model\Article;
use Sulu\Article\Domain\Model\ArticleDimensionContent;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormGroup;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Application\Article\ArticleGroupResolver;
use Sulu\Mcp\Application\Content\BlockDataValidator;
use Sulu\Mcp\Application\Content\ContentMetadataMapper;
use Sulu\Mcp\Application\Metadata\MetadataLocaleResolver;
use Sulu\Mcp\Infrastructure\Sulu\AdminLink\ArticleAdminLinkProvider;
use Sulu\Mcp\Infrastructure\Symfony\Routing\AdminLinkGenerator;
use Sulu\Mcp\Tests\Application\TestBundle\Admin\TestViewRegistry;
use Sulu\Mcp\Tests\Application\TestBundle\Metadata\TestGroupProvider;
use Sulu\Mcp\Tests\Unit\Fixture\ArrayMetadataProvider;
use Sulu\Mcp\Tests\Unit\Fixture\FixedBlockIdGenerator;
use Sulu\Mcp\UserInterface\Mcp\Tool\Article\ArticleCreateTool;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

#[CoversClass(ArticleCreateTool::class)]
final class ArticleCreateToolTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<MessageBusInterface> */
    private ObjectProphecy $messageBus;

    /** @var ObjectProphecy<ContentManagerInterface> */
    private ObjectProphecy $contentManager;

    private FixedBlockIdGenerator $blockIdGenerator;
    private ArrayMetadataProvider $formMetadataProvider;
    private ArrayMetadataProvider $mapperMetadataProvider;
    private ArticleGroupResolver $articleGroupResolver;
    private ArticleCreateTool $tool;

    protected function setUp(): void
    {
        $this->messageBus = $this->prophesize(MessageBusInterface::class);
        $this->contentManager = $this->prophesize(ContentManagerInterface::class);
        $this->blockIdGenerator = new FixedBlockIdGenerator('gen-id');
        $this->formMetadataProvider = new ArrayMetadataProvider();
        // Default: provider returns a non-typed metadata so the validator skips strict checks.
        $this->formMetadataProvider->setDefault(new FormMetadata());
        $this->mapperMetadataProvider = new ArrayMetadataProvider();
        // Provide Sulu's native SEO/excerpt field names so the mapper places them correctly.
        $this->mapperMetadataProvider->set('content_seo_metadata', $this->makeFormMeta(['seo/title', 'seo/description', 'seo/keywords', 'seo/canonicalUrl', 'seoNoIndex', 'seoNoFollow', 'seoHideInSitemap']));
        $this->mapperMetadataProvider->set('content_excerpt_metadata', $this->makeFormMeta(['excerpt/title', 'excerpt/more', 'excerpt/description', 'excerpt/icon', 'excerpt/image']));
        $this->mapperMetadataProvider->set('content_excerpt_taxonomies', $this->makeFormMeta(['excerptCategories', 'excerptTags']));
        $this->mapperMetadataProvider->setDefault($this->makeFormMeta([]));
        $router = $this->prophesize(RouterInterface::class);
        $router->generate(Argument::cetera())->willReturn('https://example.com/admin/');
        $adminLinkGenerator = new AdminLinkGenerator($router->reveal(), [new ArticleAdminLinkProvider(new TestViewRegistry())]);
        $groupProvider = new TestGroupProvider([]);
        $this->articleGroupResolver = new ArticleGroupResolver($groupProvider, $this->contentManager->reveal());
        $this->tool = new ArticleCreateTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            new BlockDataValidator($this->formMetadataProvider, new MetadataLocaleResolver(new TokenStorage(), 'en')),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider),
            $adminLinkGenerator,
            $this->articleGroupResolver,
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

    /** @return array<string, mixed> */
    private function pageContent(): array
    {
        return [
            'page' => [
                'path' => '/blog',
                'uuid' => 'parent-page-uuid',
                'suffix' => 'my-article',
            ],
        ];
    }

    public function testCreateArticleDispatchesCreateArticleMessage(): void
    {
        $mockArticle = new Article('article-uuid-123');

        $capturedEnvelope = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use ($mockArticle, &$capturedEnvelope) {
                $capturedEnvelope = $args[0];

                return $args[0]->with(new HandledStamp($mockArticle, 'handler'));
            });

        $mockDimensionContent = new ArticleDimensionContent(new Article());
        $this->contentManager->resolve(Argument::cetera())->willReturn($mockDimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn(['title' => 'Test Article', 'url' => '/my-article']);

        $result = $this->tool->createArticle('en', 'blog', 'Test Article', null, ['url' => '/my-article']);

        $this->assertInstanceOf(CreateArticleMessage::class, $capturedEnvelope->getMessage());
        $this->assertArrayHasKey(EnableFlushStamp::class, $capturedEnvelope->all());
        $this->assertTrue($result['success']);
        $this->assertSame('article-uuid-123', $result['uuid']);
        $this->assertSame('https://example.com/admin/#/en/default/article-uuid-123', $result['admin_url']);
    }

    public function testCreateArticleUsesResolvedCustomGroupInAdminUrl(): void
    {
        $mockArticle = new Article('custom-uuid');

        $this->messageBus->dispatch(Argument::cetera())
            ->will(fn (array $args) => $args[0]->with(new HandledStamp($mockArticle, 'handler')));

        $mockDimensionContent = new ArticleDimensionContent(new Article());
        $this->contentManager->resolve(Argument::cetera())->willReturn($mockDimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn(['title' => 'Custom', 'url' => '/custom']);

        $groupProvider = new TestGroupProvider([
            'blog-group' => new FormGroup('blog-group', 'Blog', ['blog']),
        ]);

        $router = $this->prophesize(RouterInterface::class);
        $router->generate(Argument::cetera())->willReturn('https://example.com/admin/');

        $tool = new ArticleCreateTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            new BlockDataValidator($this->formMetadataProvider, new MetadataLocaleResolver(new TokenStorage(), 'en')),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider),
            new AdminLinkGenerator($router->reveal(), [new ArticleAdminLinkProvider(new TestViewRegistry())]),
            new ArticleGroupResolver($groupProvider, $this->contentManager->reveal()),
        );

        $result = $tool->createArticle('en', 'blog', 'Custom', null, ['url' => '/custom']);

        $this->assertTrue($result['success']);
        $this->assertSame('https://example.com/admin/#/en/blog-group/custom-uuid', $result['admin_url']);
    }

    public function testCreateArticleIncludesTypeInData(): void
    {
        $mockArticle = new Article('uuid-1');

        $capturedMessage = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use ($mockArticle, &$capturedMessage) {
                $capturedMessage = $args[0]->getMessage();

                return $args[0]->with(new HandledStamp($mockArticle, 'handler'));
            });

        $mockDimensionContent = new ArticleDimensionContent(new Article());
        $this->contentManager->resolve(Argument::cetera())->willReturn($mockDimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn(['url' => '/my-article']);

        $this->tool->createArticle('en', 'blog', 'Test', 'default', ['url' => '/my-article']);

        $this->assertInstanceOf(CreateArticleMessage::class, $capturedMessage);
    }

    public function testCreateArticleMergesContentIntoData(): void
    {
        $mockArticle = new Article('uuid-1');

        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(fn (array $args) => $args[0]->with(new HandledStamp($mockArticle, 'handler')));

        $mockDimensionContent = new ArticleDimensionContent(new Article());
        $this->contentManager->resolve(Argument::cetera())->willReturn($mockDimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn(['url' => '/my-article']);

        $result = $this->tool->createArticle(
            'en',
            'blog',
            'Test',
            null,
            ['article' => '<p>Content</p>', 'url' => '/my-article'],
        );

        $this->assertTrue($result['success']);
    }

    public function testCreateArticleAcceptsPageTreeRoute(): void
    {
        $mockArticle = new Article('uuid-1');

        $capturedMessage = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use ($mockArticle, &$capturedMessage) {
                $capturedMessage = $args[0]->getMessage();

                return $args[0]->with(new HandledStamp($mockArticle, 'handler'));
            });

        $mockDimensionContent = new ArticleDimensionContent(new Article());
        $this->contentManager->resolve(Argument::cetera())->willReturn($mockDimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([
            'url' => [
                'page' => [
                    'path' => '/blog',
                    'uuid' => 'parent-page-uuid',
                ],
                'suffix' => 'my-article',
            ],
        ]);

        $result = $this->tool->createArticle('en', 'blog', 'Test', null, $this->pageContent());

        $this->assertInstanceOf(CreateArticleMessage::class, $capturedMessage);
        $this->assertSame([
            'url' => [
                'page' => [
                    'path' => '/blog',
                    'uuid' => 'parent-page-uuid',
                ],
                'suffix' => 'my-article',
            ],
            'locale' => 'en',
            'template' => 'blog',
            'title' => 'Test',
        ], $capturedMessage->getData());
        $this->assertTrue($result['success']);
    }

    public function testCreateArticleAcceptsSuluNativePageTreeRoute(): void
    {
        $mockArticle = new Article('uuid-1');

        $route = [
            'page' => [
                'path' => '/blog',
                'uuid' => 'parent-page-uuid',
            ],
            'suffix' => '/my-article',
        ];

        $capturedMessage = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use ($mockArticle, &$capturedMessage) {
                $capturedMessage = $args[0]->getMessage();

                return $args[0]->with(new HandledStamp($mockArticle, 'handler'));
            });

        $mockDimensionContent = new ArticleDimensionContent(new Article());
        $this->contentManager->resolve(Argument::cetera())->willReturn($mockDimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn(['url' => $route]);

        $result = $this->tool->createArticle('en', 'blog', 'Test', null, ['url' => $route]);

        $this->assertInstanceOf(CreateArticleMessage::class, $capturedMessage);
        $this->assertSame($route, $capturedMessage->getData()['url']);
        $this->assertArrayNotHasKey('page', $capturedMessage->getData());
        $this->assertTrue($result['success']);
    }

    public function testCreateArticleResolvesAndNormalizesResult(): void
    {
        $mockArticle = new Article('uuid-1');

        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(fn (array $args) => $args[0]->with(new HandledStamp($mockArticle, 'handler')));

        $mockDimensionContent = new ArticleDimensionContent(new Article());
        $this->contentManager->resolve($mockArticle, [
            'locale' => 'en',
            'stage' => DimensionContentInterface::STAGE_DRAFT,
        ])->shouldBeCalledOnce()->willReturn($mockDimensionContent);

        $this->contentManager->normalize($mockDimensionContent)
            ->shouldBeCalledOnce()
            ->willReturn(['title' => 'Resolved Title', 'url' => '/my-article']);

        $result = $this->tool->createArticle('en', 'blog', 'Test', null, ['url' => '/my-article']);

        $this->assertSame(['title' => 'Resolved Title', 'url' => '/my-article'], $result['data']);
    }

    public function testCreateArticleReturnsErrorOnException(): void
    {
        $this->messageBus->dispatch(Argument::cetera())
            ->willThrow(new \RuntimeException('Article creation failed'));

        $result = $this->tool->createArticle('en', 'blog', 'Test', null, ['url' => '/my-article']);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Article creation failed', $result['error']);
        $this->assertArrayHasKey('hint', $result);
        $this->assertArrayNotHasKey('success', $result);
    }

    public function testCreateArticleRejectsMissingRouting(): void
    {
        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->createArticle('en', 'blog', 'Test');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('routing data', $result['error']);
        $this->assertArrayNotHasKey('success', $result);
    }

    public function testCreateArticleRejectsBothRoutingForms(): void
    {
        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->createArticle('en', 'blog', 'Test', null, \array_merge(
            ['url' => '/my-article'],
            $this->pageContent(),
        ));

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('both', $result['error']);
    }

    public function testCreateArticleRejectsIncompletePageRouting(): void
    {
        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->createArticle('en', 'blog', 'Test', null, [
            'page' => ['path' => '/blog', 'uuid' => 'page-uuid'], // missing suffix
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('suffix', $result['error']);
    }

    public function testCreateArticleRejectsRelativeUrl(): void
    {
        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->createArticle('en', 'blog', 'Test', null, ['url' => 'my-article']);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('start with', $result['error']);
    }

    public function testCreateArticleReportsErrorWhenPostCreateUrlIsNull(): void
    {
        $mockArticle = new Article('uuid-1');

        $this->messageBus->dispatch(Argument::cetera())
            ->will(fn (array $args) => $args[0]->with(new HandledStamp($mockArticle, 'handler')));

        $mockDimensionContent = new ArticleDimensionContent(new Article());
        $this->contentManager->resolve(Argument::cetera())->willReturn($mockDimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn(['title' => 'X', 'url' => null]);

        $result = $this->tool->createArticle('en', 'blog', 'Test', null, ['url' => '/my-article']);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('url resolved to null', $result['error']);
        $this->assertSame('uuid-1', $result['uuid']);
    }

    public function testCreateArticleMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(ArticleCreateTool::class, 'createArticle');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes, 'createArticle() must have exactly one #[McpTool] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_article_create', $instance->name);
    }

    public function testTypeParameterHasSchemaAttribute(): void
    {
        $reflection = new \ReflectionMethod(ArticleCreateTool::class, 'createArticle');
        $parameter = $reflection->getParameters()[3];
        $this->assertSame('type', $parameter->getName());

        $attributes = $parameter->getAttributes(Schema::class);
        $this->assertCount(1, $attributes);
    }

    public function testCreateArticleAssignsBlockIdsToNestedBlocks(): void
    {
        $mockArticle = new Article('uuid-1');

        $capturedMessage = null;
        $capturedData = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use ($mockArticle, &$capturedMessage, &$capturedData) {
                $capturedMessage = $args[0]->getMessage();
                $capturedData = $capturedMessage->getData();

                return $args[0]->with(new HandledStamp($mockArticle, 'handler'));
            });

        $mockDimensionContent = new ArticleDimensionContent(new Article());
        $this->contentManager->resolve(Argument::cetera())->willReturn($mockDimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn(['url' => '/my-article']);

        $this->tool->createArticle('en', 'blog', 'Test', null, [
            'url' => '/my-article',
            'blocks' => [
                [
                    'type' => 'section',
                    'title' => 'My Section',
                    'blocks' => [
                        ['type' => 'text', 'title' => 'Nested Text'],
                    ],
                ],
            ],
        ]);

        $this->assertInstanceOf(CreateArticleMessage::class, $capturedMessage);
        $this->assertNotNull($capturedData);
        $blocks = $capturedData['blocks'];
        $this->assertNotEmpty($blocks[0]['_id'], 'top-level block must have a non-empty _id');
        $this->assertNotEmpty($blocks[0]['blocks'][0]['_id'], 'nested block must have a non-empty _id');
    }

    public function testCreateArticleRejectsInvalidBlocksBeforeWrite(): void
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
        $template->setKey('blog');
        $template->addItem($blocksField);

        $typed = new TypedFormMetadata();
        $typed->addForm('blog', $template);

        $this->formMetadataProvider = new ArrayMetadataProvider();
        $this->formMetadataProvider->set('article', $typed);

        $router = $this->prophesize(RouterInterface::class);
        $router->generate(Argument::cetera())->willReturn('https://example.com/admin/');
        $this->tool = new ArticleCreateTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            new BlockDataValidator($this->formMetadataProvider, new MetadataLocaleResolver(new TokenStorage(), 'en')),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider),
            new AdminLinkGenerator($router->reveal(), [new ArticleAdminLinkProvider(new TestViewRegistry())]),
            $this->articleGroupResolver,
        );

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->createArticle('en', 'blog', 'Test', null, [
            'url' => '/my-article',
            'blocks' => [
                ['type' => 'text', 'bogus' => 'invalid-key'],
            ],
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('bogus', $result['error']);
    }

    public function testCreateArticleSetsExcerptAndSeoInDispatchedData(): void
    {
        $mockArticle = new Article('uuid-1');

        $capturedMessage = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use ($mockArticle, &$capturedMessage) {
                $capturedMessage = $args[0]->getMessage();

                return $args[0]->with(new HandledStamp($mockArticle, 'handler'));
            });

        $mockDimensionContent = new ArticleDimensionContent(new Article());
        $this->contentManager->resolve(Argument::cetera())->willReturn($mockDimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn(['url' => '/my-article']);

        $this->tool->createArticle(
            'en',
            'blog',
            'Test',
            null,
            ['url' => '/my-article'],
            ['title' => 'T', 'image' => ['id' => 5]],
            ['title' => 'S', 'seoNoIndex' => true],
        );

        $this->assertInstanceOf(CreateArticleMessage::class, $capturedMessage);
        $data = $capturedMessage->getData();
        $this->assertSame('T', $data['excerpt']['title']);
        $this->assertSame(['id' => 5], $data['excerpt']['image']);
        $this->assertSame('S', $data['seo']['title']);
        $this->assertTrue($data['seoNoIndex']);
    }

    public function testCreateArticleForcesTrustedTemplateOverMetadataClobbering(): void
    {
        // Regression guard: a custom excerpt field literally named "template" makes
        // ContentMetadataMapper::place() write $data['template'] directly, clobbering the
        // trusted `template` arg that already passed the EDIT+ADD preflight.
        $mockArticle = new Article('uuid-1');

        $mapperMetadataProvider = new ArrayMetadataProvider();
        $mapperMetadataProvider->set('content_excerpt_metadata', $this->makeFormMeta(['template']));
        $mapperMetadataProvider->setDefault($this->makeFormMeta([]));

        $router = $this->prophesize(RouterInterface::class);
        $router->generate(Argument::cetera())->willReturn('https://example.com/admin/');
        $tool = new ArticleCreateTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            new BlockDataValidator($this->formMetadataProvider, new MetadataLocaleResolver(new TokenStorage(), 'en')),
            $this->blockIdGenerator,
            new ContentMetadataMapper($mapperMetadataProvider),
            new AdminLinkGenerator($router->reveal(), [new ArticleAdminLinkProvider(new TestViewRegistry())]),
            $this->articleGroupResolver,
        );

        $capturedData = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use ($mockArticle, &$capturedData) {
                $capturedData = $args[0]->getMessage()->getData();

                return $args[0]->with(new HandledStamp($mockArticle, 'handler'));
            });

        $mockDimensionContent = new ArticleDimensionContent(new Article());
        $this->contentManager->resolve(Argument::cetera())->willReturn($mockDimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn(['url' => '/my-article']);

        $result = $tool->createArticle(
            'en',
            'blog',
            'Test',
            null,
            ['url' => '/my-article'],
            ['template' => 'malicious_template'],
        );

        $this->assertTrue($result['success']);
        $this->assertSame('blog', $capturedData['template']);
    }
}
