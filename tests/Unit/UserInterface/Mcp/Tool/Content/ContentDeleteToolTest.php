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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\Content;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Article\Application\Message\RemoveArticleMessage;
use Sulu\Article\Domain\Model\Article;
use Sulu\Article\Domain\Model\ArticleDimensionContent;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Bundle\SecurityBundle\System\SystemStoreInterface;
use Sulu\Component\Security\Authorization\AccessControl\AccessControlRepositoryInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Mcp\Application\Content\ContentTypeResolver;
use Sulu\Mcp\Application\Security\ContentSecurityContextResolver;
use Sulu\Mcp\Application\Security\PageDescendantPermissionChecker;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\Tests\Application\TestBundle\Metadata\TestGroupProvider;
use Sulu\Mcp\Tests\Unit\Fixture\FakeToolPermissionChecker;
use Sulu\Mcp\Tests\Unit\Fixture\TestUser;
use Sulu\Mcp\UserInterface\Mcp\Tool\Content\ContentDeleteTool;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Page\Application\Message\RemovePageMessage;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Sulu\Snippet\Application\Message\RemoveSnippetMessage;
use Sulu\Snippet\Domain\Model\Snippet;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[CoversClass(ContentDeleteTool::class)]
final class ContentDeleteToolTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<MessageBusInterface> */
    private ObjectProphecy $messageBus;

    /** @var ObjectProphecy<PageRepositoryInterface> */
    private ObjectProphecy $pageRepository;

    /** @var ObjectProphecy<ArticleRepositoryInterface> */
    private ObjectProphecy $articleRepository;

    /** @var ObjectProphecy<SnippetRepositoryInterface> */
    private ObjectProphecy $snippetRepository;

    /** @var ObjectProphecy<ContentManagerInterface> */
    private ObjectProphecy $contentManager;

    private FakeToolPermissionChecker $permissionChecker;

    /** @var ObjectProphecy<AccessControlRepositoryInterface> */
    private ObjectProphecy $accessControlRepository;

    /** @var ObjectProphecy<Security> */
    private ObjectProphecy $security;

    private ContentDeleteTool $tool;

    protected function setUp(): void
    {
        $this->messageBus = $this->prophesize(MessageBusInterface::class);
        $this->pageRepository = $this->prophesize(PageRepositoryInterface::class);
        $this->articleRepository = $this->prophesize(ArticleRepositoryInterface::class);
        $this->snippetRepository = $this->prophesize(SnippetRepositoryInterface::class);
        $this->contentManager = $this->prophesize(ContentManagerInterface::class);
        $this->permissionChecker = FakeToolPermissionChecker::grantingAll();
        $this->accessControlRepository = $this->prophesize(AccessControlRepositoryInterface::class);
        $this->security = $this->prophesize(Security::class);
        $groupProvider = new TestGroupProvider([]);
        $systemStore = $this->prophesize(SystemStoreInterface::class);
        $systemStore->getSystem(Argument::cetera())->willReturn('Sulu');

        $pageDescendantPermissionChecker = new PageDescendantPermissionChecker(
            $this->pageRepository->reveal(),
            $this->accessControlRepository->reveal(),
            $systemStore->reveal(),
            $this->security->reveal(),
            [PermissionTypes::DELETE => 8],
        );

        $this->tool = new ContentDeleteTool(
            $this->messageBus->reveal(),
            new ContentTypeResolver($this->pageRepository->reveal(), $this->articleRepository->reveal(), $this->snippetRepository->reveal()),
            $this->contentManager->reveal(),
            $this->permissionChecker,
            new ContentSecurityContextResolver(new ArticleSecurityContextResolver($groupProvider), $this->contentManager->reveal()),
            $pageDescendantPermissionChecker,
        );
    }

    public function testDeletePageDispatchesRemovePageMessageWithFlushStamp(): void
    {
        $this->setupEntity('page');

        $this->security->getUser(Argument::cetera())->willReturn(new TestUser());
        $this->pageRepository->findDescendantIdsById(Argument::cetera())->willReturn([]);

        $captured = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use (&$captured) {
                $captured = $args[0];

                return $args[0]->with(new HandledStamp(null, 'handler'));
            });

        $result = $this->tool->deleteContent('page', 'uuid-1', 'en', true);

        $this->assertInstanceOf(Envelope::class, $captured);
        $this->assertInstanceOf(RemovePageMessage::class, $captured->getMessage());
        $this->assertArrayHasKey(EnableFlushStamp::class, $captured->all());
        $this->assertSame(['success' => true, 'type' => 'page', 'uuid' => 'uuid-1', 'deleted' => true], $result);
    }

    public function testDeleteSnippetDispatchesRemoveSnippetMessage(): void
    {
        $this->setupEntity('snippet');

        $captured = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use (&$captured) {
                $captured = $args[0];

                return $args[0]->with(new HandledStamp(null, 'handler'));
            });

        $result = $this->tool->deleteContent('snippet', 'uuid-2', 'en');

        $this->assertInstanceOf(Envelope::class, $captured);
        $this->assertInstanceOf(RemoveSnippetMessage::class, $captured->getMessage());
        $this->assertTrue($result['deleted']);
    }

    public function testDeleteArticleDispatchesRemoveArticleMessage(): void
    {
        $this->setupEntity('article');

        $captured = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use (&$captured) {
                $captured = $args[0];

                return $args[0]->with(new HandledStamp(null, 'handler'));
            });

        $result = $this->tool->deleteContent('article', 'uuid-3', 'en');

        $this->assertInstanceOf(Envelope::class, $captured);
        $this->assertInstanceOf(RemoveArticleMessage::class, $captured->getMessage());
        $this->assertTrue($result['deleted']);
    }

    public function testUnsupportedTypeReturnsErrorWithoutDispatch(): void
    {
        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->deleteContent('contact', 'uuid-1', 'en');

        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('success', $result);
    }

    public function testEntityNotFoundReturnsErrorWithoutDispatch(): void
    {
        $this->articleRepository->getOneBy(Argument::cetera())->willThrow(new \RuntimeException('not found'));
        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->deleteContent('article', 'missing-uuid', 'en');

        $this->assertArrayHasKey('error', $result);
    }

    public function testErrorOnException(): void
    {
        $this->setupEntity('article');
        $this->messageBus->dispatch(Argument::cetera())->willThrow(new \RuntimeException('boom'));

        $result = $this->tool->deleteContent('article', 'uuid-1', 'en');

        $this->assertStringContainsString('boom', $result['error']);
    }

    public function testMethodHasMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionMethod(ContentDeleteTool::class, 'deleteContent'))->getAttributes(McpTool::class);
        $this->assertCount(1, $attributes);
        $this->assertSame('sulu_content_delete', $attributes[0]->newInstance()->name);
    }

    public function testDeleteContentThrowsToolCallExceptionWhenPermissionDenied(): void
    {
        $this->setupEntity('page');

        $this->permissionChecker->denyAll();

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(ToolCallException::class);

        $this->tool->deleteContent('page', 'uuid-1', 'en');
    }

    public function testDeletePageThrowsToolCallExceptionWhenDescendantPermissionDenied(): void
    {
        $this->setupEntity('page');

        $this->security->getUser(Argument::cetera())->willReturn(new TestUser());
        $this->pageRepository->findDescendantIdsById(Argument::cetera())->willReturn(['child-1', 'child-2']);
        // Only child-1 is granted DELETE — child-2 is missing, so the gate must trip.
        $this->accessControlRepository->findIdsWithGrantedPermissions(Argument::cetera())->willReturn(['child-1']);

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(ToolCallException::class);

        $this->tool->deleteContent('page', 'uuid-1', 'en', true);
    }

    public function testDeleteContentPassesConcretePageClassAsObjectTypeForBothChecks(): void
    {
        // Regression guard: Sulu stores per-page ACLs under the concrete Page class
        // (getSecuredClass()), not PageInterface — the interface matches no ACL row and
        // falls back to the webspace grant, for both the EDIT and DELETE check.
        $this->setupEntity('page');

        $this->security->getUser(Argument::cetera())->willReturn(new TestUser());
        $this->pageRepository->findDescendantIdsById(Argument::cetera())->willReturn([]);
        $this->messageBus->dispatch(Argument::cetera())
            ->will(fn (array $args) => $args[0]->with(new HandledStamp(null, 'handler')));

        $result = $this->tool->deleteContent('page', 'uuid-1', 'en');

        $this->assertTrue($result['deleted']);
        $this->assertSame(
            [
                [[PermissionTypes::EDIT, PermissionTypes::DELETE], Page::class, 'uuid-1'],
            ],
            \array_map(static fn (array $c): array => [$c['permissions'], $c['objectType'], $c['objectId']], $this->permissionChecker->calls()),
        );
    }

    public function testDeletePageDispatchesWhenAllDescendantsGranted(): void
    {
        $this->setupEntity('page');

        $this->security->getUser(Argument::cetera())->willReturn(new TestUser());
        $this->pageRepository->findDescendantIdsById(Argument::cetera())->willReturn(['child-1', 'child-2']);
        $this->accessControlRepository->findIdsWithGrantedPermissions(Argument::cetera())->willReturn(['child-1', 'child-2']);

        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(fn (array $args) => $args[0]->with(new HandledStamp(null, 'handler')));

        $result = $this->tool->deleteContent('page', 'uuid-1', 'en', true);

        $this->assertTrue($result['deleted']);
    }

    private function setupEntity(string $type): void
    {
        $entity = match ($type) {
            'article' => new Article('uuid-1'),
            'snippet' => new Snippet('uuid-1'),
            default => (new Page('uuid-1'))->setWebspaceKey('example'),
        };

        match ($type) {
            'article' => $this->articleRepository->getOneBy(Argument::cetera())->willReturn($entity),
            'snippet' => $this->snippetRepository->getOneBy(Argument::cetera())->willReturn($entity),
            default => $this->pageRepository->getOneBy(Argument::cetera())->willReturn($entity),
        };

        if ('article' === $type) {
            $dimensionContent = new ArticleDimensionContent($entity);
            $dimensionContent->setTemplateKey('default');
            $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        }
    }
}
