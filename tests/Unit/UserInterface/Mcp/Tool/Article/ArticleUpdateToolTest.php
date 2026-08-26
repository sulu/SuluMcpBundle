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
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Article\Application\Message\ModifyArticleMessage;
use Sulu\Article\Domain\Model\Article;
use Sulu\Article\Domain\Model\ArticleDimensionContent;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
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
use Sulu\Mcp\Application\Security\ContentSecurityContextResolver;
use Sulu\Mcp\Infrastructure\Sulu\AdminLink\ArticleAdminLinkProvider;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\Infrastructure\Symfony\Routing\AdminLinkGenerator;
use Sulu\Mcp\Tests\Application\TestBundle\Admin\TestViewRegistry;
use Sulu\Mcp\Tests\Application\TestBundle\Metadata\TestGroupProvider;
use Sulu\Mcp\Tests\Unit\Fixture\ArrayMetadataProvider;
use Sulu\Mcp\Tests\Unit\Fixture\FakeToolPermissionChecker;
use Sulu\Mcp\Tests\Unit\Fixture\FixedBlockIdGenerator;
use Sulu\Mcp\UserInterface\Mcp\Tool\Article\ArticleUpdateTool;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

#[CoversClass(ArticleUpdateTool::class)]
final class ArticleUpdateToolTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<MessageBusInterface> */
    private ObjectProphecy $messageBus;

    /** @var ObjectProphecy<ContentManagerInterface> */
    private ObjectProphecy $contentManager;

    /** @var ObjectProphecy<ArticleRepositoryInterface> */
    private ObjectProphecy $articleRepository;

    private FixedBlockIdGenerator $blockIdGenerator;
    private ArrayMetadataProvider $formMetadataProvider;
    private ArrayMetadataProvider $mapperMetadataProvider;
    private ArticleGroupResolver $articleGroupResolver;
    private FakeToolPermissionChecker $permissionChecker;
    private ArticleSecurityContextResolver $articleContextResolver;
    private ArticleUpdateTool $tool;

    protected function setUp(): void
    {
        $this->messageBus = $this->prophesize(MessageBusInterface::class);
        $this->contentManager = $this->prophesize(ContentManagerInterface::class);
        $this->articleRepository = $this->prophesize(ArticleRepositoryInterface::class);
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
        $this->permissionChecker = FakeToolPermissionChecker::grantingAll();
        $contextGroupProvider = new TestGroupProvider([]);
        $this->articleContextResolver = new ArticleSecurityContextResolver($contextGroupProvider);
        $this->tool = new ArticleUpdateTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            $this->articleRepository->reveal(),
            new BlockDataValidator($this->formMetadataProvider, new MetadataLocaleResolver(new TokenStorage(), 'en')),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider),
            $adminLinkGenerator,
            $this->articleGroupResolver,
            $this->permissionChecker,
            $this->articleContextResolver,
            new ContentSecurityContextResolver($this->articleContextResolver, $this->contentManager->reveal()),
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

    public function testUpdateArticleReadsCurrentStateMergesAndDispatches(): void
    {
        $currentArticle = new Article('uuid-1');
        $updatedArticle = new Article('uuid-1');

        $this->articleRepository->getOneBy(Argument::cetera())->willReturn($currentArticle);

        $dimensionContent = new ArticleDimensionContent(new Article());
        $dimensionContent->setLocale('en');
        $dimensionContent->setTemplateKey('blog');
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn(['title' => 'Old Title', 'template' => 'blog']);

        $capturedEnvelope = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use ($updatedArticle, &$capturedEnvelope) {
                $capturedEnvelope = $args[0];

                return $args[0]->with(new HandledStamp($updatedArticle, 'handler'));
            });

        $result = $this->tool->updateArticle('uuid-1', 'en', 'New Title');

        $this->assertArrayHasKey(EnableFlushStamp::class, $capturedEnvelope->all());
        $this->assertTrue($result['success']);
        $this->assertSame('uuid-1', $result['uuid']);
        $this->assertSame('https://example.com/admin/#/en/default/uuid-1', $result['admin_url']);
    }

    public function testUpdateArticleMergesContentOverCurrentData(): void
    {
        $currentArticle = new Article();
        $updatedArticle = new Article('uuid-1');

        $this->articleRepository->getOneBy(Argument::cetera())->willReturn($currentArticle);

        $dimensionContent = new ArticleDimensionContent(new Article());
        $dimensionContent->setLocale('en');
        $dimensionContent->setTemplateKey('article');
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn(['title' => 'Old', 'article' => '<p>Old</p>']);

        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(fn (array $args) => $args[0]->with(new HandledStamp($updatedArticle, 'handler')));

        $result = $this->tool->updateArticle('uuid-1', 'en', null, null, ['article' => '<p>New</p>']);

        $this->assertTrue($result['success']);
    }

    public function testUpdateArticleReturnsErrorOnException(): void
    {
        $this->articleRepository->getOneBy(Argument::cetera())
            ->willThrow(new \RuntimeException('Article not found'));

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
        $currentArticle = new Article();
        $this->articleRepository->getOneBy(Argument::cetera())->willReturn($currentArticle);

        $dimensionContent = new ArticleDimensionContent(new Article());
        $dimensionContent->setLocale('en');
        $dimensionContent->setTemplateKey('article');
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);

        $this->permissionChecker->denyAll();

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(ToolCallException::class);

        $this->tool->updateArticle('uuid-1', 'en', 'New Title');
    }

    public function testUpdateArticleDeniesTemplateChangeIntoUnpermittedGroup(): void
    {
        $groupProvider = new TestGroupProvider([
            (new FormGroup('default', 'Default'))->withTemplate('article'),
            (new FormGroup('blog', 'Blog'))->withTemplate('blog_article'),
        ]);
        $contextResolver = new ArticleSecurityContextResolver($groupProvider);

        $router = $this->prophesize(RouterInterface::class);
        $router->generate(Argument::cetera())->willReturn('https://example.com/admin/');
        $tool = new ArticleUpdateTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            $this->articleRepository->reveal(),
            new BlockDataValidator($this->formMetadataProvider, new MetadataLocaleResolver(new TokenStorage(), 'en')),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider),
            new AdminLinkGenerator($router->reveal(), [new ArticleAdminLinkProvider(new TestViewRegistry())]),
            $this->articleGroupResolver,
            $this->permissionChecker,
            $contextResolver,
            new ContentSecurityContextResolver($contextResolver, $this->contentManager->reveal()),
        );

        $currentArticle = new Article();
        $this->articleRepository->getOneBy(Argument::cetera())->willReturn($currentArticle);

        $dimensionContent = new ArticleDimensionContent(new Article());
        $dimensionContent->setLocale('en');
        $dimensionContent->setTemplateKey('article');
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);

        // User has EDIT on the base group (source context) but not on the blog group (target context).
        $this->permissionChecker->denyContext('sulu.article.articles_blog');

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(ToolCallException::class);

        $tool->updateArticle('uuid-1', 'en', null, 'blog_article');
    }

    public function testUpdateArticleAllowsTemplateChangeIntoPermittedGroup(): void
    {
        $groupProvider = new TestGroupProvider([
            (new FormGroup('default', 'Default'))->withTemplate('article'),
            (new FormGroup('blog', 'Blog'))->withTemplate('blog_article'),
        ]);
        $contextResolver = new ArticleSecurityContextResolver($groupProvider);

        $router = $this->prophesize(RouterInterface::class);
        $router->generate(Argument::cetera())->willReturn('https://example.com/admin/');
        $tool = new ArticleUpdateTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            $this->articleRepository->reveal(),
            new BlockDataValidator($this->formMetadataProvider, new MetadataLocaleResolver(new TokenStorage(), 'en')),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider),
            new AdminLinkGenerator($router->reveal(), [new ArticleAdminLinkProvider(new TestViewRegistry())]),
            $this->articleGroupResolver,
            $this->permissionChecker,
            $contextResolver,
            new ContentSecurityContextResolver($contextResolver, $this->contentManager->reveal()),
        );

        $currentArticle = new Article();
        $updatedArticle = new Article('uuid-1');
        $this->articleRepository->getOneBy(Argument::cetera())->willReturn($currentArticle);

        $dimensionContent = new ArticleDimensionContent(new Article());
        $dimensionContent->setLocale('en');
        $dimensionContent->setTemplateKey('article');
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn(['title' => 'Old', 'template' => 'article']);

        // User has EDIT on both the base group (source) and the blog group (target).

        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(fn (array $args) => $args[0]->with(new HandledStamp($updatedArticle, 'handler')));

        $result = $tool->updateArticle('uuid-1', 'en', null, 'blog_article');

        $this->assertTrue($result['success']);
        $this->assertSame(['sulu.article.articles', 'sulu.article.articles_blog'], $this->permissionChecker->checkedContexts());
    }

    public function testUpdateArticleIgnoresContentTemplateSmuggling(): void
    {
        // Regression guard: only the top-level `template` arg may request a group change;
        // content.template must have zero effect on the written template.
        $groupProvider = new TestGroupProvider([
            (new FormGroup('default', 'Default'))->withTemplate('article'),
            (new FormGroup('blog', 'Blog'))->withTemplate('blog_article'),
        ]);
        $contextResolver = new ArticleSecurityContextResolver($groupProvider);

        $router = $this->prophesize(RouterInterface::class);
        $router->generate(Argument::cetera())->willReturn('https://example.com/admin/');
        $tool = new ArticleUpdateTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            $this->articleRepository->reveal(),
            new BlockDataValidator($this->formMetadataProvider, new MetadataLocaleResolver(new TokenStorage(), 'en')),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider),
            new AdminLinkGenerator($router->reveal(), [new ArticleAdminLinkProvider(new TestViewRegistry())]),
            $this->articleGroupResolver,
            $this->permissionChecker,
            $contextResolver,
            new ContentSecurityContextResolver($contextResolver, $this->contentManager->reveal()),
        );

        $currentArticle = new Article();
        $updatedArticle = new Article('uuid-1');
        $this->articleRepository->getOneBy(Argument::cetera())->willReturn($currentArticle);

        $dimensionContent = new ArticleDimensionContent(new Article());
        $dimensionContent->setLocale('en');
        $dimensionContent->setTemplateKey('article');
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn(['title' => 'Old', 'template' => 'article']);

        // User has EDIT only on the base group; content can no longer influence the written
        // template, so the (denied) blog-group target check must never fire.
        $this->permissionChecker->denyContext('sulu.article.articles_blog');

        $capturedData = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use ($updatedArticle, &$capturedData) {
                $capturedData = $args[0]->getMessage()->getData();

                return $args[0]->with(new HandledStamp($updatedArticle, 'handler'));
            });

        // Bypass attempt: no top-level `template` arg -- the template is smuggled via content.template.
        $result = $tool->updateArticle('uuid-1', 'en', null, null, ['template' => 'blog_article']);

        $this->assertTrue($result['success']);
        $this->assertSame(['sulu.article.articles'], $this->permissionChecker->checkedContexts());
        $this->assertSame('article', $capturedData['template']);
    }

    public function testUpdateArticleIgnoresNullContentTemplateSmuggling(): void
    {
        // Regression guard: content.template=null used to null out $data['template'], skip the
        // target-group check, and let Sulu default the template — silently moving the group.
        $groupProvider = new TestGroupProvider([
            (new FormGroup('default', 'Default'))->withTemplate('article'),
            (new FormGroup('blog', 'Blog'))->withTemplate('blog_article'),
        ]);
        $contextResolver = new ArticleSecurityContextResolver($groupProvider);

        $router = $this->prophesize(RouterInterface::class);
        $router->generate(Argument::cetera())->willReturn('https://example.com/admin/');
        $tool = new ArticleUpdateTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            $this->articleRepository->reveal(),
            new BlockDataValidator($this->formMetadataProvider, new MetadataLocaleResolver(new TokenStorage(), 'en')),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider),
            new AdminLinkGenerator($router->reveal(), [new ArticleAdminLinkProvider(new TestViewRegistry())]),
            $this->articleGroupResolver,
            $this->permissionChecker,
            $contextResolver,
            new ContentSecurityContextResolver($contextResolver, $this->contentManager->reveal()),
        );

        $currentArticle = new Article();
        $updatedArticle = new Article('uuid-1');
        $this->articleRepository->getOneBy(Argument::cetera())->willReturn($currentArticle);

        $dimensionContent = new ArticleDimensionContent(new Article());
        $dimensionContent->setLocale('en');
        $dimensionContent->setTemplateKey('article');
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn(['title' => 'Old', 'template' => 'article']);

        $this->permissionChecker->denyContext('sulu.article.articles_blog');

        $capturedData = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use ($updatedArticle, &$capturedData) {
                $capturedData = $args[0]->getMessage()->getData();

                return $args[0]->with(new HandledStamp($updatedArticle, 'handler'));
            });

        $result = $tool->updateArticle('uuid-1', 'en', null, null, ['template' => null, 'article' => '<p>New</p>']);

        $this->assertTrue($result['success']);
        $this->assertSame(['sulu.article.articles'], $this->permissionChecker->checkedContexts());
        $this->assertSame('article', $capturedData['template']);
    }

    public function testUpdateArticleForcesAuthorizedLocaleOverContentSmuggling(): void
    {
        $currentArticle = new Article();
        $updatedArticle = new Article('uuid-1');

        $this->articleRepository->getOneBy(Argument::cetera())->willReturn($currentArticle);

        $dimensionContent = new ArticleDimensionContent(new Article());
        $dimensionContent->setLocale('en');
        $dimensionContent->setTemplateKey('article');
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn(['title' => 'Old', 'template' => 'article']);

        $capturedData = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use ($updatedArticle, &$capturedData) {
                $capturedData = $args[0]->getMessage()->getData();

                return $args[0]->with(new HandledStamp($updatedArticle, 'handler'));
            });

        // Caller is authorized for locale 'en' only; content.locale attempts to smuggle 'de'.
        $result = $this->tool->updateArticle('uuid-1', 'en', null, null, ['locale' => 'de', 'article' => '<p>New</p>']);

        $this->assertTrue($result['success']);
        $this->assertSame('en', $capturedData['locale']);
    }

    public function testUpdateArticleAllowsSameGroupContentEditWithoutTemplateChange(): void
    {
        $groupProvider = new TestGroupProvider([
            (new FormGroup('default', 'Default'))->withTemplate('article'),
            (new FormGroup('blog', 'Blog'))->withTemplate('blog_article'),
        ]);
        $contextResolver = new ArticleSecurityContextResolver($groupProvider);

        $router = $this->prophesize(RouterInterface::class);
        $router->generate(Argument::cetera())->willReturn('https://example.com/admin/');
        $tool = new ArticleUpdateTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            $this->articleRepository->reveal(),
            new BlockDataValidator($this->formMetadataProvider, new MetadataLocaleResolver(new TokenStorage(), 'en')),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider),
            new AdminLinkGenerator($router->reveal(), [new ArticleAdminLinkProvider(new TestViewRegistry())]),
            $this->articleGroupResolver,
            $this->permissionChecker,
            $contextResolver,
            new ContentSecurityContextResolver($contextResolver, $this->contentManager->reveal()),
        );

        $currentArticle = new Article();
        $updatedArticle = new Article('uuid-1');
        $this->articleRepository->getOneBy(Argument::cetera())->willReturn($currentArticle);

        $dimensionContent = new ArticleDimensionContent(new Article());
        $dimensionContent->setLocale('en');
        $dimensionContent->setTemplateKey('article');
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn(['title' => 'Old', 'template' => 'article']);

        // User has EDIT only on the base group, but content.template repeats the current
        // template, so no group change happens and the target check must not fire.
        $this->permissionChecker->denyContext('sulu.article.articles_blog');

        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(fn (array $args) => $args[0]->with(new HandledStamp($updatedArticle, 'handler')));

        $result = $tool->updateArticle('uuid-1', 'en', null, null, ['template' => 'article', 'article' => '<p>New</p>']);

        $this->assertTrue($result['success']);
        $this->assertSame(['sulu.article.articles'], $this->permissionChecker->checkedContexts());
    }

    public function testUpdateArticleAcceptsValidUrlInContent(): void
    {
        $currentArticle = new Article();
        $updatedArticle = new Article('uuid-1');

        $this->articleRepository->getOneBy(Argument::cetera())->willReturn($currentArticle);

        $dimensionContent = new ArticleDimensionContent(new Article());
        $dimensionContent->setLocale('en');
        $dimensionContent->setTemplateKey('article');
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([]);

        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(fn (array $args) => $args[0]->with(new HandledStamp($updatedArticle, 'handler')));

        $result = $this->tool->updateArticle('uuid-1', 'en', null, null, ['url' => '/renamed']);

        $this->assertTrue($result['success']);
    }

    public function testUpdateArticleNormalizesPageTreeRouteAlias(): void
    {
        $currentArticle = new Article();
        $updatedArticle = new Article('uuid-1');

        $this->articleRepository->getOneBy(Argument::cetera())->willReturn($currentArticle);

        $dimensionContent = new ArticleDimensionContent(new Article());
        $dimensionContent->setLocale('en');
        $dimensionContent->setTemplateKey('article');
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([
            'title' => 'Old',
            'url' => [
                'page' => [
                    'path' => '/blog',
                    'uuid' => 'parent-page-uuid',
                ],
                'suffix' => '/old',
            ],
        ]);

        $capturedMessage = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use ($updatedArticle, &$capturedMessage) {
                $capturedMessage = $args[0]->getMessage();

                return $args[0]->with(new HandledStamp($updatedArticle, 'handler'));
            });

        $result = $this->tool->updateArticle('uuid-1', 'en', null, null, [
            'page' => [
                'path' => '/blog',
                'uuid' => 'parent-page-uuid',
                'suffix' => 'new',
            ],
        ]);

        $this->assertInstanceOf(ModifyArticleMessage::class, $capturedMessage);
        $this->assertSame([
            'page' => [
                'path' => '/blog',
                'uuid' => 'parent-page-uuid',
            ],
            'suffix' => 'new',
        ], $capturedMessage->getData()['url']);
        $this->assertArrayNotHasKey('page', $capturedMessage->getData());

        $this->assertTrue($result['success']);
    }

    public function testUpdateArticleRejectsInvalidRoutingInContent(): void
    {
        $currentArticle = new Article();
        $this->articleRepository->getOneBy(Argument::cetera())->willReturn($currentArticle);

        $dimensionContent = new ArticleDimensionContent(new Article());
        $dimensionContent->setLocale('en');
        $dimensionContent->setTemplateKey('article');
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([]);

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->updateArticle('uuid-1', 'en', null, null, ['url' => 'no-leading-slash']);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('start with', $result['error']);
    }

    public function testUpdateArticleAssignsBlockIdsToNestedBlocks(): void
    {
        $currentArticle = new Article();
        $updatedArticle = new Article('uuid-1');

        $this->articleRepository->getOneBy(Argument::cetera())->willReturn($currentArticle);

        $dimensionContent = new ArticleDimensionContent(new Article());
        $dimensionContent->setLocale('en');
        $dimensionContent->setTemplateKey('blog');
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn(['title' => 'Old', 'template' => 'blog']);

        $capturedMessage = null;
        $capturedData = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use ($updatedArticle, &$capturedMessage, &$capturedData) {
                $capturedMessage = $args[0]->getMessage();
                $capturedData = $capturedMessage->getData();

                return $args[0]->with(new HandledStamp($updatedArticle, 'handler'));
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

        $this->assertInstanceOf(ModifyArticleMessage::class, $capturedMessage);
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

        $this->formMetadataProvider = new ArrayMetadataProvider();
        $this->formMetadataProvider->set('article', $typed);

        $router = $this->prophesize(RouterInterface::class);
        $router->generate(Argument::cetera())->willReturn('https://example.com/admin/');
        $this->tool = new ArticleUpdateTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            $this->articleRepository->reveal(),
            new BlockDataValidator($this->formMetadataProvider, new MetadataLocaleResolver(new TokenStorage(), 'en')),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider),
            new AdminLinkGenerator($router->reveal(), [new ArticleAdminLinkProvider(new TestViewRegistry())]),
            $this->articleGroupResolver,
            $this->permissionChecker,
            $this->articleContextResolver,
            new ContentSecurityContextResolver($this->articleContextResolver, $this->contentManager->reveal()),
        );

        $currentArticle = new Article();
        $this->articleRepository->getOneBy(Argument::cetera())->willReturn($currentArticle);

        $dimensionContent = new ArticleDimensionContent(new Article());
        $dimensionContent->setLocale('en');
        $dimensionContent->setTemplateKey('blog');
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn(['title' => 'Old', 'template' => 'blog']);

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

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
        $currentArticle = new Article();
        $updatedArticle = new Article('uuid-1');

        $this->articleRepository->getOneBy(Argument::cetera())->willReturn($currentArticle);

        $dimensionContent = new ArticleDimensionContent(new Article());
        $dimensionContent->setLocale('en');
        $dimensionContent->setTemplateKey('article');
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([
            'title' => 'New Title',
            'id' => 42,
            'blocks' => [['_id' => 'b1', 'type' => 'text', 'content' => '<p>HTML</p>']],
        ]);

        $this->messageBus->dispatch(Argument::cetera())
            ->will(fn (array $args) => $args[0]->with(new HandledStamp($updatedArticle, 'handler')));

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
        $currentArticle = new Article();
        $updatedArticle = new Article('uuid-1');

        $this->articleRepository->getOneBy(Argument::cetera())->willReturn($currentArticle);

        $dimensionContent = new ArticleDimensionContent(new Article());
        $dimensionContent->setLocale('en');
        $dimensionContent->setTemplateKey('blog');
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn(['title' => 'Old', 'template' => 'blog']);

        $capturedMessage = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use ($updatedArticle, &$capturedMessage) {
                $capturedMessage = $args[0]->getMessage();

                return $args[0]->with(new HandledStamp($updatedArticle, 'handler'));
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

    /**
     * A ghost resolves to the unlocalized dimension, so its locale stays null while
     * ghostLocale and availableLocales name the locales that do exist.
     *
     * @param non-empty-list<string> $translatedLocales
     */
    private function setUpGhostLocale(array $translatedLocales = ['de'], string $sourceTemplateKey = 'article'): void
    {
        $this->articleRepository->getOneBy(Argument::cetera())->willReturn(new Article('uuid-1'));

        $ghostDimensionContent = new ArticleDimensionContent(new Article());
        $ghostDimensionContent->setGhostLocale($translatedLocales[0]);
        foreach ($translatedLocales as $translatedLocale) {
            $ghostDimensionContent->addAvailableLocale($translatedLocale);
        }

        $sourceDimensionContent = new ArticleDimensionContent(new Article());
        $sourceDimensionContent->setLocale($translatedLocales[0]);
        $sourceDimensionContent->setTemplateKey($sourceTemplateKey);

        $this->contentManager->resolve(Argument::cetera())->willReturn($ghostDimensionContent);
        $this->contentManager->resolve(
            Argument::any(),
            ['locale' => $translatedLocales[0], 'stage' => DimensionContentInterface::STAGE_DRAFT],
        )->willReturn($sourceDimensionContent);
        // url: the route the post-dispatch normalize reports for the new locale.
        $this->contentManager->normalize(Argument::cetera())
            ->willReturn(['locale' => null, 'availableLocales' => $translatedLocales, 'url' => '/english-article']);
    }

    public function testUpdateArticleCreatesMissingLocale(): void
    {
        $this->setUpGhostLocale();

        $updatedArticle = new Article('uuid-1');

        $capturedEnvelope = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use ($updatedArticle, &$capturedEnvelope) {
                $capturedEnvelope = $args[0];

                return $args[0]->with(new HandledStamp($updatedArticle, 'handler'));
            });

        $result = $this->tool->updateArticle('uuid-1', 'en', 'English Title', 'blog', ['url' => '/english-article']);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['created_locale']);

        $message = $capturedEnvelope->getMessage();
        $this->assertInstanceOf(ModifyArticleMessage::class, $message);
        $capturedData = $message->getData();

        $this->assertSame('en', $capturedData['locale']);
        $this->assertSame('English Title', $capturedData['title']);
        $this->assertSame('blog', $capturedData['template']);
        // The unlocalized dimension's own fields must not travel into the new locale.
        $this->assertArrayNotHasKey('availableLocales', $capturedData);
    }

    public function testUpdateArticleRejectsIncompleteNewLocaleWithoutDispatching(): void
    {
        $this->setUpGhostLocale(['de', 'fr']);

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->updateArticle('uuid-1', 'en', 'English Title', 'blog');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('has no "en" content yet', $result['error']);
        $this->assertStringContainsString('title, template and routing data', $result['hint']);
        $this->assertStringContainsString('de, fr', $result['hint']);
    }

    public function testUpdateArticleRequiresRoutingDataWhenCreatingLocale(): void
    {
        $this->setUpGhostLocale();

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->updateArticle('uuid-1', 'en', 'English Title', 'blog', ['article' => '<p>EN</p>']);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('routing data', $result['error']);
    }

    public function testUpdateArticleDeniesCreatingALocaleWithoutTheSourceGroup(): void
    {
        $tool = $this->multiGroupTool();
        $this->setUpGhostLocale(['de'], 'blog_article');

        $this->permissionChecker->denyContext('sulu.article.articles_blog');

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(ToolCallException::class);

        $tool->updateArticle('uuid-1', 'en', 'English Title', 'article', ['url' => '/english-article']);
    }

    public function testUpdateArticleCreatesALocaleWhenTheSourceGroupIsPermitted(): void
    {
        $tool = $this->multiGroupTool();
        $this->setUpGhostLocale(['de'], 'blog_article');

        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(fn (array $args) => $args[0]->with(new HandledStamp(new Article('uuid-1'), 'handler')));

        $result = $tool->updateArticle('uuid-1', 'en', 'English Title', 'blog_article', ['url' => '/english-article']);

        $this->assertTrue($result['created_locale']);
        $this->assertContains('sulu.article.articles_blog', $this->permissionChecker->checkedContexts());
    }

    private function multiGroupTool(): ArticleUpdateTool
    {
        $contextResolver = new ArticleSecurityContextResolver(new TestGroupProvider([
            (new FormGroup('default', 'Default'))->withTemplate('article'),
            (new FormGroup('blog', 'Blog'))->withTemplate('blog_article'),
        ]));

        $router = $this->prophesize(RouterInterface::class);
        $router->generate(Argument::cetera())->willReturn('https://example.com/admin/');

        return new ArticleUpdateTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            $this->articleRepository->reveal(),
            new BlockDataValidator($this->formMetadataProvider, new MetadataLocaleResolver(new TokenStorage(), 'en')),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider),
            new AdminLinkGenerator($router->reveal(), [new ArticleAdminLinkProvider(new TestViewRegistry())]),
            $this->articleGroupResolver,
            $this->permissionChecker,
            $contextResolver,
            new ContentSecurityContextResolver($contextResolver, $this->contentManager->reveal()),
        );
    }
}
