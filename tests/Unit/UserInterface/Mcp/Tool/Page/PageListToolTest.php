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
use Mcp\Capability\Attribute\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceCollection;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Component\Webspace\Webspace;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Application\Security\AccessControlFilterFactory;
use Sulu\Mcp\Application\Security\ToolPermissionChecker;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\UserInterface\Mcp\Tool\Page\PageListTool;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

#[CoversClass(PageListTool::class)]
final class PageListToolTest extends TestCase
{
    private PageRepositoryInterface&MockObject $pageRepository;
    private ContentManagerInterface&MockObject $contentManager;
    private WebspacePermissionResolver $webspacePermissionResolver;
    private PageListTool $tool;

    protected function setUp(): void
    {
        $this->pageRepository = $this->createMock(PageRepositoryInterface::class);
        $this->contentManager = $this->createMock(ContentManagerInterface::class);
        // Default grants the '' key from unstubbed PageInterface mocks, so existing
        // happy-path tests are unaffected by the new filter.
        $this->webspacePermissionResolver = $this->webspaceResolver(['example']);
        $this->tool = new PageListTool($this->pageRepository, $this->contentManager, $this->webspacePermissionResolver, new AccessControlFilterFactory(null, ['view' => 64, 'add' => 32, 'edit' => 16, 'delete' => 8, 'archive' => 4, 'live' => 2, 'security' => 1]));
    }

    /**
     * Builds a real WebspacePermissionResolver (it's final, can't be mocked) over
     * mocked dependencies.
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

    public function testListPagesReturnsPaginatedResults(): void
    {
        $page1 = $this->createMock(PageInterface::class);
        $page1->method('getUuid')->willReturn('uuid-1');
        $page2 = $this->createMock(PageInterface::class);
        $page2->method('getUuid')->willReturn('uuid-2');

        $this->pageRepository->method('findIdentifiersBy')->willReturn(['uuid-1', 'uuid-2']);
        $this->pageRepository->method('findBy')->willReturn([$page1, $page2]);
        $this->pageRepository->method('countBy')->willReturn(5);

        $dimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager->method('resolve')->willReturn($dimensionContent);
        $this->contentManager->method('normalize')->willReturn(['title' => 'Test']);

        $result = $this->tool->listPages('example', 'en');

        $this->assertCount(2, $result['pages']);
        $this->assertSame(5, $result['total']);
        $this->assertSame(1, $result['page']);
        $this->assertSame(20, $result['limit']);
        $this->assertSame('uuid-1', $result['pages'][0]['uuid']);
        $this->assertSame('uuid-2', $result['pages'][1]['uuid']);
    }

    public function testListPagesAppliesTemplateFilter(): void
    {
        $this->pageRepository
            ->expects($this->once())
            ->method('findIdentifiersBy')
            ->with(
                $this->callback(fn (array $filters): bool => isset($filters['templateKeys'])
                    && $filters['templateKeys'] === ['default']),
                $this->anything(),
            )
            ->willReturn([]);
        $this->pageRepository->method('countBy')->willReturn(0);

        $this->tool->listPages('example', 'en', 'default');
    }

    public function testListPagesAppliesParentIdFilter(): void
    {
        $this->pageRepository
            ->expects($this->once())
            ->method('findIdentifiersBy')
            ->with(
                $this->callback(fn (array $filters): bool => isset($filters['parentId'])
                    && 'parent-uuid' === $filters['parentId']),
                $this->anything(),
            )
            ->willReturn([]);
        $this->pageRepository->method('countBy')->willReturn(0);

        $this->tool->listPages('example', 'en', null, 'parent-uuid');
    }

    public function testListPagesDefaultsPaginationToPage1Limit20(): void
    {
        $this->pageRepository
            ->expects($this->once())
            ->method('findIdentifiersBy')
            ->with(
                $this->callback(fn (array $filters): bool => 1 === $filters['page'] && 20 === $filters['limit']),
                $this->anything(),
            )
            ->willReturn([]);
        $this->pageRepository->method('countBy')->willReturn(0);

        $this->tool->listPages('example', 'en');
    }

    public function testListPagesResolvesAndNormalizesEachPage(): void
    {
        $page1 = $this->createMock(PageInterface::class);
        $page1->method('getUuid')->willReturn('uuid-1');
        $page2 = $this->createMock(PageInterface::class);
        $page2->method('getUuid')->willReturn('uuid-2');
        $page3 = $this->createMock(PageInterface::class);
        $page3->method('getUuid')->willReturn('uuid-3');

        $this->pageRepository->method('findIdentifiersBy')->willReturn(['uuid-1', 'uuid-2', 'uuid-3']);
        $this->pageRepository->method('findBy')->willReturn([$page1, $page2, $page3]);
        $this->pageRepository->method('countBy')->willReturn(3);

        $dimensionContent = $this->createMock(DimensionContentInterface::class);
        $this->contentManager
            ->expects($this->exactly(3))
            ->method('resolve')
            ->willReturn($dimensionContent);
        $this->contentManager
            ->expects($this->exactly(3))
            ->method('normalize')
            ->willReturn(['title' => 'Test']);

        $this->tool->listPages('example', 'en');
    }

    public function testListPagesMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(PageListTool::class, 'listPages');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes, 'listPages() method must have exactly one #[McpTool] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_page_list', $instance->name);
    }

    public function testParentIdParameterHasSchemaAttribute(): void
    {
        $reflection = new \ReflectionMethod(PageListTool::class, 'listPages');
        $parameter = $reflection->getParameters()[3];
        $this->assertSame('parentId', $parameter->getName());

        $attributes = $parameter->getAttributes(Schema::class);
        $this->assertCount(1, $attributes);

        $schema = $attributes[0]->newInstance();
        $this->assertStringContainsString('UUID', $schema->description);
    }

    public function testListPagesReturnsEmptyListWhenNoWebspaceIsPermitted(): void
    {
        $tool = new PageListTool($this->pageRepository, $this->contentManager, $this->webspaceResolver([]), new AccessControlFilterFactory(null, ['view' => 64, 'add' => 32, 'edit' => 16, 'delete' => 8, 'archive' => 4, 'live' => 2, 'security' => 1]));

        $this->pageRepository->expects($this->never())->method('findBy');

        $result = $tool->listPages('example', 'en');

        $this->assertSame([], $result['pages']);
        $this->assertSame(0, $result['total']);
        $this->assertArrayHasKey('hint', $result);
    }

    public function testListPagesReturnsEmptyListWhenRequestedWebspaceIsNotPermitted(): void
    {
        $tool = new PageListTool($this->pageRepository, $this->contentManager, $this->webspaceResolver(['other']), new AccessControlFilterFactory(null, ['view' => 64, 'add' => 32, 'edit' => 16, 'delete' => 8, 'archive' => 4, 'live' => 2, 'security' => 1]));

        $this->pageRepository->expects($this->never())->method('findBy');

        $result = $tool->listPages('example', 'en');

        $this->assertSame([], $result['pages']);
        $this->assertSame(0, $result['total']);
        $this->assertStringContainsString('example', (string) $result['hint']);
    }

    /**
     * Webspace scoping must happen in the query, not by discarding rows afterward,
     * or `total` counts pages the caller cannot see.
     */
    public function testListPagesScopesQueryToRequestedWebspace(): void
    {
        $isScoped = static fn (array $filters): bool => 'example' === ($filters['webspaceKey'] ?? null);

        $this->pageRepository
            ->expects($this->once())
            ->method('findIdentifiersBy')
            ->with($this->callback($isScoped), $this->anything())
            ->willReturn([]);

        $this->pageRepository
            ->expects($this->once())
            ->method('countBy')
            ->with($this->callback($isScoped))
            ->willReturn(0);

        $this->tool->listPages('example', 'en');
    }
}
