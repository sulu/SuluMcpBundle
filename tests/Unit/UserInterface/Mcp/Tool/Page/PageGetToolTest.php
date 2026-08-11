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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\Page;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\UserInterface\Mcp\Tool\Page\PageGetTool;
use Sulu\Page\Domain\Exception\PageNotFoundException;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;

#[CoversClass(PageGetTool::class)]
final class PageGetToolTest extends TestCase
{
    private PageRepositoryInterface&MockObject $pageRepository;
    private ContentManagerInterface&MockObject $contentManager;
    private ToolPermissionCheckerInterface&MockObject $permissionChecker;
    private PageGetTool $tool;

    protected function setUp(): void
    {
        $this->pageRepository = $this->createMock(PageRepositoryInterface::class);
        $this->contentManager = $this->createMock(ContentManagerInterface::class);
        $this->permissionChecker = $this->createMock(ToolPermissionCheckerInterface::class);
        $this->tool = new PageGetTool($this->pageRepository, $this->contentManager, $this->permissionChecker);
    }

    public function testGetPageReturnsNormalizedContent(): void
    {
        $page = $this->createMock(PageInterface::class);
        $page->method('getUuid')->willReturn('test-uuid-123');
        $page->method('getWebspaceKey')->willReturn('example');

        $dimensionContent = $this->createMock(DimensionContentInterface::class);
        $normalizedData = ['title' => 'Test Page', 'template' => 'default'];

        $this->pageRepository->method('getOneBy')->willReturn($page);
        $this->contentManager->method('resolve')->willReturn($dimensionContent);
        $this->contentManager->method('normalize')->willReturn($normalizedData);

        $result = $this->tool->getPage('example', 'en', 'test-uuid-123');

        $this->assertSame('test-uuid-123', $result['uuid']);
        $this->assertSame('example', $result['webspace']);
        $this->assertSame('en', $result['locale']);
        $this->assertSame($normalizedData, $result['data']);
    }

    public function testGetPagePassesCorrectFiltersToRepository(): void
    {
        $page = $this->createMock(PageInterface::class);
        $page->method('getUuid')->willReturn('my-uuid');
        $dimensionContent = $this->createMock(DimensionContentInterface::class);

        $this->pageRepository
            ->expects($this->once())
            ->method('getOneBy')
            ->with(
                [
                    'uuid' => 'my-uuid',
                    'locale' => 'de',
                    'stage' => DimensionContentInterface::STAGE_DRAFT,
                ],
                [
                    PageRepositoryInterface::GROUP_SELECT_PAGE_ADMIN => true,
                ],
            )
            ->willReturn($page);

        $this->contentManager->method('resolve')->willReturn($dimensionContent);
        $this->contentManager->method('normalize')->willReturn([]);

        $this->tool->getPage('example', 'de', 'my-uuid');
    }

    public function testGetPageUsesContentManagerToResolveAndNormalize(): void
    {
        $page = $this->createMock(PageInterface::class);
        $page->method('getUuid')->willReturn('uuid-1');
        $dimensionContent = $this->createMock(DimensionContentInterface::class);

        $this->pageRepository->method('getOneBy')->willReturn($page);

        $this->contentManager
            ->expects($this->once())
            ->method('resolve')
            ->with($page, [
                'locale' => 'en',
                'stage' => DimensionContentInterface::STAGE_DRAFT,
            ])
            ->willReturn($dimensionContent);

        $this->contentManager
            ->expects($this->once())
            ->method('normalize')
            ->with($dimensionContent)
            ->willReturn(['title' => 'Test']);

        $this->tool->getPage('example', 'en', 'uuid-1');
    }

    public function testGetPageReturnsErrorForMissingPage(): void
    {
        $this->pageRepository
            ->method('getOneBy')
            ->willThrowException(new PageNotFoundException(['uuid' => 'missing-uuid']));

        $result = $this->tool->getPage('example', 'en', 'missing-uuid');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('missing-uuid', $result['error']);
        $this->assertTrue(\array_key_exists('hint', $result));
        $this->assertIsString($result['hint']);
        $this->assertNotEmpty($result['hint']);
    }

    public function testGetIncludesSeoAndExcerpt(): void
    {
        $page = $this->createMock(PageInterface::class);
        $page->method('getUuid')->willReturn('uuid-seo');

        $dimensionContent = $this->createMock(DimensionContentInterface::class);
        $normalizedData = [
            'title' => 'SEO Page',
            'seo' => ['title' => 'X'],
            'seoNoIndex' => true,
            'excerpt' => ['title' => 'Y'],
        ];

        $this->pageRepository->method('getOneBy')->willReturn($page);
        $this->contentManager->method('resolve')->willReturn($dimensionContent);
        $this->contentManager->method('normalize')->willReturn($normalizedData);

        $result = $this->tool->getPage('example', 'en', 'uuid-seo');

        $this->assertArrayHasKey('seo', $result['data']);
        $this->assertArrayHasKey('excerpt', $result['data']);
        $this->assertSame(true, $result['data']['seoNoIndex']);
    }

    public function testGetPageMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(PageGetTool::class, 'getPage');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes, 'getPage() method must have exactly one #[McpTool] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_page_get', $instance->name);
    }

    public function testGetPagePassesConcretePageClassAsObjectType(): void
    {
        // Regression guard: Sulu stores per-page ACLs under the concrete Page class
        // (getSecuredClass()), not PageInterface — the interface matches no ACL row and
        // silently falls back to the webspace-level grant.
        $page = $this->createMock(PageInterface::class);
        $page->method('getUuid')->willReturn('test-uuid-123');
        $page->method('getWebspaceKey')->willReturn('example');

        $this->pageRepository->method('getOneBy')->willReturn($page);

        $dimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->method('resolve')->willReturn($dimensionContent);
        $this->contentManager->method('normalize')->willReturn([]);

        $this->permissionChecker
            ->expects($this->once())
            ->method('check')
            ->with(
                'sulu.webspaces.example',
                PermissionTypes::VIEW,
                'en',
                Page::class,
                'test-uuid-123',
            );

        $this->tool->getPage('example', 'en', 'test-uuid-123');
    }

    public function testGetPageThrowsToolCallExceptionWhenPermissionDenied(): void
    {
        $page = $this->createMock(PageInterface::class);
        $page->method('getUuid')->willReturn('test-uuid-123');
        $page->method('getWebspaceKey')->willReturn('example');

        $this->pageRepository->method('getOneBy')->willReturn($page);

        $this->permissionChecker
            ->method('check')
            ->willThrowException(new PermissionDeniedException('sulu.webspaces.example', PermissionTypes::VIEW, 'en'));

        $this->expectException(ToolCallException::class);

        $this->tool->getPage('example', 'en', 'test-uuid-123');
    }
}
