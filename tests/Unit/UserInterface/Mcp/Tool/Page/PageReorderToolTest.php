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
use Sulu\Mcp\Tests\Unit\Fixture\FakeToolPermissionChecker;
use Sulu\Mcp\UserInterface\Mcp\Tool\Page\PageReorderTool;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Page\Application\Message\OrderPageMessage;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[CoversClass(PageReorderTool::class)]
final class PageReorderToolTest extends TestCase
{
    use ProphecyTrait;

    private const PAGE_UUID = 'page-uuid';
    private const PARENT_UUID = 'parent-uuid';

    /** @var ObjectProphecy<MessageBusInterface> */
    private ObjectProphecy $messageBus;

    /** @var ObjectProphecy<PageRepositoryInterface> */
    private ObjectProphecy $pageRepository;

    private FakeToolPermissionChecker $permissionChecker;

    protected function setUp(): void
    {
        $this->messageBus = $this->prophesize(MessageBusInterface::class);
        $this->pageRepository = $this->prophesize(PageRepositoryInterface::class);
        $this->permissionChecker = FakeToolPermissionChecker::grantingAll();
    }

    public function testReorderPageDispatchesOrderPageMessage(): void
    {
        $page = $this->givenPageWithSiblings(3);

        $capturedEnvelope = null;
        $this->messageBus->dispatch(Argument::type(Envelope::class), Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use ($page, &$capturedEnvelope) {
                $capturedEnvelope = $args[0];

                return $args[0]->with(new HandledStamp($page, 'handler'));
            });

        $result = $this->tool()->reorderPage(self::PAGE_UUID, 2, 'en');

        self::assertTrue($result['success']);
        self::assertSame(2, $result['position']);
        self::assertSame(3, $result['siblingCount']);
        self::assertSame(self::PARENT_UUID, $result['parentId']);

        self::assertInstanceOf(Envelope::class, $capturedEnvelope);
        self::assertNotNull($capturedEnvelope->last(EnableFlushStamp::class));

        $message = $capturedEnvelope->getMessage();
        self::assertInstanceOf(OrderPageMessage::class, $message);
        self::assertSame(['uuid' => self::PAGE_UUID], $message->getIdentifier());
        self::assertSame(2, $message->getPosition());
        self::assertSame('en', $message->getLocale());
    }

    public function testReorderPageRefusesAPositionBeyondTheSiblingCount(): void
    {
        $this->givenPageWithSiblings(3);

        $result = $this->tool()->reorderPage(self::PAGE_UUID, 4, 'en');

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('out of range', $result['error']);
        $this->messageBus->dispatch(Argument::cetera())->shouldNotHaveBeenCalled();
    }

    public function testReorderPageRefusesAPositionBelowOne(): void
    {
        $this->givenPageWithSiblings(3);

        $result = $this->tool()->reorderPage(self::PAGE_UUID, 0, 'en');

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('out of range', $result['error']);
        $this->messageBus->dispatch(Argument::cetera())->shouldNotHaveBeenCalled();
    }

    public function testReorderPageRefusesTheWebspaceStartPage(): void
    {
        $startPage = new Page(self::PAGE_UUID);
        $startPage->setWebspaceKey('example');
        $this->pageRepository->findOneBy(Argument::cetera())->willReturn($startPage);

        $result = $this->tool()->reorderPage(self::PAGE_UUID, 1, 'en');

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('start page', $result['error']);
        $this->messageBus->dispatch(Argument::cetera())->shouldNotHaveBeenCalled();
    }

    public function testReorderPageReportsAnUnknownUuid(): void
    {
        $this->pageRepository->findOneBy(Argument::cetera())->willReturn(null);

        $result = $this->tool()->reorderPage(self::PAGE_UUID, 1, 'en');

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Page not found', $result['error']);
    }

    public function testReorderPageRequiresEditOnThePage(): void
    {
        $this->givenPageWithSiblings(3);
        $this->permissionChecker = FakeToolPermissionChecker::grantingAll()->denyContext('sulu.webspaces.example');

        $this->expectException(ToolCallException::class);

        $this->tool()->reorderPage(self::PAGE_UUID, 2, 'en');
    }

    public function testReorderPageDoesNotRevealTheSiblingCountToACallerWithoutEditRights(): void
    {
        $parent = new Page(self::PARENT_UUID);
        $parent->setWebspaceKey('example');
        $page = new Page(self::PAGE_UUID);
        $page->setWebspaceKey('example');
        $page->setParent($parent);
        $this->pageRepository->findOneBy(Argument::cetera())->willReturn($page);
        $this->pageRepository->countBy(Argument::cetera())->shouldNotBeCalled();

        $this->permissionChecker = FakeToolPermissionChecker::grantingAll()->denyContext('sulu.webspaces.example');

        $this->expectException(ToolCallException::class);

        $this->tool()->reorderPage(self::PAGE_UUID, 99, 'en');
    }

    private function tool(): PageReorderTool
    {
        return new PageReorderTool(
            $this->messageBus->reveal(),
            $this->pageRepository->reveal(),
            $this->permissionChecker,
        );
    }

    private function givenPageWithSiblings(int $siblingCount): PageInterface
    {
        $parent = new Page(self::PARENT_UUID);
        $parent->setWebspaceKey('example');

        $page = new Page(self::PAGE_UUID);
        $page->setWebspaceKey('example');
        $page->setParent($parent);
        $parent->addChild($page);

        for ($i = 1; $i < $siblingCount; ++$i) {
            $sibling = new Page('sibling-' . $i);
            $sibling->setWebspaceKey('example');
            $sibling->setParent($parent);
            $parent->addChild($sibling);
        }

        $this->pageRepository->findOneBy(Argument::cetera())->willReturn($page);
        $this->pageRepository->countBy(['parentId' => self::PARENT_UUID])->willReturn($siblingCount);

        return $page;
    }
}
