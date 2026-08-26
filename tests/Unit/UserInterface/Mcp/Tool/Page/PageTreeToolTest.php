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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceCollection;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Component\Webspace\Webspace;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Mcp\Application\Security\AccessControlFilterFactory;
use Sulu\Mcp\Application\Security\ToolPermissionChecker;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Tests\Unit\Fixture\TestUser;
use Sulu\Mcp\UserInterface\Mcp\Tool\Page\PageTreeTool;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Model\PageDimensionContent;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Sulu\Route\Domain\Model\Route;

#[CoversClass(PageTreeTool::class)]
final class PageTreeToolTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<PageRepositoryInterface> */
    private ObjectProphecy $pageRepository;

    /** @var ObjectProphecy<ContentManagerInterface> */
    private ObjectProphecy $contentManager;

    private WebspacePermissionResolver $webspacePermissionResolver;
    private PageTreeTool $tool;

    protected function setUp(): void
    {
        $this->pageRepository = $this->prophesize(PageRepositoryInterface::class);
        $this->contentManager = $this->prophesize(ContentManagerInterface::class);
        // Default: grants the '' webspace key returned by unstubbed PageInterface
        // mocks, so existing happy-path tests are unaffected by the new filter.
        $this->webspacePermissionResolver = $this->webspaceResolver(['example']);
        $this->tool = new PageTreeTool(
            $this->pageRepository->reveal(),
            $this->contentManager->reveal(),
            $this->webspacePermissionResolver,
            new AccessControlFilterFactory(null, ['view' => 64, 'add' => 32, 'edit' => 16, 'delete' => 8, 'archive' => 4, 'live' => 2, 'security' => 1]),
        );
    }

    /**
     * WebspacePermissionResolver is final, so this builds a real instance over
     * prophesized WebspaceManagerInterface and SecurityCheckerInterface collaborators.
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

        $webspaceManager = $this->prophesize(WebspaceManagerInterface::class);
        $webspaceManager->getWebspaceCollection()->willReturn(new WebspaceCollection($webspaces));

        $securityChecker = $this->prophesize(SecurityCheckerInterface::class);
        $securityChecker->hasPermission(Argument::cetera())->willReturn(true);

        $tokenStorage = (new TestUser())->inTokenStorage();

        return new WebspacePermissionResolver($webspaceManager->reveal(), new ToolPermissionChecker($securityChecker->reveal(), $tokenStorage));
    }

    public function testGetPageTreeReturnsTreeStructure(): void
    {
        $page = $this->createPage('uuid-1', 'Homepage', '/');

        $this->pageRepository->findByAsTree(Argument::cetera())->willReturn([$page]);
        $this->setupContentManagerForPage($page, 'Homepage', '/', 'homepage', 'published');

        $result = $this->tool->getPageTree('example', 'en');

        $this->assertSame('example', $result['webspace']);
        $this->assertSame('en', $result['locale']);
        $this->assertArrayHasKey('tree', $result);
        $this->assertCount(1, $result['tree']);
    }

    public function testGetPageTreeBuildsNodesWithRequiredFields(): void
    {
        $page = $this->createPage('uuid-1', 'Homepage', '/');

        $this->pageRepository->findByAsTree(Argument::cetera())->willReturn([$page]);
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
        $parent = $this->createPage('uuid-parent', 'Homepage', '/');
        $child = $this->createPage('uuid-child', 'About Us', '/about');
        $child->setParent($parent);
        $parent->addChild($child);

        $this->pageRepository->findByAsTree(Argument::cetera())->willReturn([$parent]);

        $parentDimensionContent = $this->createDimensionContent($parent, 'Homepage', '/', 'homepage', 'published');
        $childDimensionContent = $this->createDimensionContent($child, 'About Us', '/about', 'default', 'draft');

        $this->contentManager->resolve($parent, Argument::cetera())->willReturn($parentDimensionContent);
        $this->contentManager->resolve($child, Argument::cetera())->willReturn($childDimensionContent);

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

    public function testGetPageTreeNumbersSiblingsFromOneAtEveryLevel(): void
    {
        // The number sulu_page_reorder takes as input.
        $firstChild = $this->createPage('uuid-child-1', 'First', '/first');
        $secondChild = $this->createPage('uuid-child-2', 'Second', '/second');

        $parent = $this->createPage('uuid-parent', 'Homepage', '/');
        foreach ([$firstChild, $secondChild] as $child) {
            $parent->addChild($child);
            $child->setParent($parent);
        }

        $this->pageRepository->findByAsTree(Argument::cetera())->willReturn([$parent]);

        $this->contentManager->resolve($parent, Argument::cetera())
            ->willReturn($this->createDimensionContent($parent, 'Homepage', '/', 'homepage', 'published'));
        $this->contentManager->resolve($firstChild, Argument::cetera())
            ->willReturn($this->createDimensionContent($firstChild, 'First', '/first', 'default', 'draft'));
        $this->contentManager->resolve($secondChild, Argument::cetera())
            ->willReturn($this->createDimensionContent($secondChild, 'Second', '/second', 'default', 'draft'));

        $result = $this->tool->getPageTree('example', 'en');

        $parentNode = $result['tree'][0];
        $this->assertSame(1, $parentNode['position'], 'the first root page is position 1, not 0');
        $this->assertSame(1, $parentNode['children'][0]['position']);
        $this->assertSame(2, $parentNode['children'][1]['position']);
    }

    public function testGetPageTreeReturnsEmptyTreeForEmptyWebspace(): void
    {
        $this->pageRepository->findByAsTree(Argument::cetera())->willReturn([]);

        $result = $this->tool->getPageTree('example', 'en');

        $this->assertSame([], $result['tree']);
    }

    public function testGetPageTreeMaxDepthStopsRecursionAtBoundary(): void
    {
        $grandchild = $this->createPage('uuid-grandchild', 'Grandchild', '/child/grandchild');

        $child = $this->createPage('uuid-child', 'Child', '/child');
        $child->addChild($grandchild);
        $grandchild->setParent($child);

        $parent = $this->createPage('uuid-parent', 'Parent', '/');
        $parent->addChild($child);
        $child->setParent($parent);

        $this->pageRepository->findByAsTree(Argument::cetera())->willReturn([$parent]);

        $parentDim = $this->createDimensionContent($parent, 'Parent', '/', 'default', 'published');
        $childDim = $this->createDimensionContent($child, 'Child', '/child', 'default', 'published');

        $this->contentManager->resolve($parent, Argument::cetera())->willReturn($parentDim);
        $this->contentManager->resolve($child, Argument::cetera())->willReturn($childDim);

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
        $child = $this->createPage('uuid-child', 'Child', '/child');

        $root = $this->createPage('uuid-root', 'Root', '/');
        $root->addChild($child);
        $child->setParent($root);

        $this->pageRepository->findByAsTree(Argument::cetera())->willReturn([$root]);

        $rootDim = $this->createDimensionContent($root, 'Root', '/', 'default', 'published');
        $this->contentManager->resolve(Argument::cetera())->willReturn($rootDim);

        $result = $this->tool->getPageTree('example', 'en', 0);

        $rootNode = $result['tree'][0];
        $this->assertTrue($rootNode['hasChildren'], 'root has children so hasChildren must be true');
        $this->assertSame([], $rootNode['children'], 'children must be empty at maxDepth=0');
    }

    public function testGetPageTreeWithoutMaxDepthReturnsFullNesting(): void
    {
        $grandchild = $this->createPage('uuid-grandchild', 'Grandchild', '/child/grandchild');

        $child = $this->createPage('uuid-child', 'Child', '/child');
        $child->addChild($grandchild);
        $grandchild->setParent($child);

        $root = $this->createPage('uuid-root', 'Root', '/');
        $root->addChild($child);
        $child->setParent($root);

        $this->pageRepository->findByAsTree(Argument::cetera())->willReturn([$root]);

        $dim = $this->createDimensionContent($root, 'Page', '/', 'default', 'published');
        $this->contentManager->resolve(Argument::cetera())->willReturn($dim);

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
        $tool = new PageTreeTool(
            $this->pageRepository->reveal(),
            $this->contentManager->reveal(),
            $this->webspaceResolver([]),
            new AccessControlFilterFactory(null, ['view' => 64, 'add' => 32, 'edit' => 16, 'delete' => 8, 'archive' => 4, 'live' => 2, 'security' => 1]),
        );

        $this->pageRepository->findByAsTree(Argument::cetera())->shouldNotBeCalled();

        $result = $tool->getPageTree('example', 'en');

        $this->assertSame('example', $result['webspace']);
        $this->assertSame([], $result['tree']);
        $this->assertArrayHasKey('hint', $result);
    }

    public function testGetPageTreeReturnsEmptyTreeWhenRequestedWebspaceIsNotPermitted(): void
    {
        $tool = new PageTreeTool(
            $this->pageRepository->reveal(),
            $this->contentManager->reveal(),
            $this->webspaceResolver(['other']),
            new AccessControlFilterFactory(null, ['view' => 64, 'add' => 32, 'edit' => 16, 'delete' => 8, 'archive' => 4, 'live' => 2, 'security' => 1]),
        );

        $this->pageRepository->findByAsTree(Argument::cetera())->shouldNotBeCalled();

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
            ->findByAsTree(
                Argument::that(static fn (array $filters): bool => 'example' === ($filters['webspaceKey'] ?? null)),
                Argument::cetera(),
            )
            ->shouldBeCalledOnce()
            ->willReturn([]);

        $this->tool->getPageTree('example', 'en');
    }

    /**
     * @param list<PageInterface> $children
     */
    private function createPage(string $uuid, string $title, string $url, array $children = []): Page
    {
        $page = new Page($uuid);
        $page->setWebspaceKey('example');
        foreach ($children as $child) {
            $page->addChild($child);
        }

        return $page;
    }

    private function createDimensionContent(
        PageInterface $page,
        string $title,
        string $slug,
        string $templateKey,
        string $workflowPlace,
    ): PageDimensionContent {
        $dimensionContent = new PageDimensionContent($page);
        $dimensionContent->setTemplateKey($templateKey);
        $dimensionContent->setTemplateData(['title' => $title]);
        $dimensionContent->setWorkflowPlace($workflowPlace);
        $dimensionContent->addAvailableLocale('en');
        $dimensionContent->setRoute(new Route(PageInterface::RESOURCE_KEY, $page->getUuid(), 'en', $slug));

        return $dimensionContent;
    }

    private function setupContentManagerForPage(
        PageInterface $page,
        string $title,
        string $slug,
        string $templateKey,
        string $workflowPlace,
    ): void {
        $dimensionContent = $this->createDimensionContent($page, $title, $slug, $templateKey, $workflowPlace);
        $this->contentManager->resolve($page, Argument::cetera())->willReturn($dimensionContent);
    }
}
