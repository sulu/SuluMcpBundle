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

use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Mcp\Infrastructure\Sulu\AdminLink\PageAdminLinkProvider;
use Sulu\Mcp\Infrastructure\Symfony\Routing\AdminLinkGenerator;
use Sulu\Mcp\Tests\Application\TestBundle\Admin\TestViewRegistry;
use Sulu\Mcp\Tests\Unit\Fixture\FakeToolPermissionChecker;
use Sulu\Mcp\UserInterface\Mcp\Tool\Page\PageMoveTool;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Page\Application\Message\MovePageMessage;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Model\PageDimensionContent;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Sulu\Route\Domain\Model\Route as SuluRoute;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Loader\ClosureLoader;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Router;

#[CoversClass(PageMoveTool::class)]
final class PageMoveToolTest extends TestCase
{
    use ProphecyTrait;

    private const PAGE_UUID = 'page-uuid';
    private const OLD_PARENT_UUID = 'old-parent-uuid';
    private const NEW_PARENT_UUID = 'new-parent-uuid';

    /** @var ObjectProphecy<MessageBusInterface> */
    private ObjectProphecy $messageBus;

    /** @var ObjectProphecy<ContentManagerInterface> */
    private ObjectProphecy $contentManager;

    /** @var ObjectProphecy<PageRepositoryInterface> */
    private ObjectProphecy $pageRepository;

    private FakeToolPermissionChecker $permissionChecker;

    protected function setUp(): void
    {
        $this->messageBus = $this->prophesize(MessageBusInterface::class);
        $this->contentManager = $this->prophesize(ContentManagerInterface::class);
        $this->pageRepository = $this->prophesize(PageRepositoryInterface::class);
        $this->permissionChecker = FakeToolPermissionChecker::grantingAll();
    }

    public function testMovePageDispatchesMovePageMessageAndReportsDescendants(): void
    {
        $page = $this->givenTree();
        $this->pageRepository->findDescendantIdsById(self::PAGE_UUID)->willReturn(['child-a', 'child-b']);
        $this->givenTranslation($page->getParent(), ['en']);
        $this->givenRoute($page, '/products/hardware/drills');

        $capturedEnvelope = null;
        $this->messageBus->dispatch(Argument::type(Envelope::class), Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use ($page, &$capturedEnvelope) {
                $capturedEnvelope = $args[0];

                return $args[0]->with(new HandledStamp($page, 'handler'));
            });

        $result = $this->tool()->movePage(self::PAGE_UUID, self::NEW_PARENT_UUID, 'en');

        self::assertTrue($result['success']);
        self::assertSame(self::NEW_PARENT_UUID, $result['parentId']);
        self::assertSame(self::OLD_PARENT_UUID, $result['previousParentId']);
        self::assertSame(2, $result['affectedDescendants']);
        self::assertSame('/products/hardware/drills', $result['url']);

        self::assertInstanceOf(Envelope::class, $capturedEnvelope);
        self::assertNotNull($capturedEnvelope->last(EnableFlushStamp::class));

        $message = $capturedEnvelope->getMessage();
        self::assertInstanceOf(MovePageMessage::class, $message);
        self::assertSame(['uuid' => self::PAGE_UUID], $message->getIdentifier());
        self::assertSame(['uuid' => self::NEW_PARENT_UUID], $message->getTargetParentIdentifier());
        self::assertSame('en', $message->getLocale());
    }

    public function testMovePageRefusesATargetParentInAnotherWebspace(): void
    {
        $page = $this->givenTree(targetWebspace: 'intranet');
        $this->givenTranslation($page->getParent(), ['en']);

        $this->expectException(ToolCallException::class);

        $this->tool()->movePage(self::PAGE_UUID, self::NEW_PARENT_UUID, 'en');
    }

    public function testMovePageRefusesToMoveTheWebspaceStartPage(): void
    {
        $startPage = new Page(self::PAGE_UUID);
        $startPage->setWebspaceKey('example');
        $this->givenPage(self::PAGE_UUID, $startPage);

        $result = $this->tool()->movePage(self::PAGE_UUID, self::NEW_PARENT_UUID, 'en');

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('start page', $result['error']);
        $this->messageBus->dispatch(Argument::cetera())->shouldNotHaveBeenCalled();
    }

    public function testMovePageRefusesAMoveBelowItself(): void
    {
        $result = $this->tool()->movePage(self::PAGE_UUID, self::PAGE_UUID, 'en');

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('below itself', $result['error']);
        $this->messageBus->dispatch(Argument::cetera())->shouldNotHaveBeenCalled();
    }

    public function testMovePageRefusesAMoveBelowItsOwnDescendant(): void
    {
        $page = $this->givenTree();
        $this->pageRepository->findDescendantIdsById(self::PAGE_UUID)->willReturn([self::NEW_PARENT_UUID]);
        $this->givenTranslation($page->getParent(), ['en']);

        $result = $this->tool()->movePage(self::PAGE_UUID, self::NEW_PARENT_UUID, 'en');

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('own descendant', $result['error']);
        // The permission check runs first, so the tree shape is never disclosed to a
        // caller who could not have edited it anyway.
        self::assertNotEmpty($this->permissionChecker->calls());
        $this->messageBus->dispatch(Argument::cetera())->shouldNotHaveBeenCalled();
    }

    public function testMovePageRequiresEditOnTheTargetParentNotOnlyTheSourcePage(): void
    {
        $page = $this->givenTree();
        $this->pageRepository->findDescendantIdsById(self::PAGE_UUID)->willReturn([]);
        $this->givenTranslation($page->getParent(), ['en']);

        // EDIT everywhere except on the page the caller wants to move into.
        $this->permissionChecker = FakeToolPermissionChecker::grantingAll()->grantWhen(
            static fn (string $context, string $permission, ?string $locale, ?string $objectType, mixed $objectId): bool => self::NEW_PARENT_UUID !== $objectId
        );

        $this->expectException(ToolCallException::class);

        $this->tool()->movePage(self::PAGE_UUID, self::NEW_PARENT_UUID, 'en');
    }

    public function testMovePageChecksBothTheSourcePageAndTheTargetParent(): void
    {
        $page = $this->givenTree();
        $this->pageRepository->findDescendantIdsById(self::PAGE_UUID)->willReturn([]);
        $this->givenTranslation($page->getParent(), ['en']);
        $this->givenRoute($page, '/drills');
        $this->givenBusReturns($page);

        $this->tool()->movePage(self::PAGE_UUID, self::NEW_PARENT_UUID, 'en');

        $checkedIds = \array_column($this->permissionChecker->calls(), 'objectId');
        self::assertSame([self::PAGE_UUID, self::NEW_PARENT_UUID], $checkedIds);
        self::assertSame(
            [['sulu.webspaces.example', PermissionTypes::EDIT], ['sulu.webspaces.example', PermissionTypes::EDIT]],
            $this->permissionChecker->checkedPairs(),
        );
    }

    public function testMovePageRefusesWhenTheCurrentParentHasNoTranslationInTheGivenLocale(): void
    {
        // MovePageMessageHandler reads the previous parent's title in this locale
        // without a null check, so the tool has to stop before dispatching.
        $page = $this->givenTree();
        $this->pageRepository->findDescendantIdsById(self::PAGE_UUID)->willReturn([]);
        $this->givenTranslation($page->getParent(), ['de']);

        $result = $this->tool()->movePage(self::PAGE_UUID, self::NEW_PARENT_UUID, 'en');

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('no "en" translation', $result['error']);
        $this->messageBus->dispatch(Argument::cetera())->shouldNotHaveBeenCalled();
    }

    public function testMovePageReportsAnUnknownUuid(): void
    {
        $this->pageRepository->findOneBy(Argument::cetera())->willReturn(null);

        $result = $this->tool()->movePage(self::PAGE_UUID, self::NEW_PARENT_UUID, 'en');

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Page not found', $result['error']);
    }

    private function tool(): PageMoveTool
    {
        return new PageMoveTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            $this->pageRepository->reveal(),
            new AdminLinkGenerator($this->router(), [new PageAdminLinkProvider(new TestViewRegistry())]),
            $this->permissionChecker,
        );
    }

    /**
     * Page under an old parent, plus a target parent, all in "example" unless told otherwise.
     */
    private function givenTree(string $targetWebspace = 'example'): PageInterface
    {
        $oldParent = new Page(self::OLD_PARENT_UUID);
        $oldParent->setWebspaceKey('example');

        $page = new Page(self::PAGE_UUID);
        $page->setWebspaceKey('example');
        $page->setParent($oldParent);
        $oldParent->addChild($page);

        $newParent = new Page(self::NEW_PARENT_UUID);
        $newParent->setWebspaceKey($targetWebspace);

        $this->givenPage(self::PAGE_UUID, $page);
        $this->givenPage(self::NEW_PARENT_UUID, $newParent);

        return $page;
    }

    private function givenPage(string $uuid, PageInterface $page): void
    {
        $this->pageRepository
            ->findOneBy(Argument::withEntry('uuid', $uuid), Argument::cetera())
            ->willReturn($page);
    }

    /**
     * @param list<string> $locales
     */
    private function givenTranslation(?PageInterface $page, array $locales): void
    {
        self::assertNotNull($page);

        $dimensionContent = new PageDimensionContent($page);
        foreach ($locales as $locale) {
            $dimensionContent->addAvailableLocale($locale);
        }

        $this->contentManager->resolve($page, Argument::cetera())->willReturn($dimensionContent);
    }

    private function givenRoute(PageInterface $page, string $slug): void
    {
        $dimensionContent = new PageDimensionContent($page);
        $dimensionContent->addAvailableLocale('en');
        $dimensionContent->setRoute(new SuluRoute(PageInterface::RESOURCE_KEY, $page->getUuid(), 'en', $slug));

        $this->contentManager->resolve($page, Argument::cetera())->willReturn($dimensionContent);
    }

    private function givenBusReturns(PageInterface $page): void
    {
        $this->messageBus
            ->dispatch(Argument::type(Envelope::class), Argument::cetera())
            ->will(fn (array $args) => $args[0]->with(new HandledStamp($page, 'handler')));
    }

    private function router(): Router
    {
        $routes = new RouteCollection();
        $routes->add('sulu_admin', new Route('/admin/'));

        return new Router(
            new ClosureLoader(),
            static fn (): RouteCollection => $routes,
            [],
            new RequestContext(),
        );
    }
}
