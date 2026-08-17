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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sulu\Article\Application\Message\CreateArticleMessage;
use Sulu\Article\Domain\Model\ArticleInterface;
use Sulu\Bundle\AdminBundle\Application\BlockIdGenerator\BlockIdGeneratorInterface;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormGroup;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\GroupProviderInterface;
use Sulu\Bundle\AdminBundle\Metadata\MetadataInterface;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Application\Article\ArticleGroupResolver;
use Sulu\Mcp\Application\Content\BlockDataValidator;
use Sulu\Mcp\Application\Content\ContentMetadataMapper;
use Sulu\Mcp\Application\Metadata\MetadataLocaleResolver;
use Sulu\Mcp\Infrastructure\Sulu\AdminLink\ArticleAdminLinkProvider;
use Sulu\Mcp\Infrastructure\Symfony\Routing\AdminLinkGenerator;
use Sulu\Mcp\Tests\Application\TestBundle\Admin\TestViewRegistry;
use Sulu\Mcp\UserInterface\Mcp\Tool\Article\ArticleCreateTool;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

#[CoversClass(ArticleCreateTool::class)]
final class ArticleCreateToolTest extends TestCase
{
    private MessageBusInterface&MockObject $messageBus;
    private ContentManagerInterface&MockObject $contentManager;
    private BlockIdGeneratorInterface&MockObject $blockIdGenerator;
    private MetadataProviderInterface&MockObject $formMetadataProvider;
    private MetadataProviderInterface&MockObject $mapperMetadataProvider;
    private ArticleGroupResolver $articleGroupResolver;
    private ArticleCreateTool $tool;

    protected function setUp(): void
    {
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->contentManager = $this->createMock(ContentManagerInterface::class);
        $this->blockIdGenerator = $this->createMock(BlockIdGeneratorInterface::class);
        $this->blockIdGenerator->method('generateId')->willReturn('gen-id');
        $this->formMetadataProvider = $this->createMock(MetadataProviderInterface::class);
        // Default: provider returns a non-typed metadata so the validator skips strict checks.
        $this->formMetadataProvider->method('getMetadata')->willReturn($this->createMock(MetadataInterface::class));
        $this->mapperMetadataProvider = $this->createMock(MetadataProviderInterface::class);
        // Provide Sulu's native SEO/excerpt field names so the mapper places them correctly.
        $this->mapperMetadataProvider->method('getMetadata')->willReturnCallback(
            fn (string $key) => match ($key) {
                'content_seo_metadata' => $this->makeFormMeta(['seo/title', 'seo/description', 'seo/keywords', 'seo/canonicalUrl', 'seoNoIndex', 'seoNoFollow', 'seoHideInSitemap']),
                'content_excerpt_metadata' => $this->makeFormMeta(['excerpt/title', 'excerpt/more', 'excerpt/description', 'excerpt/icon', 'excerpt/image']),
                'content_excerpt_taxonomies' => $this->makeFormMeta(['excerptCategories', 'excerptTags']),
                default => $this->makeFormMeta([]),
            },
        );
        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturn('https://example.com/admin/');
        $adminLinkGenerator = new AdminLinkGenerator($router, [new ArticleAdminLinkProvider(new TestViewRegistry())]);
        $groupProvider = $this->createMock(GroupProviderInterface::class);
        $groupProvider->method('getGroups')->willReturn([]);
        $this->articleGroupResolver = new ArticleGroupResolver($groupProvider, $this->contentManager);
        $this->tool = new ArticleCreateTool(
            $this->messageBus,
            $this->contentManager,
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
        $items = [];
        foreach ($names as $name) {
            $field = $this->createMock(FieldMetadata::class);
            $field->method('getName')->willReturn($name);
            $items[$name] = $field;
        }
        $form = $this->createMock(FormMetadata::class);
        $form->method('getFlatFieldMetadata')->willReturn($items);

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
        $mockArticle = $this->createMock(ArticleInterface::class);
        $mockArticle->method('getUuid')->willReturn('article-uuid-123');

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function(Envelope $envelope) use ($mockArticle) {
                $message = $envelope->getMessage();
                $this->assertInstanceOf(CreateArticleMessage::class, $message);

                $stamps = $envelope->all();
                $this->assertArrayHasKey(EnableFlushStamp::class, $stamps);

                return $envelope->with(new HandledStamp($mockArticle, 'handler'));
            });

        $mockDimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->method('resolve')->willReturn($mockDimensionContent);
        $this->contentManager->method('normalize')->willReturn(['title' => 'Test Article', 'url' => '/my-article']);

        $result = $this->tool->createArticle('en', 'blog', 'Test Article', null, ['url' => '/my-article']);

        $this->assertTrue($result['success']);
        $this->assertSame('article-uuid-123', $result['uuid']);
        $this->assertSame('https://example.com/admin/#/en/default/article-uuid-123', $result['admin_url']);
    }

    public function testCreateArticleUsesResolvedCustomGroupInAdminUrl(): void
    {
        $mockArticle = $this->createMock(ArticleInterface::class);
        $mockArticle->method('getUuid')->willReturn('custom-uuid');

        $this->messageBus->method('dispatch')
            ->willReturnCallback(fn (Envelope $envelope) => $envelope->with(new HandledStamp($mockArticle, 'handler')));

        $mockDimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->method('resolve')->willReturn($mockDimensionContent);
        $this->contentManager->method('normalize')->willReturn(['title' => 'Custom', 'url' => '/custom']);

        $groupProvider = $this->createMock(GroupProviderInterface::class);
        $groupProvider->method('getGroups')->willReturn([
            'blog-group' => new FormGroup('blog-group', 'Blog', ['blog']),
        ]);

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturn('https://example.com/admin/');

        $tool = new ArticleCreateTool(
            $this->messageBus,
            $this->contentManager,
            new BlockDataValidator($this->formMetadataProvider, new MetadataLocaleResolver(new TokenStorage(), 'en')),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider),
            new AdminLinkGenerator($router, [new ArticleAdminLinkProvider(new TestViewRegistry())]),
            new ArticleGroupResolver($groupProvider, $this->contentManager),
        );

        $result = $tool->createArticle('en', 'blog', 'Custom', null, ['url' => '/custom']);

        $this->assertTrue($result['success']);
        $this->assertSame('https://example.com/admin/#/en/blog-group/custom-uuid', $result['admin_url']);
    }

    public function testCreateArticleIncludesTypeInData(): void
    {
        $mockArticle = $this->createMock(ArticleInterface::class);
        $mockArticle->method('getUuid')->willReturn('uuid-1');

        $capturedMessage = null;
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function(Envelope $envelope) use ($mockArticle, &$capturedMessage) {
                $capturedMessage = $envelope->getMessage();

                return $envelope->with(new HandledStamp($mockArticle, 'handler'));
            });

        $mockDimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->method('resolve')->willReturn($mockDimensionContent);
        $this->contentManager->method('normalize')->willReturn(['url' => '/my-article']);

        $this->tool->createArticle('en', 'blog', 'Test', 'default', ['url' => '/my-article']);

        $this->assertInstanceOf(CreateArticleMessage::class, $capturedMessage);
    }

    public function testCreateArticleMergesContentIntoData(): void
    {
        $mockArticle = $this->createMock(ArticleInterface::class);
        $mockArticle->method('getUuid')->willReturn('uuid-1');

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(fn (Envelope $envelope) => $envelope->with(new HandledStamp($mockArticle, 'handler')));

        $mockDimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->method('resolve')->willReturn($mockDimensionContent);
        $this->contentManager->method('normalize')->willReturn(['url' => '/my-article']);

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
        $mockArticle = $this->createMock(ArticleInterface::class);
        $mockArticle->method('getUuid')->willReturn('uuid-1');

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function(Envelope $envelope) use ($mockArticle) {
                $message = $envelope->getMessage();
                $this->assertInstanceOf(CreateArticleMessage::class, $message);
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
                ], $message->getData());

                return $envelope->with(new HandledStamp($mockArticle, 'handler'));
            });

        $mockDimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->method('resolve')->willReturn($mockDimensionContent);
        $this->contentManager->method('normalize')->willReturn([
            'url' => [
                'page' => [
                    'path' => '/blog',
                    'uuid' => 'parent-page-uuid',
                ],
                'suffix' => 'my-article',
            ],
        ]);

        $result = $this->tool->createArticle('en', 'blog', 'Test', null, $this->pageContent());

        $this->assertTrue($result['success']);
    }

    public function testCreateArticleAcceptsSuluNativePageTreeRoute(): void
    {
        $mockArticle = $this->createMock(ArticleInterface::class);
        $mockArticle->method('getUuid')->willReturn('uuid-1');

        $route = [
            'page' => [
                'path' => '/blog',
                'uuid' => 'parent-page-uuid',
            ],
            'suffix' => '/my-article',
        ];

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function(Envelope $envelope) use ($mockArticle, $route) {
                $message = $envelope->getMessage();
                $this->assertInstanceOf(CreateArticleMessage::class, $message);
                $this->assertSame($route, $message->getData()['url']);
                $this->assertArrayNotHasKey('page', $message->getData());

                return $envelope->with(new HandledStamp($mockArticle, 'handler'));
            });

        $mockDimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->method('resolve')->willReturn($mockDimensionContent);
        $this->contentManager->method('normalize')->willReturn(['url' => $route]);

        $result = $this->tool->createArticle('en', 'blog', 'Test', null, ['url' => $route]);

        $this->assertTrue($result['success']);
    }

    public function testCreateArticleResolvesAndNormalizesResult(): void
    {
        $mockArticle = $this->createMock(ArticleInterface::class);
        $mockArticle->method('getUuid')->willReturn('uuid-1');

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(fn (Envelope $envelope) => $envelope->with(new HandledStamp($mockArticle, 'handler')));

        $mockDimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->expects($this->once())
            ->method('resolve')
            ->with($mockArticle, [
                'locale' => 'en',
                'stage' => DimensionContentInterface::STAGE_DRAFT,
            ])
            ->willReturn($mockDimensionContent);

        $this->contentManager->expects($this->once())
            ->method('normalize')
            ->with($mockDimensionContent)
            ->willReturn(['title' => 'Resolved Title', 'url' => '/my-article']);

        $result = $this->tool->createArticle('en', 'blog', 'Test', null, ['url' => '/my-article']);

        $this->assertSame(['title' => 'Resolved Title', 'url' => '/my-article'], $result['data']);
    }

    public function testCreateArticleReturnsErrorOnException(): void
    {
        $this->messageBus->method('dispatch')
            ->willThrowException(new \RuntimeException('Article creation failed'));

        $result = $this->tool->createArticle('en', 'blog', 'Test', null, ['url' => '/my-article']);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Article creation failed', $result['error']);
        $this->assertArrayHasKey('hint', $result);
        $this->assertArrayNotHasKey('success', $result);
    }

    public function testCreateArticleRejectsMissingRouting(): void
    {
        $this->messageBus->expects($this->never())->method('dispatch');

        $result = $this->tool->createArticle('en', 'blog', 'Test');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('routing data', $result['error']);
        $this->assertArrayNotHasKey('success', $result);
    }

    public function testCreateArticleRejectsBothRoutingForms(): void
    {
        $this->messageBus->expects($this->never())->method('dispatch');

        $result = $this->tool->createArticle('en', 'blog', 'Test', null, \array_merge(
            ['url' => '/my-article'],
            $this->pageContent(),
        ));

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('both', $result['error']);
    }

    public function testCreateArticleRejectsIncompletePageRouting(): void
    {
        $this->messageBus->expects($this->never())->method('dispatch');

        $result = $this->tool->createArticle('en', 'blog', 'Test', null, [
            'page' => ['path' => '/blog', 'uuid' => 'page-uuid'], // missing suffix
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('suffix', $result['error']);
    }

    public function testCreateArticleRejectsRelativeUrl(): void
    {
        $this->messageBus->expects($this->never())->method('dispatch');

        $result = $this->tool->createArticle('en', 'blog', 'Test', null, ['url' => 'my-article']);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('start with', $result['error']);
    }

    public function testCreateArticleReportsErrorWhenPostCreateUrlIsNull(): void
    {
        $mockArticle = $this->createMock(ArticleInterface::class);
        $mockArticle->method('getUuid')->willReturn('uuid-1');

        $this->messageBus->method('dispatch')
            ->willReturnCallback(fn (Envelope $envelope) => $envelope->with(new HandledStamp($mockArticle, 'handler')));

        $mockDimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->method('resolve')->willReturn($mockDimensionContent);
        $this->contentManager->method('normalize')->willReturn(['title' => 'X', 'url' => null]);

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
        $mockArticle = $this->createMock(ArticleInterface::class);
        $mockArticle->method('getUuid')->willReturn('uuid-1');

        $capturedData = null;
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function(Envelope $envelope) use ($mockArticle, &$capturedData) {
                $message = $envelope->getMessage();
                $this->assertInstanceOf(CreateArticleMessage::class, $message);
                $capturedData = $message->getData();

                return $envelope->with(new HandledStamp($mockArticle, 'handler'));
            });

        $mockDimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->method('resolve')->willReturn($mockDimensionContent);
        $this->contentManager->method('normalize')->willReturn(['url' => '/my-article']);

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

        $this->formMetadataProvider = $this->createMock(MetadataProviderInterface::class);
        $this->formMetadataProvider->method('getMetadata')
            ->willReturnCallback(fn (string $key) => 'article' === $key ? $typed : null);

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturn('https://example.com/admin/');
        $this->tool = new ArticleCreateTool(
            $this->messageBus,
            $this->contentManager,
            new BlockDataValidator($this->formMetadataProvider, new MetadataLocaleResolver(new TokenStorage(), 'en')),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider),
            new AdminLinkGenerator($router, [new ArticleAdminLinkProvider(new TestViewRegistry())]),
            $this->articleGroupResolver,
        );

        $this->messageBus->expects($this->never())->method('dispatch');

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
        $mockArticle = $this->createMock(ArticleInterface::class);
        $mockArticle->method('getUuid')->willReturn('uuid-1');

        $capturedMessage = null;
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function(Envelope $envelope) use ($mockArticle, &$capturedMessage) {
                $capturedMessage = $envelope->getMessage();

                return $envelope->with(new HandledStamp($mockArticle, 'handler'));
            });

        $mockDimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->method('resolve')->willReturn($mockDimensionContent);
        $this->contentManager->method('normalize')->willReturn(['url' => '/my-article']);

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
        $mockArticle = $this->createMock(ArticleInterface::class);
        $mockArticle->method('getUuid')->willReturn('uuid-1');

        $mapperMetadataProvider = $this->createMock(MetadataProviderInterface::class);
        $mapperMetadataProvider->method('getMetadata')->willReturnCallback(
            fn (string $key) => match ($key) {
                'content_excerpt_metadata' => $this->makeFormMeta(['template']),
                default => $this->makeFormMeta([]),
            },
        );

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturn('https://example.com/admin/');
        $tool = new ArticleCreateTool(
            $this->messageBus,
            $this->contentManager,
            new BlockDataValidator($this->formMetadataProvider, new MetadataLocaleResolver(new TokenStorage(), 'en')),
            $this->blockIdGenerator,
            new ContentMetadataMapper($mapperMetadataProvider),
            new AdminLinkGenerator($router, [new ArticleAdminLinkProvider(new TestViewRegistry())]),
            $this->articleGroupResolver,
        );

        $capturedData = null;
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function(Envelope $envelope) use ($mockArticle, &$capturedData) {
                $capturedData = $envelope->getMessage()->getData();

                return $envelope->with(new HandledStamp($mockArticle, 'handler'));
            });

        $mockDimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->method('resolve')->willReturn($mockDimensionContent);
        $this->contentManager->method('normalize')->willReturn(['url' => '/my-article']);

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
