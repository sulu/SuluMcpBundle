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
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Tests\Unit\Fixture\FakeToolPermissionChecker;
use Sulu\Mcp\UserInterface\Mcp\Tool\Page\PageGetTool;
use Sulu\Page\Domain\Exception\PageNotFoundException;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Model\PageDimensionContent;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;

#[CoversClass(PageGetTool::class)]
final class PageGetToolTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<PageRepositoryInterface> */
    private ObjectProphecy $pageRepository;

    /** @var ObjectProphecy<ContentManagerInterface> */
    private ObjectProphecy $contentManager;

    private FakeToolPermissionChecker $permissionChecker;
    private PageGetTool $tool;

    protected function setUp(): void
    {
        $this->pageRepository = $this->prophesize(PageRepositoryInterface::class);
        $this->contentManager = $this->prophesize(ContentManagerInterface::class);
        $this->permissionChecker = FakeToolPermissionChecker::grantingAll();
        $this->tool = new PageGetTool(
            $this->pageRepository->reveal(),
            $this->contentManager->reveal(),
            $this->permissionChecker,
        );
    }

    public function testGetPageReturnsNormalizedContent(): void
    {
        $page = new Page('test-uuid-123');
        $page->setWebspaceKey('example');
        $normalizedData = ['title' => 'Test Page', 'template' => 'default'];

        $this->pageRepository->getOneBy(Argument::cetera())->willReturn($page);
        $this->contentManager->resolve(Argument::cetera())->willReturn(new PageDimensionContent($page));
        $this->contentManager->normalize(Argument::cetera())->willReturn($normalizedData);

        $result = $this->tool->getPage('example', 'en', 'test-uuid-123');

        $this->assertSame('test-uuid-123', $result['uuid']);
        $this->assertSame('example', $result['webspace']);
        $this->assertSame('en', $result['locale']);
        $this->assertSame($normalizedData, $result['data']);
    }

    public function testGetPagePassesCorrectFiltersToRepository(): void
    {
        $page = new Page('my-uuid');
        $page->setWebspaceKey('example');

        $this->pageRepository
            ->getOneBy(
                [
                    'uuid' => 'my-uuid',
                    'locale' => 'de',
                    'stage' => DimensionContentInterface::STAGE_DRAFT,
                ],
                [
                    PageRepositoryInterface::GROUP_SELECT_PAGE_ADMIN => true,
                ],
            )
            ->shouldBeCalledOnce()
            ->willReturn($page);

        $this->contentManager->resolve(Argument::cetera())->willReturn(new PageDimensionContent($page));
        $this->contentManager->normalize(Argument::cetera())->willReturn([]);

        $this->tool->getPage('example', 'de', 'my-uuid');
    }

    public function testGetPageUsesContentManagerToResolveAndNormalize(): void
    {
        $page = new Page('uuid-1');
        $page->setWebspaceKey('example');
        $dimensionContent = new PageDimensionContent($page);

        $this->pageRepository->getOneBy(Argument::cetera())->willReturn($page);

        $this->contentManager
            ->resolve($page, [
                'locale' => 'en',
                'stage' => DimensionContentInterface::STAGE_DRAFT,
            ])
            ->shouldBeCalledOnce()
            ->willReturn($dimensionContent);

        $this->contentManager
            ->normalize($dimensionContent)
            ->shouldBeCalledOnce()
            ->willReturn(['title' => 'Test']);

        $this->tool->getPage('example', 'en', 'uuid-1');
    }

    public function testGetPageReturnsErrorForMissingPage(): void
    {
        $this->pageRepository
            ->getOneBy(Argument::cetera())
            ->willThrow(new PageNotFoundException(['uuid' => 'missing-uuid']));

        $result = $this->tool->getPage('example', 'en', 'missing-uuid');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('missing-uuid', $result['error']);
        $this->assertTrue(\array_key_exists('hint', $result));
        $this->assertIsString($result['hint']);
        $this->assertNotEmpty($result['hint']);
    }

    public function testGetIncludesSeoAndExcerpt(): void
    {
        $page = new Page('uuid-seo');
        $page->setWebspaceKey('example');
        $normalizedData = [
            'title' => 'SEO Page',
            'seo' => ['title' => 'X'],
            'seoNoIndex' => true,
            'excerpt' => ['title' => 'Y'],
        ];

        $this->pageRepository->getOneBy(Argument::cetera())->willReturn($page);
        $this->contentManager->resolve(Argument::cetera())->willReturn(new PageDimensionContent($page));
        $this->contentManager->normalize(Argument::cetera())->willReturn($normalizedData);

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
        $page = new Page('test-uuid-123');
        $page->setWebspaceKey('example');

        $this->pageRepository->getOneBy(Argument::cetera())->willReturn($page);
        $this->contentManager->resolve(Argument::cetera())->willReturn(new PageDimensionContent($page));
        $this->contentManager->normalize(Argument::cetera())->willReturn([]);

        $this->tool->getPage('example', 'en', 'test-uuid-123');

        $this->assertSame(
            [[
                'context' => 'sulu.webspaces.example',
                'permissions' => [PermissionTypes::VIEW],
                'locale' => 'en',
                'objectType' => Page::class,
                'objectId' => 'test-uuid-123',
            ]],
            $this->permissionChecker->calls(),
        );
    }
}
