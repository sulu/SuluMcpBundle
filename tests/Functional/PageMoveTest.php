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

namespace Sulu\Mcp\Tests\Functional;

use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use Sulu\Bundle\SecurityBundle\System\SystemStoreInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Domain\Model\WorkflowInterface;
use Sulu\Mcp\UserInterface\Mcp\Tool\Page\PageMoveTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Page\PageReorderTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Page\PageTreeTool;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Page\Application\Message\ApplyWorkflowTransitionPageMessage;
use Sulu\Page\Application\Message\CreatePageMessage;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Route\Domain\Model\Route;
use Sulu\Route\Domain\Repository\RouteRepositoryInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Pins the three claims the move tool's description makes -- descendants follow, old
 * addresses become history, no re-publish is involved -- against real Sulu.
 */
#[CoversClass(PageMoveTool::class)]
#[CoversClass(PageReorderTool::class)]
final class PageMoveTest extends FunctionalTestCase
{
    private const ALL_GRANTED = [
        PermissionTypes::VIEW => true, PermissionTypes::ADD => true, PermissionTypes::EDIT => true,
        PermissionTypes::DELETE => true, PermissionTypes::ARCHIVE => true, PermissionTypes::LIVE => true,
        PermissionTypes::SECURITY => true,
    ];

    public function testMoveRewritesTheSubtreeAndLeavesTheOldAddressesAsHistory(): void
    {
        $this->authenticateEditor();

        $home = $this->createPage('homepage', 'Home', '/');
        $alpha = $this->createPage($home->getUuid(), 'Alpha', '/alpha');
        $beta = $this->createPage($home->getUuid(), 'Beta', '/beta');
        $leaf = $this->createPage($alpha->getUuid(), 'Leaf', '/alpha/leaf');

        $result = $this->moveTool()->movePage($alpha->getUuid(), $beta->getUuid(), 'en');

        // RouteChangedUpdater rewrites descendants in raw SQL after the flush, so the
        // identity map still holds the pre-move slugs.
        $alphaUuid = $alpha->getUuid();
        $leafUuid = $leaf->getUuid();
        $this->entityManager->clear();

        self::assertTrue($result['success'] ?? false, \json_encode($result));
        self::assertSame($beta->getUuid(), $result['parentId']);
        self::assertSame($home->getUuid(), $result['previousParentId']);
        self::assertSame(1, $result['affectedDescendants'], 'the leaf below alpha is the one affected descendant');
        self::assertSame('/beta/alpha', $result['url'], 'the caller is told the new address, not the old one');

        self::assertSame('/beta/alpha', $this->slugOfUuid($alphaUuid));
        self::assertSame('/beta/alpha/leaf', $this->slugOfUuid($leafUuid), 'the subtree is re-anchored, not just the moved page');

        // No publish transition ran: Route rows are not stage-scoped, so the address the
        // website resolves changed on flush.
        $histories = $this->historySlugs();
        self::assertContains('/alpha', $histories, 'the moved page keeps its old address as a redirect');
        self::assertContains('/alpha/leaf', $histories, 'every descendant keeps its old address as a redirect');
    }

    public function testMoveRefusesATargetParentInAnotherWebspace(): void
    {
        $this->authenticateEditor();

        $home = $this->createPage('homepage', 'Home', '/');
        $alpha = $this->createPage($home->getUuid(), 'Alpha', '/alpha');
        $intranetHome = $this->createPage('homepage', 'Intranet', '/', 'intranet');

        $denied = null;
        try {
            $this->moveTool()->movePage($alpha->getUuid(), $intranetHome->getUuid(), 'en');
        } catch (ToolCallException $exception) {
            $denied = $exception;
        }

        self::assertInstanceOf(ToolCallException::class, $denied, 'a cross-webspace move is refused, not reported as an error payload');
        self::assertSame('/alpha', $this->slugOfUuid($alpha->getUuid()), 'a refused move must not touch the route');
    }

    public function testReorderChangesSiblingOrderWithoutTouchingAddresses(): void
    {
        $this->authenticateEditor();

        $home = $this->createPage('homepage', 'Home', '/');
        $first = $this->createPage($home->getUuid(), 'First', '/first');
        $second = $this->createPage($home->getUuid(), 'Second', '/second');

        $result = $this->reorderTool()->reorderPage($second->getUuid(), 1, 'en');

        self::assertTrue($result['success'] ?? false, \json_encode($result));
        self::assertSame(1, $result['position']);

        $this->entityManager->clear();

        // Position is 1-based: reorderOneBy() computes its target index as $position - 1.
        self::assertSame(
            [$second->getUuid(), $first->getUuid()],
            $this->childUuids($home->getUuid()),
            'position 1 puts the page first, not second',
        );

        self::assertSame('/first', $this->slugOfUuid($first->getUuid()), 'reordering rewrites no addresses');
        self::assertSame('/second', $this->slugOfUuid($second->getUuid()));
        self::assertSame([], $this->historySlugs(), 'reordering creates no redirects');
    }

    public function testTreeReportsThePositionReorderExpects(): void
    {
        $this->authenticateEditor();

        $home = $this->createPage('homepage', 'Home', '/');
        $first = $this->createPage($home->getUuid(), 'First', '/first');
        $second = $this->createPage($home->getUuid(), 'Second', '/second');
        $third = $this->createPage($home->getUuid(), 'Third', '/third');

        $positions = $this->treePositions($home->getUuid());
        self::assertSame(3, $positions[$third->getUuid()], 'the third child reports position 3');

        // Feeding a reported position straight back must be a no-op.
        $this->reorderTool()->reorderPage($third->getUuid(), $positions[$third->getUuid()], 'en');
        $this->entityManager->clear();

        self::assertSame(
            [$first->getUuid(), $second->getUuid(), $third->getUuid()],
            $this->childUuids($home->getUuid()),
        );
    }

    public function testMoveRefusesATargetParentThatIsAlreadyTheCurrentParent(): void
    {
        $this->authenticateEditor();

        $home = $this->createPage('homepage', 'Home', '/');
        $first = $this->createPage($home->getUuid(), 'First', '/first');
        $second = $this->createPage($home->getUuid(), 'Second', '/second');

        $result = $this->moveTool()->movePage($first->getUuid(), $home->getUuid(), 'en');

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('already a child', $result['error']);

        $this->entityManager->clear();
        self::assertSame(
            [$first->getUuid(), $second->getUuid()],
            $this->childUuids($home->getUuid()),
            'the refused move must not re-append the page as last child',
        );
    }

    /**
     * @return list<string>
     */
    private function childUuids(string $parentUuid): array
    {
        $parent = self::getContainer()->get('sulu_page.page_repository')->getOneBy(['uuid' => $parentUuid]);

        return \array_map(
            static fn (PageInterface $child): string => $child->getUuid(),
            $parent->getChildren()->toArray(),
        );
    }

    /**
     * @return array<string, int> child uuid => reported position
     */
    private function treePositions(string $parentUuid): array
    {
        /** @var PageTreeTool $tool */
        $tool = self::getContainer()->get(PageTreeTool::class);
        $tree = $tool->getPageTree('website', 'en');

        $positions = [];
        foreach ($this->flatten($tree['tree']) as $node) {
            $positions[$node['uuid']] = $node['position'];
        }

        return $positions;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     *
     * @return list<array<string, mixed>>
     */
    private function flatten(array $nodes): array
    {
        $flat = [];
        foreach ($nodes as $node) {
            $flat[] = $node;
            $flat = \array_merge($flat, $this->flatten($node['children']));
        }

        return $flat;
    }

    private function authenticateEditor(): void
    {
        $container = self::getContainer();
        $builder = new PermissionFixtureBuilder(
            $this->entityManager,
            $container->get('sulu_security.mask_converter'),
            $container->get('security.token_storage'),
            $container->get(SystemStoreInterface::class),
        );
        $role = $builder->role('PageEditor', [
            'sulu.webspaces.website' => self::ALL_GRANTED,
            'sulu.webspaces.intranet' => self::ALL_GRANTED,
        ]);
        $builder->authenticate($builder->user('page-editor', $role));
    }

    private function createPage(string $parentId, string $title, string $url, string $webspace = 'website'): PageInterface
    {
        $envelope = $this->bus()->dispatch(new Envelope(
            new CreatePageMessage($webspace, $parentId, [
                'locale' => 'en',
                'template' => 'default',
                'title' => $title,
                'url' => $url,
            ]),
            [new EnableFlushStamp()],
        ));

        /** @var HandledStamp[] $stamps */
        $stamps = $envelope->all(HandledStamp::class);
        /** @var PageInterface $page */
        $page = $stamps[0]->getResult();

        $this->bus()->dispatch(new Envelope(
            new ApplyWorkflowTransitionPageMessage(
                identifier: ['uuid' => $page->getUuid()],
                locale: 'en',
                transitionName: WorkflowInterface::WORKFLOW_TRANSITION_PUBLISH,
            ),
            [new EnableFlushStamp()],
        ));

        return $page;
    }

    private function slugOfUuid(string $uuid): ?string
    {
        $route = $this->routeRepository()->findOneBy([
            'resourceKey' => PageInterface::RESOURCE_KEY,
            'resourceId' => $uuid,
            'locale' => 'en',
        ]);

        return $route?->getSlug();
    }

    /**
     * @return list<string>
     */
    private function historySlugs(): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('r.slug')
            ->from(Route::class, 'r')
            ->where('r.resourceKey = :resourceKey')
            ->setParameter('resourceKey', 'route_histories')
            ->getQuery()
            ->getScalarResult();

        return \array_map(static fn (array $row): string => (string) $row['slug'], $rows);
    }

    private function bus(): MessageBusInterface
    {
        /** @var MessageBusInterface $bus */
        $bus = self::getContainer()->get('sulu_message_bus');

        return $bus;
    }

    private function routeRepository(): RouteRepositoryInterface
    {
        /** @var RouteRepositoryInterface $repository */
        $repository = self::getContainer()->get('sulu_route.route_repository');

        return $repository;
    }

    private function moveTool(): PageMoveTool
    {
        /** @var PageMoveTool $tool */
        $tool = self::getContainer()->get(PageMoveTool::class);

        return $tool;
    }

    private function reorderTool(): PageReorderTool
    {
        /** @var PageReorderTool $tool */
        $tool = self::getContainer()->get(PageReorderTool::class);

        return $tool;
    }
}
