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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sulu\Article\Application\Message\RemoveArticleMessage;
use Sulu\Article\Domain\Model\ArticleInterface;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Bundle\AdminBundle\Metadata\GroupProviderInterface;
use Sulu\Bundle\SecurityBundle\System\SystemStoreInterface;
use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Component\Security\Authorization\AccessControl\AccessControlRepositoryInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\TemplateInterface;
use Sulu\Mcp\Application\Content\ContentTypeResolver;
use Sulu\Mcp\Application\Security\ContentSecurityContextResolver;
use Sulu\Mcp\Application\Security\PageDescendantPermissionChecker;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\UserInterface\Mcp\Tool\Content\ContentDeleteTool;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Page\Application\Message\RemovePageMessage;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Sulu\Snippet\Application\Message\RemoveSnippetMessage;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[CoversClass(ContentDeleteTool::class)]
final class ContentDeleteToolTest extends TestCase
{
    private MessageBusInterface&MockObject $messageBus;
    private PageRepositoryInterface&MockObject $pageRepository;
    private ArticleRepositoryInterface&MockObject $articleRepository;
    private SnippetRepositoryInterface&MockObject $snippetRepository;
    private ContentManagerInterface&MockObject $contentManager;
    private ToolPermissionCheckerInterface&MockObject $permissionChecker;
    private AccessControlRepositoryInterface&MockObject $accessControlRepository;
    private Security&MockObject $security;
    private ContentDeleteTool $tool;

    protected function setUp(): void
    {
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->pageRepository = $this->createMock(PageRepositoryInterface::class);
        $this->articleRepository = $this->createMock(ArticleRepositoryInterface::class);
        $this->snippetRepository = $this->createMock(SnippetRepositoryInterface::class);
        $this->contentManager = $this->createMock(ContentManagerInterface::class);
        $this->permissionChecker = $this->createMock(ToolPermissionCheckerInterface::class);
        $this->accessControlRepository = $this->createMock(AccessControlRepositoryInterface::class);
        $this->security = $this->createMock(Security::class);
        $groupProvider = $this->createMock(GroupProviderInterface::class);
        $groupProvider->method('getGroups')->willReturn([]);
        $systemStore = $this->createMock(SystemStoreInterface::class);
        $systemStore->method('getSystem')->willReturn('Sulu');

        $pageDescendantPermissionChecker = new PageDescendantPermissionChecker(
            $this->pageRepository,
            $this->accessControlRepository,
            $systemStore,
            $this->security,
            [PermissionTypes::DELETE => 8],
        );

        $this->tool = new ContentDeleteTool(
            $this->messageBus,
            new ContentTypeResolver($this->pageRepository, $this->articleRepository, $this->snippetRepository),
            $this->contentManager,
            $this->permissionChecker,
            new ContentSecurityContextResolver(new ArticleSecurityContextResolver($groupProvider)),
            $pageDescendantPermissionChecker,
        );
    }

    public function testDeletePageDispatchesRemovePageMessageWithFlushStamp(): void
    {
        $this->setupEntity('page');

        $user = $this->createMock(UserInterface::class);
        $this->security->method('getUser')->willReturn($user);
        $this->pageRepository->method('findDescendantIdsById')->willReturn([]);

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (Envelope $envelope) {
                $this->assertInstanceOf(RemovePageMessage::class, $envelope->getMessage());
                $this->assertArrayHasKey(EnableFlushStamp::class, $envelope->all());

                return $envelope->with(new HandledStamp(null, 'handler'));
            });

        $result = $this->tool->deleteContent('page', 'uuid-1', 'en', true);

        $this->assertSame(['success' => true, 'type' => 'page', 'uuid' => 'uuid-1', 'deleted' => true], $result);
    }

    public function testDeleteSnippetDispatchesRemoveSnippetMessage(): void
    {
        $this->setupEntity('snippet');

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (Envelope $envelope) {
                $this->assertInstanceOf(RemoveSnippetMessage::class, $envelope->getMessage());

                return $envelope->with(new HandledStamp(null, 'handler'));
            });

        $result = $this->tool->deleteContent('snippet', 'uuid-2', 'en');

        $this->assertTrue($result['deleted']);
    }

    public function testDeleteArticleDispatchesRemoveArticleMessage(): void
    {
        $this->setupEntity('article');

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (Envelope $envelope) {
                $this->assertInstanceOf(RemoveArticleMessage::class, $envelope->getMessage());

                return $envelope->with(new HandledStamp(null, 'handler'));
            });

        $result = $this->tool->deleteContent('article', 'uuid-3', 'en');

        $this->assertTrue($result['deleted']);
    }

    public function testUnsupportedTypeReturnsErrorWithoutDispatch(): void
    {
        $this->messageBus->expects($this->never())->method('dispatch');

        $result = $this->tool->deleteContent('contact', 'uuid-1', 'en');

        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('success', $result);
    }

    public function testEntityNotFoundReturnsErrorWithoutDispatch(): void
    {
        $this->articleRepository->method('getOneBy')->willThrowException(new \RuntimeException('not found'));
        $this->messageBus->expects($this->never())->method('dispatch');

        $result = $this->tool->deleteContent('article', 'missing-uuid', 'en');

        $this->assertArrayHasKey('error', $result);
    }

    public function testErrorOnException(): void
    {
        $this->setupEntity('article');
        $this->messageBus->method('dispatch')->willThrowException(new \RuntimeException('boom'));

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

        $this->permissionChecker
            ->method('check')
            ->willThrowException(new PermissionDeniedException('sulu.webspaces.example', PermissionTypes::DELETE, 'en'));

        $this->messageBus->expects($this->never())->method('dispatch');

        $this->expectException(ToolCallException::class);

        $this->tool->deleteContent('page', 'uuid-1', 'en');
    }

    public function testDeletePageThrowsToolCallExceptionWhenDescendantPermissionDenied(): void
    {
        $this->setupEntity('page');

        $user = $this->createMock(UserInterface::class);
        $this->security->method('getUser')->willReturn($user);
        $this->pageRepository->method('findDescendantIdsById')->willReturn(['child-1', 'child-2']);
        // Only child-1 is granted DELETE — child-2 is missing, so the gate must trip.
        $this->accessControlRepository->method('findIdsWithGrantedPermissions')->willReturn(['child-1']);

        $this->messageBus->expects($this->never())->method('dispatch');

        $this->expectException(ToolCallException::class);

        $this->tool->deleteContent('page', 'uuid-1', 'en', true);
    }

    public function testDeleteContentPassesConcretePageClassAsObjectTypeForBothChecks(): void
    {
        // Regression guard: Sulu stores per-page ACLs under the concrete Page class
        // (getSecuredClass()), not PageInterface — the interface matches no ACL row and
        // falls back to the webspace grant, for both the EDIT and DELETE check.
        $this->setupEntity('page');

        $user = $this->createMock(UserInterface::class);
        $this->security->method('getUser')->willReturn($user);
        $this->pageRepository->method('findDescendantIdsById')->willReturn([]);
        $this->messageBus->method('dispatch')
            ->willReturnCallback(fn (Envelope $envelope) => $envelope->with(new HandledStamp(null, 'handler')));

        $recordedCalls = [];
        $this->permissionChecker
            ->expects($this->once())
            ->method('check')
            ->willReturnCallback(function (string $context, string|array $permissions, ?string $locale, ?string $objectType, mixed $objectId) use (&$recordedCalls): void {
                $recordedCalls[] = [$permissions, $objectType, $objectId];
            });

        $result = $this->tool->deleteContent('page', 'uuid-1', 'en');

        $this->assertTrue($result['deleted']);
        $this->assertSame(
            [
                [[PermissionTypes::EDIT, PermissionTypes::DELETE], Page::class, 'uuid-1'],
            ],
            $recordedCalls,
        );
    }

    public function testDeletePageDispatchesWhenAllDescendantsGranted(): void
    {
        $this->setupEntity('page');

        $user = $this->createMock(UserInterface::class);
        $this->security->method('getUser')->willReturn($user);
        $this->pageRepository->method('findDescendantIdsById')->willReturn(['child-1', 'child-2']);
        $this->accessControlRepository->method('findIdsWithGrantedPermissions')->willReturn(['child-1', 'child-2']);

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(fn (Envelope $envelope) => $envelope->with(new HandledStamp(null, 'handler')));

        $result = $this->tool->deleteContent('page', 'uuid-1', 'en', true);

        $this->assertTrue($result['deleted']);
    }

    private function setupEntity(string $type): void
    {
        $entity = $this->createMock(match ($type) {
            'page' => PageInterface::class,
            'article' => ArticleInterface::class,
            'snippet' => SnippetInterface::class,
            default => PageInterface::class,
        });

        match ($type) {
            'article' => $this->articleRepository->method('getOneBy')->willReturn($entity),
            'snippet' => $this->snippetRepository->method('getOneBy')->willReturn($entity),
            default => $this->pageRepository->method('getOneBy')->willReturn($entity),
        };

        if ('article' === $type) {
            $dimensionContent = $this->createMockForIntersectionOfInterfaces([TemplateInterface::class, DimensionContentInterface::class]);
            $dimensionContent->method('getTemplateKey')->willReturn('default');
            $this->contentManager->method('resolve')->willReturn($dimensionContent);
        }
    }
}
