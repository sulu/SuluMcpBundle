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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\Preview;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Article\Domain\Model\Article;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Bundle\PreviewBundle\Application\Manager\PreviewLinkManagerInterface;
use Sulu\Bundle\PreviewBundle\Domain\Model\PreviewLink;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Mcp\Application\Content\ContentTypeResolver;
use Sulu\Mcp\Application\Security\ContentSecurityContextResolver;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\Tests\Application\TestBundle\Metadata\TestGroupProvider;
use Sulu\Mcp\Tests\Unit\Fixture\FakeToolPermissionChecker;
use Sulu\Mcp\UserInterface\Mcp\Tool\Preview\PreviewLinkGenerateTool;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Model\PageDimensionContent;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

#[CoversClass(PreviewLinkGenerateTool::class)]
final class PreviewLinkGenerateToolTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<PreviewLinkManagerInterface> */
    private ObjectProphecy $previewLinkManager;

    /** @var ObjectProphecy<RouterInterface> */
    private ObjectProphecy $router;

    /** @var ObjectProphecy<PageRepositoryInterface> */
    private ObjectProphecy $pageRepository;

    /** @var ObjectProphecy<ArticleRepositoryInterface> */
    private ObjectProphecy $articleRepository;

    /** @var ObjectProphecy<ContentManagerInterface> */
    private ObjectProphecy $contentManager;

    private FakeToolPermissionChecker $permissionChecker;
    private PreviewLinkGenerateTool $tool;

    protected function setUp(): void
    {
        $this->previewLinkManager = $this->prophesize(PreviewLinkManagerInterface::class);
        $this->router = $this->prophesize(RouterInterface::class);
        $this->pageRepository = $this->prophesize(PageRepositoryInterface::class);
        $this->articleRepository = $this->prophesize(ArticleRepositoryInterface::class);
        $this->contentManager = $this->prophesize(ContentManagerInterface::class);
        $this->permissionChecker = FakeToolPermissionChecker::grantingAll();
        $groupProvider = new TestGroupProvider([]);

        $snippetRepository = $this->prophesize(SnippetRepositoryInterface::class);

        $this->tool = new PreviewLinkGenerateTool(
            $this->previewLinkManager->reveal(),
            $this->router->reveal(),
            new ContentTypeResolver($this->pageRepository->reveal(), $this->articleRepository->reveal(), $snippetRepository->reveal()),
            $this->contentManager->reveal(),
            $this->permissionChecker,
            new ContentSecurityContextResolver(new ArticleSecurityContextResolver($groupProvider), $this->contentManager->reveal()),
        );
    }

    private function setupEntity(string $type): void
    {
        if ('page' === $type) {
            $page = new Page('page-uuid-1');
            $page->setWebspaceKey('example');
            $this->pageRepository->getOneBy(Argument::cetera())->willReturn($page);
        } else {
            $article = new Article('article-uuid-1');
            $this->articleRepository->getOneBy(Argument::cetera())->willReturn($article);
            $dimensionContent = new PageDimensionContent(new Page());
            $dimensionContent->setTemplateKey('default');
            $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        }
    }

    public function testGeneratePreviewLinkForPage(): void
    {
        $this->setupEntity('page');

        $previewLink = new PreviewLink('abc123', 'pages', 'page-uuid-1', 'en', ['webspaceKey' => 'example']);

        $this->previewLinkManager
            ->generate('pages', 'page-uuid-1', 'en', ['webspaceKey' => 'example'])
            ->shouldBeCalledOnce()
            ->willReturn($previewLink);

        $this->router
            ->generate('sulu_preview.public_preview', ['token' => 'abc123'], UrlGeneratorInterface::ABSOLUTE_URL)
            ->shouldBeCalledOnce()
            ->willReturn('https://example.com/preview/abc123');

        $result = $this->tool->generatePreviewLink('page', 'page-uuid-1', 'en', 'example');

        $this->assertTrue($result['success']);
        $this->assertSame('https://example.com/preview/abc123', $result['preview_url']);
        $this->assertSame('abc123', $result['token']);
        $this->assertSame('pages', $result['resourceKey']);
        $this->assertSame('page-uuid-1', $result['resourceId']);
        $this->assertSame('en', $result['locale']);
    }

    public function testGeneratePreviewLinkForArticle(): void
    {
        $this->setupEntity('article');

        $previewLink = new PreviewLink('def456', 'articles', 'article-uuid-1', 'de', ['webspaceKey' => 'sulu']);

        $this->previewLinkManager
            ->generate('articles', 'article-uuid-1', 'de', ['webspaceKey' => 'sulu'])
            ->shouldBeCalledOnce()
            ->willReturn($previewLink);

        $this->router
            ->generate('sulu_preview.public_preview', ['token' => 'def456'], UrlGeneratorInterface::ABSOLUTE_URL)
            ->shouldBeCalledOnce()
            ->willReturn('https://example.com/preview/def456');

        $result = $this->tool->generatePreviewLink('article', 'article-uuid-1', 'de', 'sulu');

        $this->assertTrue($result['success']);
        $this->assertSame('https://example.com/preview/def456', $result['preview_url']);
        $this->assertSame('def456', $result['token']);
        $this->assertSame('articles', $result['resourceKey']);
        $this->assertSame('article-uuid-1', $result['resourceId']);
        $this->assertSame('de', $result['locale']);
    }

    public function testTypeIsMappedToResourceKeyForGenerate(): void
    {
        $this->setupEntity('article');

        $previewLink = new PreviewLink('tok', 'articles', 'article-uuid-1', 'en', ['webspaceKey' => 'example']);

        $capturedResourceKey = null;
        $this->previewLinkManager
            ->generate(Argument::cetera())
            ->will(function(array $args) use (&$capturedResourceKey, $previewLink) {
                $capturedResourceKey = $args[0];

                return $previewLink;
            });

        $this->router->generate(Argument::cetera())->willReturn('https://example.com/preview/tok');

        $this->tool->generatePreviewLink('article', 'article-uuid-1', 'en', 'example');

        $this->assertSame('articles', $capturedResourceKey, 'Singular "article" must be mapped to plural "articles" before calling the manager.');
    }

    public function testGeneratePreviewLinkRejectsMissingWebspace(): void
    {
        $this->previewLinkManager->generate(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->generatePreviewLink('article', 'article-uuid-1', 'en');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('webspace', $result['error']);
        $this->assertArrayNotHasKey('success', $result);
    }

    public function testGeneratePreviewLinkPassesWebspaceInOptions(): void
    {
        $this->setupEntity('page');

        $previewLink = new PreviewLink('tok', 'pages', 'uuid-1', 'en', ['webspaceKey' => 'example']);

        $this->previewLinkManager
            ->generate('pages', 'uuid-1', 'en', ['webspaceKey' => 'example'])
            ->shouldBeCalledOnce()
            ->willReturn($previewLink);

        $this->router->generate(Argument::cetera())->willReturn('https://example.com/preview/tok');

        $this->tool->generatePreviewLink('page', 'uuid-1', 'en', 'example');
    }

    /**
     * The stored token is rendered under this webspace's portal, theme and routes, so
     * it must be the page's own -- not any webspace the caller cares to name.
     */
    public function testGeneratePreviewLinkRejectsForeignRenderingWebspace(): void
    {
        $this->setupEntity('page');

        $this->previewLinkManager->generate(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(ToolCallException::class);

        $this->tool->generatePreviewLink('page', 'uuid-1', 'en', 'other-webspace');
    }

    public function testGeneratePreviewLinkReturnsErrorOnException(): void
    {
        $this->setupEntity('page');

        $this->previewLinkManager
            ->generate(Argument::cetera())
            ->willThrow(new \RuntimeException('Resource not found'));

        $result = $this->tool->generatePreviewLink('page', 'bad-uuid', 'en', 'example');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Resource not found', $result['error']);
        $this->assertArrayHasKey('hint', $result);
    }

    public function testEntityNotFoundReturnsErrorWithoutGenerate(): void
    {
        $this->pageRepository->getOneBy(Argument::cetera())->willThrow(new \RuntimeException('not found'));
        $this->previewLinkManager->generate(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->generatePreviewLink('page', 'missing-uuid', 'en', 'example');

        $this->assertArrayHasKey('error', $result);
    }

    public function testGeneratePreviewLinkThrowsToolCallExceptionWhenPermissionDenied(): void
    {
        $this->setupEntity('page');

        $this->permissionChecker->denyAll();

        $this->previewLinkManager->generate(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(ToolCallException::class);

        $this->tool->generatePreviewLink('page', 'page-uuid-1', 'en', 'example');
    }

    public function testMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(PreviewLinkGenerateTool::class, 'generatePreviewLink');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes, 'generatePreviewLink() method must have exactly one #[McpTool] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_preview_link_generate', $instance->name);
    }

    public function testTypeParameterHasSchemaAttributeWithSingularEnum(): void
    {
        $reflection = new \ReflectionMethod(PreviewLinkGenerateTool::class, 'generatePreviewLink');
        $parameter = $reflection->getParameters()[0];
        $this->assertSame('type', $parameter->getName());

        $attributes = $parameter->getAttributes(Schema::class);
        $this->assertCount(1, $attributes);

        $schema = $attributes[0]->newInstance();
        $this->assertSame(['page', 'article'], $schema->enum);
    }
}
