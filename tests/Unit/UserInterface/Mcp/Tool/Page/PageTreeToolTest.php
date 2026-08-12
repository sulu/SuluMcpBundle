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

use Doctrine\Common\Collections\ArrayCollection;
use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceCollection;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Component\Webspace\Webspace;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Mcp\Application\Security\AccessControlFilterFactory;
use Sulu\Mcp\Application\Security\ToolPermissionChecker;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\UserInterface\Mcp\Tool\Page\PageTreeTool;
use Sulu\Page\Domain\Model\PageDimensionContentInterface;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Sulu\Route\Domain\Model\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

#[CoversClass(PageTreeTool::class)]
final class PageTreeToolTest extends TestCase
{
    private PageRepositoryInterface&MockObject $pageRepository;
    private ContentManagerInterface&MockObject $contentManager;
    private WebspacePermissionResolver $webspacePermissionResolver;
    private PageTreeTool $tool;

    protected function setUp(): void
    {
        $this->pageRepository = $this->createMock(PageRepositoryInterface::class);
        $this->contentManager = $this->createMock(ContentManagerInterface::class);
        // Default: grants the '' webspace key returned by unstubbed PageInterface
        // mocks, so existing happy-path tests are unaffected by the new filter.
        $this->webspacePermissionResolver = $this->webspaceResolver(['example']);
        $this->tool = new PageTreeTool($this->pageRepository, $this->contentManager, $this->webspacePermissionResolver, new AccessControlFilterFactory(null, ['view' => 64, 'add' => 32, 'edit' => 16, 'delete' => 8, 'archive' => 4, 'live' => 2, 'security' => 1]));
    }

    /**
     * WebspacePermissionResolver is final, so this builds a real instance over
     * mocked WebspaceManagerInterface and SecurityCheckerInterface collaborators.
     *
     * @param list<string> $grantedWebspaceKeys webspace keys on which EDIT is granted
     */
    private function webspaceResolver(array $grantedWebspaceKeys): WebspacePermissionResolver
    {
        $webspaces = [];
        foreach ($grantedWebspaceKeys as $key) {
            $webspace = new Webspace();
            $webspace->setKey($key);
            $webspaces[$key] = $webspace;
        }

        $webspaceManager = $this->createMock(WebspaceManagerInterface::class);
        $webspaceManager->method('getWebspaceCollection')->willReturn(new WebspaceCollection($webspaces));

        $securityChecker = $this->createMock(SecurityCheckerInterface::class);
        $securityChecker->method('hasPermission')->willReturn(true);

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($this->createMock(UserInterface::class));
        $tokenStorage->method('getToken')->willReturn($token);

        return new WebspacePermissionResolver($webspaceManager, new ToolPermissionChecker($securityChecker, $tokenStorage));
    }

    public function testGetPageTreeReturnsTreeStructure(): void
    {
        $page = $this->createPageMock('uuid-1', 'Homepage', '/');

        $this->pageRepository->method('findByAsTree')->willReturn([$page]);
        $this->setupContentManagerForPage($page, 'Homepage', '/', 'homepage', 'published');

        $result = $this->tool->getPageTree('example', 'en');

        $this->assertSame('example', $result['webspace']);
        $this->assertSame('en', $result['locale']);
        $this->assertArrayHasKey('tree', $result);
        $this->assertCount(1, $result['tree']);
    }

    public function testGetPageTreeBuildsNodesWithRequiredFields(): void
    {
        $page = $this->createPageMock('uuid-1', 'Homepage', '/');

        $this->pageRepository->method('findByAsTree')->willReturn([$page]);
        $this->setupContentManagerForPage($page, 'Homepage', '/', 'homepage', 'published');

        $result = $this->tool->getPageTree('example', 'en');
        $node = $result['tree'][0];

        $this->assertSame('uuid-1', $node['uuid']);
        $this->assertSame('Homepage', $node['title']);
        $this->assertSame('/', $node['url']);
        $this->assertSame('homepage', $node['templateKey']);
        $this->assertFalse($node['hasChildren']);
        $this->assertNull($node['parentUuid']);
        $this->assertSame(0, $node['depth']);
        $this->assertSame('published', $node['workflowPlace']);
        $this->assertArrayHasKey('availableLocales', $node);
        $this->assertArrayHasKey('children', $node);
    }

    public function testGetPageTreeHandlesNestedChildren(): void
    {
        $parent = $this->createMock(PageInterface::class);
        $parent->method('getUuid')->willReturn('uuid-parent');
        $parent->method('getParent')->willReturn(null);

        $child = $this->createMock(PageInterface::class);
        $child->method('getUuid')->willReturn('uuid-child');
        $child->method('getChildren')->willReturn(new ArrayCollection([]));
        $child->method('getParent')->willReturn($parent);

        $parent->method('getChildren')->willReturn(new ArrayCollection([$child]));

        $this->pageRepository->method('findByAsTree')->willReturn([$parent]);

        $parentDimensionContent = $this->createDimensionContentMock('Homepage', '/', 'homepage', 'published');
        $childDimensionContent = $this->createDimensionContentMock('About Us', '/about', 'default', 'draft');

        $this->contentManager->method('resolve')
            ->willReturnCallback(function(PageInterface $page) use ($parent, $parentDimensionContent, $childDimensionContent) {
                if ($page === $parent) {
                    return $parentDimensionContent;
                }

                return $childDimensionContent;
            });

        $result = $this->tool->getPageTree('example', 'en');

        $this->assertCount(1, $result['tree']);
        $parentNode = $result['tree'][0];
        $this->assertTrue($parentNode['hasChildren']);
        $this->assertCount(1, $parentNode['children']);

        $childNode = $parentNode['children'][0];
        $this->assertSame('uuid-child', $childNode['uuid']);
        $this->assertSame('About Us', $childNode['title']);
        $this->assertSame(1, $childNode['depth']);
        $this->assertSame('uuid-parent', $childNode['parentUuid']);
    }

    public function testGetPageTreeReturnsEmptyTreeForEmptyWebspace(): void
    {
        $this->pageRepository->method('findByAsTree')->willReturn([]);

        $result = $this->tool->getPageTree('example', 'en');

        $this->assertSame([], $result['tree']);
    }

    public function testGetPageTreeMaxDepthStopsRecursionAtBoundary(): void
    {
        $grandchild = $this->createMock(PageInterface::class);
        $grandchild->method('getUuid')->willReturn('uuid-grandchild');
        $grandchild->method('getChildren')->willReturn(new ArrayCollection([]));
        $grandchild->method('getParent')->willReturn(null);

        $child = $this->createMock(PageInterface::class);
        $child->method('getUuid')->willReturn('uuid-child');
        $child->method('getChildren')->willReturn(new ArrayCollection([$grandchild]));
        $child->method('getParent')->willReturn(null);

        $parent = $this->createMock(PageInterface::class);
        $parent->method('getUuid')->willReturn('uuid-parent');
        $parent->method('getChildren')->willReturn(new ArrayCollection([$child]));
        $parent->method('getParent')->willReturn(null);

        $this->pageRepository->method('findByAsTree')->willReturn([$parent]);

        $parentDim = $this->createDimensionContentMock('Parent', '/', 'default', 'published');
        $childDim = $this->createDimensionContentMock('Child', '/child', 'default', 'published');

        $this->contentManager->method('resolve')
            ->willReturnCallback(function(PageInterface $page) use ($parent, $parentDim, $childDim) {
                if ($page === $parent) {
                    return $parentDim;
                }

                return $childDim;
            });

        $result = $this->tool->getPageTree('example', 'en', 1);

        $parentNode = $result['tree'][0];
        $this->assertTrue($parentNode['hasChildren']);
        $this->assertCount(1, $parentNode['children'], 'depth=0 is below maxDepth=1, so child should be included');

        $childNode = $parentNode['children'][0];
        $this->assertTrue($childNode['hasChildren'], 'child has children so hasChildren must be true');
        $this->assertSame([], $childNode['children'], 'grandchild must be omitted at maxDepth=1');
    }

    public function testGetPageTreeMaxDepthZeroReturnsOnlyRootPages(): void
    {
        $child = $this->createMock(PageInterface::class);
        $child->method('getUuid')->willReturn('uuid-child');
        $child->method('getChildren')->willReturn(new ArrayCollection([]));
        $child->method('getParent')->willReturn(null);

        $root = $this->createMock(PageInterface::class);
        $root->method('getUuid')->willReturn('uuid-root');
        $root->method('getChildren')->willReturn(new ArrayCollection([$child]));
        $root->method('getParent')->willReturn(null);

        $this->pageRepository->method('findByAsTree')->willReturn([$root]);

        $rootDim = $this->createDimensionContentMock('Root', '/', 'default', 'published');
        $this->contentManager->method('resolve')->willReturn($rootDim);

        $result = $this->tool->getPageTree('example', 'en', 0);

        $rootNode = $result['tree'][0];
        $this->assertTrue($rootNode['hasChildren'], 'root has children so hasChildren must be true');
        $this->assertSame([], $rootNode['children'], 'children must be empty at maxDepth=0');
    }

    public function testGetPageTreeWithoutMaxDepthReturnsFullNesting(): void
    {
        $grandchild = $this->createMock(PageInterface::class);
        $grandchild->method('getUuid')->willReturn('uuid-grandchild');
        $grandchild->method('getChildren')->willReturn(new ArrayCollection([]));
        $grandchild->method('getParent')->willReturn(null);

        $child = $this->createMock(PageInterface::class);
        $child->method('getUuid')->willReturn('uuid-child');
        $child->method('getChildren')->willReturn(new ArrayCollection([$grandchild]));
        $child->method('getParent')->willReturn(null);

        $root = $this->createMock(PageInterface::class);
        $root->method('getUuid')->willReturn('uuid-root');
        $root->method('getChildren')->willReturn(new ArrayCollection([$child]));
        $root->method('getParent')->willReturn(null);

        $this->pageRepository->method('findByAsTree')->willReturn([$root]);

        $dim = $this->createDimensionContentMock('Page', '/', 'default', 'published');
        $this->contentManager->method('resolve')->willReturn($dim);

        $result = $this->tool->getPageTree('example', 'en');

        $rootNode = $result['tree'][0];
        $this->assertCount(1, $rootNode['children']);
        $this->assertCount(1, $rootNode['children'][0]['children'], 'grandchild must be present when no maxDepth');
    }

    public function testGetPageTreeMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(PageTreeTool::class, 'getPageTree');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes, 'getPageTree() method must have exactly one #[McpTool] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_page_tree', $instance->name);
    }

    public function testGetPageTreeReturnsEmptyTreeWhenNoWebspaceIsPermitted(): void
    {
        $tool = new PageTreeTool($this->pageRepository, $this->contentManager, $this->webspaceResolver([]), new AccessControlFilterFactory(null, ['view' => 64, 'add' => 32, 'edit' => 16, 'delete' => 8, 'archive' => 4, 'live' => 2, 'security' => 1]));

        $this->pageRepository->expects($this->never())->method('findByAsTree');

        $result = $tool->getPageTree('example', 'en');

        $this->assertSame('example', $result['webspace']);
        $this->assertSame([], $result['tree']);
        $this->assertArrayHasKey('hint', $result);
    }

    public function testGetPageTreeReturnsEmptyTreeWhenRequestedWebspaceIsNotPermitted(): void
    {
        $tool = new PageTreeTool($this->pageRepository, $this->contentManager, $this->webspaceResolver(['other']), new AccessControlFilterFactory(null, ['view' => 64, 'add' => 32, 'edit' => 16, 'delete' => 8, 'archive' => 4, 'live' => 2, 'security' => 1]));

        $this->pageRepository->expects($this->never())->method('findByAsTree');

        $result = $tool->getPageTree('example', 'en');

        $this->assertSame([], $result['tree']);
        $this->assertStringContainsString('example', (string) $result['hint']);
    }

    /**
     * The tree must be scoped in the query; filtering roots afterwards let a request
     * for one webspace return another webspace's tree under the requested label.
     */
    public function testGetPageTreeScopesQueryToRequestedWebspace(): void
    {
        $this->pageRepository
            ->expects($this->once())
            ->method('findByAsTree')
            ->with(
                $this->callback(static fn (array $filters): bool => 'example' === ($filters['webspaceKey'] ?? null)),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn([]);

        $this->tool->getPageTree('example', 'en');
    }

    /**
     * @param PageInterface[] $children
     */
    private function createPageMock(string $uuid, string $title, string $url, array $children = []): PageInterface&MockObject
    {
        $page = $this->createMock(PageInterface::class);
        $page->method('getUuid')->willReturn($uuid);
        $page->method('getChildren')->willReturn(new ArrayCollection($children));
        $page->method('getParent')->willReturn(null);

        return $page;
    }

    private function createDimensionContentMock(
        string $title,
        string $slug,
        string $templateKey,
        string $workflowPlace,
    ): PageDimensionContentInterface&MockObject {
        $dimensionContent = $this->createMock(PageDimensionContentInterface::class);
        $dimensionContent->method('getTitle')->willReturn($title);
        $dimensionContent->method('getTemplateKey')->willReturn($templateKey);
        $dimensionContent->method('getWorkflowPlace')->willReturn($workflowPlace);
        $dimensionContent->method('getAvailableLocales')->willReturn(['en']);

        $route = $this->createMock(Route::class);
        $route->method('getSlug')->willReturn($slug);
        $dimensionContent->method('getRoute')->willReturn($route);

        return $dimensionContent;
    }

    private function setupContentManagerForPage(
        PageInterface $page,
        string $title,
        string $slug,
        string $templateKey,
        string $workflowPlace,
    ): void {
        $dimensionContent = $this->createDimensionContentMock($title, $slug, $templateKey, $workflowPlace);
        $this->contentManager->method('resolve')->with($page, $this->anything())->willReturn($dimensionContent);
    }
}
