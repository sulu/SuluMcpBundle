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
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sulu\Article\Application\Message\ModifyArticleMessage;
use Sulu\Article\Domain\Model\ArticleInterface;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Bundle\AdminBundle\Application\BlockIdGenerator\BlockIdGeneratorInterface;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormGroup;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\GroupProviderInterface;
use Sulu\Bundle\AdminBundle\Metadata\MetadataInterface;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\TemplateInterface;
use Sulu\Mcp\Application\Article\ArticleGroupResolver;
use Sulu\Mcp\Application\Content\BlockDataValidator;
use Sulu\Mcp\Application\Content\ContentMetadataMapper;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\Infrastructure\Sulu\AdminLink\ArticleAdminLinkProvider;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\Infrastructure\Symfony\Routing\AdminLinkGenerator;
use Sulu\Mcp\Tests\Application\TestBundle\Admin\TestViewRegistry;
use Sulu\Mcp\UserInterface\Mcp\Tool\Article\ArticleUpdateTool;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\RouterInterface;

#[CoversClass(ArticleUpdateTool::class)]
final class ArticleUpdateToolTest extends TestCase
{
    private MessageBusInterface&MockObject $messageBus;
    private ContentManagerInterface&MockObject $contentManager;
    private ArticleRepositoryInterface&MockObject $articleRepository;
    private BlockIdGeneratorInterface&MockObject $blockIdGenerator;
    private MetadataProviderInterface&MockObject $formMetadataProvider;
    private MetadataProviderInterface&MockObject $mapperMetadataProvider;
    private ArticleGroupResolver $articleGroupResolver;
    private ToolPermissionCheckerInterface&MockObject $permissionChecker;
    private ArticleSecurityContextResolver $articleContextResolver;
    private ArticleUpdateTool $tool;

    protected function setUp(): void
    {
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->contentManager = $this->createMock(ContentManagerInterface::class);
        $this->articleRepository = $this->createMock(ArticleRepositoryInterface::class);
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
        $this->permissionChecker = $this->createMock(ToolPermissionCheckerInterface::class);
        $contextGroupProvider = $this->createMock(GroupProviderInterface::class);
        $contextGroupProvider->method('getGroups')->willReturn([]);
        $this->articleContextResolver = new ArticleSecurityContextResolver($contextGroupProvider);
        $this->tool = new ArticleUpdateTool(
            $this->messageBus,
            $this->contentManager,
            $this->articleRepository,
            new BlockDataValidator($this->formMetadataProvider),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider),
            $adminLinkGenerator,
            $this->articleGroupResolver,
            $this->permissionChecker,
            $this->articleContextResolver,
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
        $form->method('getItems')->willReturn($items);

        return $form;
    }

    public function testUpdateArticleReadsCurrentStateMergesAndDispatches(): void
    {
        $currentArticle = $this->createMock(ArticleInterface::class);
        $currentArticle->method('getUuid')->willReturn('uuid-1');
        $updatedArticle = $this->createMock(ArticleInterface::class);
        $updatedArticle->method('getUuid')->willReturn('uuid-1');

        $this->articleRepository->method('getOneBy')->willReturn($currentArticle);

        $dimensionContent = $this->createMockForIntersectionOfInterfaces([TemplateInterface::class, DimensionContentInterface::class]);
        $dimensionContent->method('getTemplateKey')->willReturn('blog');
        $this->contentManager->method('resolve')->willReturn($dimensionContent);
        $this->contentManager->method('normalize')->willReturn(['title' => 'Old Title', 'template' => 'blog']);

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (Envelope $envelope) use ($updatedArticle) {
                $stamps = $envelope->all();
                $this->assertArrayHasKey(EnableFlushStamp::class, $stamps);

                return $envelope->with(new HandledStamp($updatedArticle, 'handler'));
            });

        $result = $this->tool->updateArticle('uuid-1', 'en', 'New Title');

        $this->assertTrue($result['success']);
        $this->assertSame('uuid-1', $result['uuid']);
        $this->assertSame('https://example.com/admin/#/en/default/uuid-1', $result['admin_url']);
    }

    public function testUpdateArticleMergesContentOverCurrentData(): void
    {
        $currentArticle = $this->createMock(ArticleInterface::class);
        $updatedArticle = $this->createMock(ArticleInterface::class);
        $updatedArticle->method('getUuid')->willReturn('uuid-1');

        $this->articleRepository->method('getOneBy')->willReturn($currentArticle);

        $dimensionContent = $this->createMockForIntersectionOfInterfaces([TemplateInterface::class, DimensionContentInterface::class]);
        $dimensionContent->method('getTemplateKey')->willReturn('article');
        $this->contentManager->method('resolve')->willReturn($dimensionContent);
        $this->contentManager->method('normalize')->willReturn(['title' => 'Old', 'article' => '<p>Old</p>']);

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(fn (Envelope $envelope) => $envelope->with(new HandledStamp($updatedArticle, 'handler')));

        $result = $this->tool->updateArticle('uuid-1', 'en', null, null, ['article' => '<p>New</p>']);

        $this->assertTrue($result['success']);
    }

    public function testUpdateArticleReturnsErrorOnException(): void
    {
        $this->articleRepository->method('getOneBy')
            ->willThrowException(new \RuntimeException('Article not found'));

        $result = $this->tool->updateArticle('uuid-1', 'en', 'Title');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Article not found', $result['error']);
        $this->assertArrayHasKey('hint', $result);
        $this->assertArrayNotHasKey('success', $result);
    }

    public function testUpdateArticleMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(ArticleUpdateTool::class, 'updateArticle');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes, 'updateArticle() must have exactly one #[McpTool] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_article_update', $instance->name);
    }

    public function testUpdateArticleThrowsToolCallExceptionWhenPermissionDenied(): void
    {
        $currentArticle = $this->createMock(ArticleInterface::class);
        $this->articleRepository->method('getOneBy')->willReturn($currentArticle);

        $dimensionContent = $this->createMockForIntersectionOfInterfaces([
            TemplateInterface::class,
            DimensionContentInterface::class,
        ]);
        $dimensionContent->method('getTemplateKey')->willReturn('article');
        $this->contentManager->method('resolve')->willReturn($dimensionContent);

        $this->permissionChecker
            ->method('check')
            ->willThrowException(new PermissionDeniedException('sulu.article.articles', PermissionTypes::EDIT, 'en'));

        $this->messageBus->expects($this->never())->method('dispatch');

        $this->expectException(ToolCallException::class);

        $this->tool->updateArticle('uuid-1', 'en', 'New Title');
    }

    public function testUpdateArticleDeniesTemplateChangeIntoUnpermittedGroup(): void
    {
        $groupProvider = $this->createMock(GroupProviderInterface::class);
        $groupProvider->method('getGroups')->willReturn([
            (new FormGroup('default', 'Default'))->withTemplate('article'),
            (new FormGroup('blog', 'Blog'))->withTemplate('blog_article'),
        ]);
        $contextResolver = new ArticleSecurityContextResolver($groupProvider);

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturn('https://example.com/admin/');
        $tool = new ArticleUpdateTool(
            $this->messageBus,
            $this->contentManager,
            $this->articleRepository,
            new BlockDataValidator($this->formMetadataProvider),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider),
            new AdminLinkGenerator($router, [new ArticleAdminLinkProvider(new TestViewRegistry())]),
            $this->articleGroupResolver,
            $this->permissionChecker,
            $contextResolver,
        );

        $currentArticle = $this->createMock(ArticleInterface::class);
        $this->articleRepository->method('getOneBy')->willReturn($currentArticle);

        $dimensionContent = $this->createMockForIntersectionOfInterfaces([
            TemplateInterface::class,
            DimensionContentInterface::class,
        ]);
        $dimensionContent->method('getTemplateKey')->willReturn('article');
        $this->contentManager->method('resolve')->willReturn($dimensionContent);

        // User has EDIT on the base group (source context) but not on the blog group (target context).
        $this->permissionChecker->method('check')->willReturnCallback(
            function (string $context, string $permission, ?string $locale = null): void {
                if ('sulu.article.articles_blog' === $context) {
                    throw new PermissionDeniedException($context, $permission, $locale);
                }
            },
        );

        $this->messageBus->expects($this->never())->method('dispatch');

        $this->expectException(ToolCallException::class);

        $tool->updateArticle('uuid-1', 'en', null, 'blog_article');
    }

    public function testUpdateArticleAllowsTemplateChangeIntoPermittedGroup(): void
    {
        $groupProvider = $this->createMock(GroupProviderInterface::class);
        $groupProvider->method('getGroups')->willReturn([
            (new FormGroup('default', 'Default'))->withTemplate('article'),
            (new FormGroup('blog', 'Blog'))->withTemplate('blog_article'),
        ]);
        $contextResolver = new ArticleSecurityContextResolver($groupProvider);

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturn('https://example.com/admin/');
        $tool = new ArticleUpdateTool(
            $this->messageBus,
            $this->contentManager,
            $this->articleRepository,
            new BlockDataValidator($this->formMetadataProvider),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider),
            new AdminLinkGenerator($router, [new ArticleAdminLinkProvider(new TestViewRegistry())]),
            $this->articleGroupResolver,
            $this->permissionChecker,
            $contextResolver,
        );

        $currentArticle = $this->createMock(ArticleInterface::class);
        $updatedArticle = $this->createMock(ArticleInterface::class);
        $updatedArticle->method('getUuid')->willReturn('uuid-1');
        $this->articleRepository->method('getOneBy')->willReturn($currentArticle);

        $dimensionContent = $this->createMockForIntersectionOfInterfaces([
            TemplateInterface::class,
            DimensionContentInterface::class,
        ]);
        $dimensionContent->method('getTemplateKey')->willReturn('article');
        $this->contentManager->method('resolve')->willReturn($dimensionContent);
        $this->contentManager->method('normalize')->willReturn(['title' => 'Old', 'template' => 'article']);

        // User has EDIT on both the base group (source) and the blog group (target).
        $checkedContexts = [];
        $this->permissionChecker->method('check')->willReturnCallback(
            function (string $context) use (&$checkedContexts): void {
                $checkedContexts[] = $context;
            },
        );

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(fn (Envelope $envelope) => $envelope->with(new HandledStamp($updatedArticle, 'handler')));

        $result = $tool->updateArticle('uuid-1', 'en', null, 'blog_article');

        $this->assertTrue($result['success']);
        $this->assertSame(['sulu.article.articles', 'sulu.article.articles_blog'], $checkedContexts);
    }

    public function testUpdateArticleIgnoresContentTemplateSmuggling(): void
    {
        // Regression guard: only the top-level `template` arg may request a group change;
        // content.template must have zero effect on the written template.
        $groupProvider = $this->createMock(GroupProviderInterface::class);
        $groupProvider->method('getGroups')->willReturn([
            (new FormGroup('default', 'Default'))->withTemplate('article'),
            (new FormGroup('blog', 'Blog'))->withTemplate('blog_article'),
        ]);
        $contextResolver = new ArticleSecurityContextResolver($groupProvider);

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturn('https://example.com/admin/');
        $tool = new ArticleUpdateTool(
            $this->messageBus,
            $this->contentManager,
            $this->articleRepository,
            new BlockDataValidator($this->formMetadataProvider),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider),
            new AdminLinkGenerator($router, [new ArticleAdminLinkProvider(new TestViewRegistry())]),
            $this->articleGroupResolver,
            $this->permissionChecker,
            $contextResolver,
        );

        $currentArticle = $this->createMock(ArticleInterface::class);
        $updatedArticle = $this->createMock(ArticleInterface::class);
        $updatedArticle->method('getUuid')->willReturn('uuid-1');
        $this->articleRepository->method('getOneBy')->willReturn($currentArticle);

        $dimensionContent = $this->createMockForIntersectionOfInterfaces([
            TemplateInterface::class,
            DimensionContentInterface::class,
        ]);
        $dimensionContent->method('getTemplateKey')->willReturn('article');
        $this->contentManager->method('resolve')->willReturn($dimensionContent);
        $this->contentManager->method('normalize')->willReturn(['title' => 'Old', 'template' => 'article']);

        // User has EDIT only on the base group; content can no longer influence the written
        // template, so the (denied) blog-group target check must never fire.
        $checkedContexts = [];
        $this->permissionChecker->method('check')->willReturnCallback(
            function (string $context, string $permission, ?string $locale = null) use (&$checkedContexts): void {
                $checkedContexts[] = $context;
                if ('sulu.article.articles_blog' === $context) {
                    throw new PermissionDeniedException($context, $permission, $locale);
                }
            },
        );

        $capturedData = null;
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (Envelope $envelope) use ($updatedArticle, &$capturedData) {
                $capturedData = $envelope->getMessage()->getData();

                return $envelope->with(new HandledStamp($updatedArticle, 'handler'));
            });

        // Bypass attempt: no top-level `template` arg -- the template is smuggled via content.template.
        $result = $tool->updateArticle('uuid-1', 'en', null, null, ['template' => 'blog_article']);

        $this->assertTrue($result['success']);
        $this->assertSame(['sulu.article.articles'], $checkedContexts);
        $this->assertSame('article', $capturedData['template']);
    }

    public function testUpdateArticleIgnoresNullContentTemplateSmuggling(): void
    {
        // Regression guard: content.template=null used to null out $data['template'], skip the
        // target-group check, and let Sulu default the template — silently moving the group.
        $groupProvider = $this->createMock(GroupProviderInterface::class);
        $groupProvider->method('getGroups')->willReturn([
            (new FormGroup('default', 'Default'))->withTemplate('article'),
            (new FormGroup('blog', 'Blog'))->withTemplate('blog_article'),
        ]);
        $contextResolver = new ArticleSecurityContextResolver($groupProvider);

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturn('https://example.com/admin/');
        $tool = new ArticleUpdateTool(
            $this->messageBus,
            $this->contentManager,
            $this->articleRepository,
            new BlockDataValidator($this->formMetadataProvider),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider),
            new AdminLinkGenerator($router, [new ArticleAdminLinkProvider(new TestViewRegistry())]),
            $this->articleGroupResolver,
            $this->permissionChecker,
            $contextResolver,
        );

        $currentArticle = $this->createMock(ArticleInterface::class);
        $updatedArticle = $this->createMock(ArticleInterface::class);
        $updatedArticle->method('getUuid')->willReturn('uuid-1');
        $this->articleRepository->method('getOneBy')->willReturn($currentArticle);

        $dimensionContent = $this->createMockForIntersectionOfInterfaces([
            TemplateInterface::class,
            DimensionContentInterface::class,
        ]);
        $dimensionContent->method('getTemplateKey')->willReturn('article');
        $this->contentManager->method('resolve')->willReturn($dimensionContent);
        $this->contentManager->method('normalize')->willReturn(['title' => 'Old', 'template' => 'article']);

        $checkedContexts = [];
        $this->permissionChecker->method('check')->willReturnCallback(
            function (string $context, string $permission, ?string $locale = null) use (&$checkedContexts): void {
                $checkedContexts[] = $context;
                if ('sulu.article.articles_blog' === $context) {
                    throw new PermissionDeniedException($context, $permission, $locale);
                }
            },
        );

        $capturedData = null;
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (Envelope $envelope) use ($updatedArticle, &$capturedData) {
                $capturedData = $envelope->getMessage()->getData();

                return $envelope->with(new HandledStamp($updatedArticle, 'handler'));
            });

        $result = $tool->updateArticle('uuid-1', 'en', null, null, ['template' => null, 'article' => '<p>New</p>']);

        $this->assertTrue($result['success']);
        $this->assertSame(['sulu.article.articles'], $checkedContexts);
        $this->assertSame('article', $capturedData['template']);
    }

    public function testUpdateArticleForcesAuthorizedLocaleOverContentSmuggling(): void
    {
        $currentArticle = $this->createMock(ArticleInterface::class);
        $updatedArticle = $this->createMock(ArticleInterface::class);
        $updatedArticle->method('getUuid')->willReturn('uuid-1');

        $this->articleRepository->method('getOneBy')->willReturn($currentArticle);

        $dimensionContent = $this->createMockForIntersectionOfInterfaces([TemplateInterface::class, DimensionContentInterface::class]);
        $dimensionContent->method('getTemplateKey')->willReturn('article');
        $this->contentManager->method('resolve')->willReturn($dimensionContent);
        $this->contentManager->method('normalize')->willReturn(['title' => 'Old', 'template' => 'article']);

        $capturedData = null;
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (Envelope $envelope) use ($updatedArticle, &$capturedData) {
                $capturedData = $envelope->getMessage()->getData();

                return $envelope->with(new HandledStamp($updatedArticle, 'handler'));
            });

        // Caller is authorized for locale 'en' only; content.locale attempts to smuggle 'de'.
        $result = $this->tool->updateArticle('uuid-1', 'en', null, null, ['locale' => 'de', 'article' => '<p>New</p>']);

        $this->assertTrue($result['success']);
        $this->assertSame('en', $capturedData['locale']);
    }

    public function testUpdateArticleAllowsSameGroupContentEditWithoutTemplateChange(): void
    {
        $groupProvider = $this->createMock(GroupProviderInterface::class);
        $groupProvider->method('getGroups')->willReturn([
            (new FormGroup('default', 'Default'))->withTemplate('article'),
            (new FormGroup('blog', 'Blog'))->withTemplate('blog_article'),
        ]);
        $contextResolver = new ArticleSecurityContextResolver($groupProvider);

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturn('https://example.com/admin/');
        $tool = new ArticleUpdateTool(
            $this->messageBus,
            $this->contentManager,
            $this->articleRepository,
            new BlockDataValidator($this->formMetadataProvider),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider),
            new AdminLinkGenerator($router, [new ArticleAdminLinkProvider(new TestViewRegistry())]),
            $this->articleGroupResolver,
            $this->permissionChecker,
            $contextResolver,
        );

        $currentArticle = $this->createMock(ArticleInterface::class);
        $updatedArticle = $this->createMock(ArticleInterface::class);
        $updatedArticle->method('getUuid')->willReturn('uuid-1');
        $this->articleRepository->method('getOneBy')->willReturn($currentArticle);

        $dimensionContent = $this->createMockForIntersectionOfInterfaces([
            TemplateInterface::class,
            DimensionContentInterface::class,
        ]);
        $dimensionContent->method('getTemplateKey')->willReturn('article');
        $this->contentManager->method('resolve')->willReturn($dimensionContent);
        $this->contentManager->method('normalize')->willReturn(['title' => 'Old', 'template' => 'article']);

        // User has EDIT only on the base group, but content.template repeats the current
        // template, so no group change happens and the target check must not fire.
        $checkedContexts = [];
        $this->permissionChecker->method('check')->willReturnCallback(
            function (string $context, string $permission, ?string $locale = null) use (&$checkedContexts): void {
                $checkedContexts[] = $context;
                if ('sulu.article.articles_blog' === $context) {
                    throw new PermissionDeniedException($context, $permission, $locale);
                }
            },
        );

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(fn (Envelope $envelope) => $envelope->with(new HandledStamp($updatedArticle, 'handler')));

        $result = $tool->updateArticle('uuid-1', 'en', null, null, ['template' => 'article', 'article' => '<p>New</p>']);

        $this->assertTrue($result['success']);
        $this->assertSame(['sulu.article.articles'], $checkedContexts);
    }

    public function testUpdateArticleAcceptsValidUrlInContent(): void
    {
        $currentArticle = $this->createMock(ArticleInterface::class);
        $updatedArticle = $this->createMock(ArticleInterface::class);
        $updatedArticle->method('getUuid')->willReturn('uuid-1');

        $this->articleRepository->method('getOneBy')->willReturn($currentArticle);

        $dimensionContent = $this->createMockForIntersectionOfInterfaces([TemplateInterface::class, DimensionContentInterface::class]);
        $dimensionContent->method('getTemplateKey')->willReturn('article');
        $this->contentManager->method('resolve')->willReturn($dimensionContent);
        $this->contentManager->method('normalize')->willReturn([]);

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(fn (Envelope $envelope) => $envelope->with(new HandledStamp($updatedArticle, 'handler')));

        $result = $this->tool->updateArticle('uuid-1', 'en', null, null, ['url' => '/renamed']);

        $this->assertTrue($result['success']);
    }

    public function testUpdateArticleNormalizesPageTreeRouteAlias(): void
    {
        $currentArticle = $this->createMock(ArticleInterface::class);
        $updatedArticle = $this->createMock(ArticleInterface::class);
        $updatedArticle->method('getUuid')->willReturn('uuid-1');

        $this->articleRepository->method('getOneBy')->willReturn($currentArticle);

        $dimensionContent = $this->createMockForIntersectionOfInterfaces([TemplateInterface::class, DimensionContentInterface::class]);
        $dimensionContent->method('getTemplateKey')->willReturn('article');
        $this->contentManager->method('resolve')->willReturn($dimensionContent);
        $this->contentManager->method('normalize')->willReturn([
            'title' => 'Old',
            'url' => [
                'page' => [
                    'path' => '/blog',
                    'uuid' => 'parent-page-uuid',
                ],
                'suffix' => '/old',
            ],
        ]);

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (Envelope $envelope) use ($updatedArticle) {
                $message = $envelope->getMessage();
                $this->assertInstanceOf(ModifyArticleMessage::class, $message);
                $this->assertSame([
                    'page' => [
                        'path' => '/blog',
                        'uuid' => 'parent-page-uuid',
                    ],
                    'suffix' => 'new',
                ], $message->getData()['url']);
                $this->assertArrayNotHasKey('page', $message->getData());

                return $envelope->with(new HandledStamp($updatedArticle, 'handler'));
            });

        $result = $this->tool->updateArticle('uuid-1', 'en', null, null, [
            'page' => [
                'path' => '/blog',
                'uuid' => 'parent-page-uuid',
                'suffix' => 'new',
            ],
        ]);

        $this->assertTrue($result['success']);
    }

    public function testUpdateArticleRejectsInvalidRoutingInContent(): void
    {
        $currentArticle = $this->createMock(ArticleInterface::class);
        $this->articleRepository->method('getOneBy')->willReturn($currentArticle);

        $dimensionContent = $this->createMockForIntersectionOfInterfaces([TemplateInterface::class, DimensionContentInterface::class]);
        $dimensionContent->method('getTemplateKey')->willReturn('article');
        $this->contentManager->method('resolve')->willReturn($dimensionContent);
        $this->contentManager->method('normalize')->willReturn([]);

        $this->messageBus->expects($this->never())->method('dispatch');

        $result = $this->tool->updateArticle('uuid-1', 'en', null, null, ['url' => 'no-leading-slash']);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('start with', $result['error']);
    }

    public function testUpdateArticleAssignsBlockIdsToNestedBlocks(): void
    {
        $currentArticle = $this->createMock(ArticleInterface::class);
        $updatedArticle = $this->createMock(ArticleInterface::class);
        $updatedArticle->method('getUuid')->willReturn('uuid-1');

        $this->articleRepository->method('getOneBy')->willReturn($currentArticle);

        $dimensionContent = $this->createMockForIntersectionOfInterfaces([TemplateInterface::class, DimensionContentInterface::class]);
        $dimensionContent->method('getTemplateKey')->willReturn('blog');
        $this->contentManager->method('resolve')->willReturn($dimensionContent);
        $this->contentManager->method('normalize')->willReturn(['title' => 'Old', 'template' => 'blog']);

        $capturedData = null;
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (Envelope $envelope) use ($updatedArticle, &$capturedData) {
                $message = $envelope->getMessage();
                $this->assertInstanceOf(ModifyArticleMessage::class, $message);
                $capturedData = $message->getData();

                return $envelope->with(new HandledStamp($updatedArticle, 'handler'));
            });

        $this->tool->updateArticle('uuid-1', 'en', null, null, [
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

    public function testUpdateArticleRejectsInvalidBlocksBeforeWrite(): void
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
        $this->tool = new ArticleUpdateTool(
            $this->messageBus,
            $this->contentManager,
            $this->articleRepository,
            new BlockDataValidator($this->formMetadataProvider),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider),
            new AdminLinkGenerator($router, [new ArticleAdminLinkProvider(new TestViewRegistry())]),
            $this->articleGroupResolver,
            $this->permissionChecker,
            $this->articleContextResolver,
        );

        $currentArticle = $this->createMock(ArticleInterface::class);
        $this->articleRepository->method('getOneBy')->willReturn($currentArticle);

        $dimensionContent = $this->createMockForIntersectionOfInterfaces([TemplateInterface::class, DimensionContentInterface::class]);
        $dimensionContent->method('getTemplateKey')->willReturn('blog');
        $this->contentManager->method('resolve')->willReturn($dimensionContent);
        $this->contentManager->method('normalize')->willReturn(['title' => 'Old', 'template' => 'blog']);

        $this->messageBus->expects($this->never())->method('dispatch');

        $result = $this->tool->updateArticle('uuid-1', 'en', null, null, [
            'url' => '/my-article',
            'blocks' => [
                ['type' => 'text', 'bogus' => 'invalid-key'],
            ],
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('bogus', $result['error']);
    }

    public function testUpdateArticleReturnsCompactedData(): void
    {
        $currentArticle = $this->createMock(ArticleInterface::class);
        $updatedArticle = $this->createMock(ArticleInterface::class);
        $updatedArticle->method('getUuid')->willReturn('uuid-1');

        $this->articleRepository->method('getOneBy')->willReturn($currentArticle);

        $dimensionContent = $this->createMockForIntersectionOfInterfaces([TemplateInterface::class, DimensionContentInterface::class]);
        $dimensionContent->method('getTemplateKey')->willReturn('article');
        $this->contentManager->method('resolve')->willReturn($dimensionContent);
        $this->contentManager->method('normalize')->willReturn([
            'title' => 'New Title',
            'id' => 42,
            'blocks' => [['_id' => 'b1', 'type' => 'text', 'content' => '<p>HTML</p>']],
        ]);

        $this->messageBus->method('dispatch')
            ->willReturnCallback(fn (Envelope $envelope) => $envelope->with(new HandledStamp($updatedArticle, 'handler')));

        $result = $this->tool->updateArticle('uuid-1', 'en', 'New Title');

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('id', $result['data']);
        $this->assertSame('New Title', $result['data']['title']);
        // Blocks are summarized to index/type, not full content
        $this->assertSame('text', $result['data']['blocks'][0]['type']);
        $this->assertArrayNotHasKey('content', $result['data']['blocks'][0]);
    }

    public function testUpdateArticleSetsExcerptAndSeoInDispatchedData(): void
    {
        $currentArticle = $this->createMock(ArticleInterface::class);
        $updatedArticle = $this->createMock(ArticleInterface::class);
        $updatedArticle->method('getUuid')->willReturn('uuid-1');

        $this->articleRepository->method('getOneBy')->willReturn($currentArticle);

        $dimensionContent = $this->createMockForIntersectionOfInterfaces([TemplateInterface::class, DimensionContentInterface::class]);
        $dimensionContent->method('getTemplateKey')->willReturn('blog');
        $this->contentManager->method('resolve')->willReturn($dimensionContent);
        $this->contentManager->method('normalize')->willReturn(['title' => 'Old', 'template' => 'blog']);

        $capturedMessage = null;
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (Envelope $envelope) use ($updatedArticle, &$capturedMessage) {
                $capturedMessage = $envelope->getMessage();

                return $envelope->with(new HandledStamp($updatedArticle, 'handler'));
            });

        $this->tool->updateArticle(
            'uuid-1',
            'en',
            null,
            null,
            ['url' => '/my-article'],
            ['title' => 'T', 'image' => ['id' => 5]],
            ['title' => 'S', 'seoNoIndex' => true],
        );

        $this->assertInstanceOf(ModifyArticleMessage::class, $capturedMessage);
        $data = $capturedMessage->getData();
        $this->assertSame('T', $data['excerpt']['title']);
        $this->assertSame(['id' => 5], $data['excerpt']['image']);
        $this->assertSame('S', $data['seo']['title']);
        $this->assertTrue($data['seoNoIndex']);
    }
}
