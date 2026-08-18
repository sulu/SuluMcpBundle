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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceCollection;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Component\Webspace\Webspace;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Application\Security\AccessControlFilterFactory;
use Sulu\Mcp\Application\Security\ToolPermissionChecker;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Tests\Unit\Fixture\TestUser;
use Sulu\Mcp\UserInterface\Mcp\Tool\Page\PageListTool;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Model\PageDimensionContent;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;

#[CoversClass(PageListTool::class)]
final class PageListToolTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<PageRepositoryInterface> */
    private ObjectProphecy $pageRepository;

    /** @var ObjectProphecy<ContentManagerInterface> */
    private ObjectProphecy $contentManager;

    private WebspacePermissionResolver $webspacePermissionResolver;
    private PageListTool $tool;

    protected function setUp(): void
    {
        $this->pageRepository = $this->prophesize(PageRepositoryInterface::class);
        $this->contentManager = $this->prophesize(ContentManagerInterface::class);
        // Default grants the '' key from unstubbed PageInterface mocks, so existing
        // happy-path tests are unaffected by the new filter.
        $this->webspacePermissionResolver = $this->webspaceResolver(['example']);
        $this->tool = new PageListTool($this->pageRepository->reveal(), $this->contentManager->reveal(), $this->webspacePermissionResolver, new AccessControlFilterFactory(null, ['view' => 64, 'add' => 32, 'edit' => 16, 'delete' => 8, 'archive' => 4, 'live' => 2, 'security' => 1]));
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

        $webspaceManager = $this->prophesize(WebspaceManagerInterface::class);
        $webspaceManager->getWebspaceCollection()->willReturn(new WebspaceCollection($webspaces));

        $securityChecker = $this->prophesize(SecurityCheckerInterface::class);
        $securityChecker->hasPermission(Argument::cetera())->willReturn(true);

        $tokenStorage = (new TestUser())->inTokenStorage();

        return new WebspacePermissionResolver($webspaceManager->reveal(), new ToolPermissionChecker($securityChecker->reveal(), $tokenStorage));
    }

    public function testListPagesReturnsPaginatedResults(): void
    {
        $page1 = new Page('uuid-1');
        $page1->setWebspaceKey('example');
        $page2 = new Page('uuid-2');
        $page2->setWebspaceKey('example');

        $this->pageRepository->findIdentifiersBy(Argument::cetera())->willReturn(['uuid-1', 'uuid-2']);
        $this->pageRepository->findBy(Argument::cetera())->willReturn([$page1, $page2]);
        $this->pageRepository->countBy(Argument::cetera())->willReturn(5);

        $dimensionContent = new PageDimensionContent(new Page());
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn(['title' => 'Test']);

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
            ->findIdentifiersBy(
                Argument::that(fn (array $filters): bool => isset($filters['templateKeys'])
                    && $filters['templateKeys'] === ['default']),
                Argument::any(),
            )
            ->shouldBeCalledOnce()
            ->willReturn([]);
        $this->pageRepository->countBy(Argument::cetera())->willReturn(0);

        $this->tool->listPages('example', 'en', 'default');
    }

    public function testListPagesAppliesParentIdFilter(): void
    {
        $this->pageRepository
            ->findIdentifiersBy(
                Argument::that(fn (array $filters): bool => isset($filters['parentId'])
                    && 'parent-uuid' === $filters['parentId']),
                Argument::any(),
            )
            ->shouldBeCalledOnce()
            ->willReturn([]);
        $this->pageRepository->countBy(Argument::cetera())->willReturn(0);

        $this->tool->listPages('example', 'en', null, 'parent-uuid');
    }

    public function testListPagesDefaultsPaginationToPage1Limit20(): void
    {
        $this->pageRepository
            ->findIdentifiersBy(
                Argument::that(fn (array $filters): bool => 1 === $filters['page'] && 20 === $filters['limit']),
                Argument::any(),
            )
            ->shouldBeCalledOnce()
            ->willReturn([]);
        $this->pageRepository->countBy(Argument::cetera())->willReturn(0);

        $this->tool->listPages('example', 'en');
    }

    public function testListPagesResolvesAndNormalizesEachPage(): void
    {
        $page1 = new Page('uuid-1');
        $page1->setWebspaceKey('example');
        $page2 = new Page('uuid-2');
        $page2->setWebspaceKey('example');
        $page3 = new Page('uuid-3');
        $page3->setWebspaceKey('example');

        $this->pageRepository->findIdentifiersBy(Argument::cetera())->willReturn(['uuid-1', 'uuid-2', 'uuid-3']);
        $this->pageRepository->findBy(Argument::cetera())->willReturn([$page1, $page2, $page3]);
        $this->pageRepository->countBy(Argument::cetera())->willReturn(3);

        $dimensionContent = new PageDimensionContent(new Page());
        $this->contentManager
            ->resolve(Argument::cetera())
            ->shouldBeCalledTimes(3)
            ->willReturn($dimensionContent);
        $this->contentManager
            ->normalize(Argument::cetera())
            ->shouldBeCalledTimes(3)
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
        $tool = new PageListTool($this->pageRepository->reveal(), $this->contentManager->reveal(), $this->webspaceResolver([]), new AccessControlFilterFactory(null, ['view' => 64, 'add' => 32, 'edit' => 16, 'delete' => 8, 'archive' => 4, 'live' => 2, 'security' => 1]));

        $this->pageRepository->findBy(Argument::cetera())->shouldNotBeCalled();

        $result = $tool->listPages('example', 'en');

        $this->assertSame([], $result['pages']);
        $this->assertSame(0, $result['total']);
        $this->assertArrayHasKey('hint', $result);
    }

    public function testListPagesReturnsEmptyListWhenRequestedWebspaceIsNotPermitted(): void
    {
        $tool = new PageListTool($this->pageRepository->reveal(), $this->contentManager->reveal(), $this->webspaceResolver(['other']), new AccessControlFilterFactory(null, ['view' => 64, 'add' => 32, 'edit' => 16, 'delete' => 8, 'archive' => 4, 'live' => 2, 'security' => 1]));

        $this->pageRepository->findBy(Argument::cetera())->shouldNotBeCalled();

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
            ->findIdentifiersBy(Argument::that($isScoped), Argument::any())
            ->shouldBeCalledOnce()
            ->willReturn([]);

        $this->pageRepository
            ->countBy(Argument::that($isScoped))
            ->shouldBeCalledOnce()
            ->willReturn(0);

        $this->tool->listPages('example', 'en');
    }

    /**
     * @return array{PageListTool, ObjectProphecy<PageRepositoryInterface>}
     */
    private function createToolWithProphecyRepository(): array
    {
        $pageRepository = $this->prophesize(PageRepositoryInterface::class);

        $contentManager = $this->prophesize(ContentManagerInterface::class);
        $contentManager->resolve(Argument::cetera())
            ->willReturn($this->prophesize(DimensionContentInterface::class)->reveal());
        $contentManager->normalize(Argument::cetera())->willReturn(['title' => 'Test']);

        $tool = new PageListTool(
            $pageRepository->reveal(),
            $contentManager->reveal(),
            $this->webspacePermissionResolver,
            new AccessControlFilterFactory(null, ['view' => 64, 'add' => 32, 'edit' => 16, 'delete' => 8, 'archive' => 4, 'live' => 2, 'security' => 1]),
        );

        return [$tool, $pageRepository];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sortFieldAndOrderProvider(): iterable
    {
        foreach (['title', 'authored', 'created', 'changed', 'workflowPublished'] as $field) {
            foreach (['asc', 'desc'] as $order) {
                yield "{$field}/{$order}" => [$field, $order];
            }
        }
    }

    #[DataProvider('sortFieldAndOrderProvider')]
    public function testListPagesAppliesSortByToBothRepositoryCalls(string $sortBy, string $sortOrder): void
    {
        [$tool, $pageRepository] = $this->createToolWithProphecyRepository();

        $page = $this->prophesize(PageInterface::class);
        $page->getUuid()->willReturn('uuid-1');

        $pageRepository->countBy(Argument::type('array'))->willReturn(1);
        $pageRepository->findIdentifiersBy(Argument::type('array'), [$sortBy => $sortOrder])
            ->shouldBeCalledOnce()
            ->willReturn(['uuid-1']);
        $pageRepository->findBy(Argument::type('array'), [$sortBy => $sortOrder], Argument::type('array'))
            ->shouldBeCalledOnce()
            ->willReturn([$page->reveal()]);

        $tool->listPages('example', 'en', null, null, 1, 20, $sortBy, $sortOrder);
    }

    public function testListPagesDefaultSortIsTitleAscendingWhenOmitted(): void
    {
        [$tool, $pageRepository] = $this->createToolWithProphecyRepository();

        $page = $this->prophesize(PageInterface::class);
        $page->getUuid()->willReturn('uuid-1');

        $pageRepository->countBy(Argument::type('array'))->willReturn(1);
        $pageRepository->findIdentifiersBy(Argument::type('array'), ['title' => 'asc'])
            ->shouldBeCalledOnce()
            ->willReturn(['uuid-1']);
        $pageRepository->findBy(Argument::type('array'), ['title' => 'asc'], Argument::type('array'))
            ->shouldBeCalledOnce()
            ->willReturn([$page->reveal()]);

        $tool->listPages('example', 'en');
    }

    public function testListPagesRejectsUnsupportedSortBy(): void
    {
        [$tool] = $this->createToolWithProphecyRepository();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported sortBy "bogus"');

        $tool->listPages('example', 'en', null, null, 1, 20, 'bogus', 'asc');
    }

    public function testListPagesRejectsUnsupportedSortOrder(): void
    {
        [$tool] = $this->createToolWithProphecyRepository();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported sortOrder "bogus"');

        $tool->listPages('example', 'en', null, null, 1, 20, 'title', 'bogus');
    }
}
