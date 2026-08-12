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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sulu\Article\Domain\Model\ArticleInterface;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Bundle\AdminBundle\Metadata\GroupProviderInterface;
use Sulu\Bundle\PreviewBundle\Application\Manager\PreviewLinkManagerInterface;
use Sulu\Bundle\PreviewBundle\Domain\Model\PreviewLinkInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\TemplateInterface;
use Sulu\Mcp\Application\Content\ContentTypeResolver;
use Sulu\Mcp\Application\Security\ContentSecurityContextResolver;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\UserInterface\Mcp\Tool\Preview\PreviewLinkGenerateTool;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

#[CoversClass(PreviewLinkGenerateTool::class)]
final class PreviewLinkGenerateToolTest extends TestCase
{
    private PreviewLinkManagerInterface&MockObject $previewLinkManager;
    private RouterInterface&MockObject $router;
    private PageRepositoryInterface&MockObject $pageRepository;
    private ArticleRepositoryInterface&MockObject $articleRepository;
    private ContentManagerInterface&MockObject $contentManager;
    private ToolPermissionCheckerInterface&MockObject $permissionChecker;
    private PreviewLinkGenerateTool $tool;

    protected function setUp(): void
    {
        $this->previewLinkManager = $this->createMock(PreviewLinkManagerInterface::class);
        $this->router = $this->createMock(RouterInterface::class);
        $this->pageRepository = $this->createMock(PageRepositoryInterface::class);
        $this->articleRepository = $this->createMock(ArticleRepositoryInterface::class);
        $this->contentManager = $this->createMock(ContentManagerInterface::class);
        $this->permissionChecker = $this->createMock(ToolPermissionCheckerInterface::class);
        $groupProvider = $this->createMock(GroupProviderInterface::class);
        $groupProvider->method('getGroups')->willReturn([]);

        $snippetRepository = $this->createMock(SnippetRepositoryInterface::class);

        $this->tool = new PreviewLinkGenerateTool(
            $this->previewLinkManager,
            $this->router,
            new ContentTypeResolver($this->pageRepository, $this->articleRepository, $snippetRepository),
            $this->contentManager,
            $this->permissionChecker,
            new ContentSecurityContextResolver(new ArticleSecurityContextResolver($groupProvider)),
        );
    }

    private function setupEntity(string $type): void
    {
        $entity = $this->createMock('page' === $type ? PageInterface::class : ArticleInterface::class);

        if ('page' === $type) {
            $entity->method('getWebspaceKey')->willReturn('example');
            $this->pageRepository->method('getOneBy')->willReturn($entity);
        } else {
            $this->articleRepository->method('getOneBy')->willReturn($entity);
            $dimensionContent = $this->createMockForIntersectionOfInterfaces([TemplateInterface::class, DimensionContentInterface::class]);
            $dimensionContent->method('getTemplateKey')->willReturn('default');
            $this->contentManager->method('resolve')->willReturn($dimensionContent);
        }
    }

    public function testGeneratePreviewLinkForPage(): void
    {
        $this->setupEntity('page');

        $previewLink = $this->createMock(PreviewLinkInterface::class);
        $previewLink->method('getToken')->willReturn('abc123');
        $previewLink->method('getResourceKey')->willReturn('pages');
        $previewLink->method('getResourceId')->willReturn('page-uuid-1');
        $previewLink->method('getLocale')->willReturn('en');

        $this->previewLinkManager
            ->expects($this->once())
            ->method('generate')
            ->with('pages', 'page-uuid-1', 'en', ['webspaceKey' => 'example'])
            ->willReturn($previewLink);

        $this->router
            ->expects($this->once())
            ->method('generate')
            ->with('sulu_preview.public_preview', ['token' => 'abc123'], UrlGeneratorInterface::ABSOLUTE_URL)
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

        $previewLink = $this->createMock(PreviewLinkInterface::class);
        $previewLink->method('getToken')->willReturn('def456');
        $previewLink->method('getResourceKey')->willReturn('articles');
        $previewLink->method('getResourceId')->willReturn('article-uuid-1');
        $previewLink->method('getLocale')->willReturn('de');

        $this->previewLinkManager
            ->expects($this->once())
            ->method('generate')
            ->with('articles', 'article-uuid-1', 'de', ['webspaceKey' => 'sulu'])
            ->willReturn($previewLink);

        $this->router
            ->expects($this->once())
            ->method('generate')
            ->with('sulu_preview.public_preview', ['token' => 'def456'], UrlGeneratorInterface::ABSOLUTE_URL)
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

        $previewLink = $this->createMock(PreviewLinkInterface::class);
        $previewLink->method('getToken')->willReturn('tok');
        $previewLink->method('getResourceKey')->willReturn('articles');
        $previewLink->method('getResourceId')->willReturn('article-uuid-1');
        $previewLink->method('getLocale')->willReturn('en');

        $capturedResourceKey = null;
        $this->previewLinkManager
            ->expects($this->once())
            ->method('generate')
            ->willReturnCallback(function(string $resourceKey) use (&$capturedResourceKey, $previewLink) {
                $capturedResourceKey = $resourceKey;

                return $previewLink;
            });

        $this->router->method('generate')->willReturn('https://example.com/preview/tok');

        $this->tool->generatePreviewLink('article', 'article-uuid-1', 'en', 'example');

        $this->assertSame('articles', $capturedResourceKey, 'Singular "article" must be mapped to plural "articles" before calling the manager.');
    }

    public function testGeneratePreviewLinkRejectsMissingWebspace(): void
    {
        $this->previewLinkManager->expects($this->never())->method('generate');

        $result = $this->tool->generatePreviewLink('article', 'article-uuid-1', 'en');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('webspace', $result['error']);
        $this->assertArrayNotHasKey('success', $result);
    }

    public function testGeneratePreviewLinkPassesWebspaceInOptions(): void
    {
        $this->setupEntity('page');

        $previewLink = $this->createMock(PreviewLinkInterface::class);
        $previewLink->method('getToken')->willReturn('tok');

        $this->previewLinkManager
            ->expects($this->once())
            ->method('generate')
            ->with('pages', 'uuid-1', 'en', ['webspaceKey' => 'example'])
            ->willReturn($previewLink);

        $this->router->method('generate')->willReturn('https://example.com/preview/tok');

        $this->tool->generatePreviewLink('page', 'uuid-1', 'en', 'example');
    }

    /**
     * The stored token is rendered under this webspace's portal, theme and routes, so
     * it must be the page's own -- not any webspace the caller cares to name.
     */
    public function testGeneratePreviewLinkRejectsForeignRenderingWebspace(): void
    {
        $this->setupEntity('page');

        $this->previewLinkManager->expects($this->never())->method('generate');

        $this->expectException(ToolCallException::class);

        $this->tool->generatePreviewLink('page', 'uuid-1', 'en', 'other-webspace');
    }

    public function testGeneratePreviewLinkReturnsErrorOnException(): void
    {
        $this->setupEntity('page');

        $this->previewLinkManager
            ->method('generate')
            ->willThrowException(new \RuntimeException('Resource not found'));

        $result = $this->tool->generatePreviewLink('page', 'bad-uuid', 'en', 'example');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Resource not found', $result['error']);
        $this->assertArrayHasKey('hint', $result);
    }

    public function testEntityNotFoundReturnsErrorWithoutGenerate(): void
    {
        $this->pageRepository->method('getOneBy')->willThrowException(new \RuntimeException('not found'));
        $this->previewLinkManager->expects($this->never())->method('generate');

        $result = $this->tool->generatePreviewLink('page', 'missing-uuid', 'en', 'example');

        $this->assertArrayHasKey('error', $result);
    }

    public function testGeneratePreviewLinkThrowsToolCallExceptionWhenPermissionDenied(): void
    {
        $this->setupEntity('page');

        $this->permissionChecker
            ->method('check')
            ->willThrowException(new PermissionDeniedException('sulu.webspaces.example', PermissionTypes::EDIT, 'en'));

        $this->previewLinkManager->expects($this->never())->method('generate');

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
