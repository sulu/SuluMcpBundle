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
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\TemplateInterface;
use Sulu\Mcp\Application\Content\ContentTypeResolver;
use Sulu\Mcp\Application\Security\ContentSecurityContextResolver;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\UserInterface\Mcp\Tool\Preview\PreviewLinkRevokeTool;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;

#[CoversClass(PreviewLinkRevokeTool::class)]
final class PreviewLinkRevokeToolTest extends TestCase
{
    private PreviewLinkManagerInterface&MockObject $previewLinkManager;
    private PageRepositoryInterface&MockObject $pageRepository;
    private ArticleRepositoryInterface&MockObject $articleRepository;
    private ContentManagerInterface&MockObject $contentManager;
    private ToolPermissionCheckerInterface&MockObject $permissionChecker;
    private PreviewLinkRevokeTool $tool;

    protected function setUp(): void
    {
        $this->previewLinkManager = $this->createMock(PreviewLinkManagerInterface::class);
        $this->pageRepository = $this->createMock(PageRepositoryInterface::class);
        $this->articleRepository = $this->createMock(ArticleRepositoryInterface::class);
        $this->contentManager = $this->createMock(ContentManagerInterface::class);
        $this->permissionChecker = $this->createMock(ToolPermissionCheckerInterface::class);
        $groupProvider = $this->createMock(GroupProviderInterface::class);
        $groupProvider->method('getGroups')->willReturn([]);

        $snippetRepository = $this->createMock(SnippetRepositoryInterface::class);

        $this->tool = new PreviewLinkRevokeTool(
            $this->previewLinkManager,
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

    public function testRevokePreviewLinkSuccess(): void
    {
        $this->setupEntity('page');

        $this->previewLinkManager
            ->expects($this->once())
            ->method('revoke')
            ->with('pages', 'page-uuid-1', 'en');

        $result = $this->tool->revokePreviewLink('page', 'page-uuid-1', 'en');

        $this->assertTrue($result['success']);
        $this->assertSame('revoked', $result['action']);
        $this->assertSame('pages', $result['resourceKey']);
        $this->assertSame('page-uuid-1', $result['resourceId']);
        $this->assertSame('en', $result['locale']);
    }

    public function testTypeIsMappedToResourceKeyForRevoke(): void
    {
        $this->setupEntity('article');

        $capturedResourceKey = null;
        $this->previewLinkManager
            ->expects($this->once())
            ->method('revoke')
            ->willReturnCallback(function(string $resourceKey) use (&$capturedResourceKey): void {
                $capturedResourceKey = $resourceKey;
            });

        $this->tool->revokePreviewLink('article', 'article-uuid-1', 'en');

        $this->assertSame('articles', $capturedResourceKey, 'Singular "article" must be mapped to plural "articles" before calling the manager.');
    }

    public function testRevokePreviewLinkReturnsErrorOnException(): void
    {
        $this->setupEntity('page');

        $this->previewLinkManager
            ->method('revoke')
            ->willThrowException(new \RuntimeException('No preview link found'));

        $result = $this->tool->revokePreviewLink('page', 'bad-uuid', 'en');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('No preview link found', $result['error']);
        $this->assertArrayHasKey('hint', $result);
    }

    public function testEntityNotFoundReturnsErrorWithoutRevoke(): void
    {
        $this->pageRepository->method('getOneBy')->willThrowException(new \RuntimeException('not found'));
        $this->previewLinkManager->expects($this->never())->method('revoke');

        $result = $this->tool->revokePreviewLink('page', 'missing-uuid', 'en');

        $this->assertArrayHasKey('error', $result);
    }

    public function testRevokePreviewLinkThrowsToolCallExceptionWhenPermissionDenied(): void
    {
        $this->setupEntity('page');

        $this->permissionChecker
            ->method('check')
            ->willThrowException(new PermissionDeniedException('sulu.webspaces.example', PermissionTypes::EDIT, 'en'));

        $this->previewLinkManager->expects($this->never())->method('revoke');

        $this->expectException(ToolCallException::class);

        $this->tool->revokePreviewLink('page', 'page-uuid-1', 'en');
    }

    public function testMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(PreviewLinkRevokeTool::class, 'revokePreviewLink');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes, 'revokePreviewLink() method must have exactly one #[McpTool] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_preview_link_revoke', $instance->name);
    }

    public function testTypeParameterHasSchemaAttributeWithSingularEnum(): void
    {
        $reflection = new \ReflectionMethod(PreviewLinkRevokeTool::class, 'revokePreviewLink');
        $parameter = $reflection->getParameters()[0];
        $this->assertSame('type', $parameter->getName());

        $attributes = $parameter->getAttributes(Schema::class);
        $this->assertCount(1, $attributes);

        $schema = $attributes[0]->newInstance();
        $this->assertSame(['page', 'article'], $schema->enum);
    }
}
