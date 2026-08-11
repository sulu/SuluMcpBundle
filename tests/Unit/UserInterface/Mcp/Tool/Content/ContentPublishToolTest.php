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
use Sulu\Article\Domain\Model\ArticleInterface;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Bundle\AdminBundle\Metadata\GroupProviderInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\TemplateInterface;
use Sulu\Mcp\Application\Content\ContentTypeResolver;
use Sulu\Mcp\Application\Security\ContentSecurityContextResolver;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\UserInterface\Mcp\Tool\Content\ContentPublishTool;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Page\Application\Message\ApplyWorkflowTransitionPageMessage;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[CoversClass(ContentPublishTool::class)]
final class ContentPublishToolTest extends TestCase
{
    private MessageBusInterface&MockObject $messageBus;
    private PageRepositoryInterface&MockObject $pageRepository;
    private ArticleRepositoryInterface&MockObject $articleRepository;
    private SnippetRepositoryInterface&MockObject $snippetRepository;
    private ContentManagerInterface&MockObject $contentManager;
    private ToolPermissionCheckerInterface&MockObject $permissionChecker;
    private ContentPublishTool $tool;

    protected function setUp(): void
    {
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->pageRepository = $this->createMock(PageRepositoryInterface::class);
        $this->articleRepository = $this->createMock(ArticleRepositoryInterface::class);
        $this->snippetRepository = $this->createMock(SnippetRepositoryInterface::class);
        $this->contentManager = $this->createMock(ContentManagerInterface::class);
        $this->permissionChecker = $this->createMock(ToolPermissionCheckerInterface::class);
        $groupProvider = $this->createMock(GroupProviderInterface::class);
        $groupProvider->method('getGroups')->willReturn([]);

        $this->tool = new ContentPublishTool(
            $this->messageBus,
            new ContentTypeResolver($this->pageRepository, $this->articleRepository, $this->snippetRepository),
            $this->contentManager,
            $this->permissionChecker,
            new ContentSecurityContextResolver(new ArticleSecurityContextResolver($groupProvider)),
        );
    }

    public function testPublishPageDispatchesTransitionWithPublishName(): void
    {
        $this->setupEntity('page');

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (Envelope $envelope) {
                $message = $envelope->getMessage();
                $this->assertInstanceOf(ApplyWorkflowTransitionPageMessage::class, $message);
                $this->assertArrayHasKey(EnableFlushStamp::class, $envelope->all());

                return $envelope->with(new HandledStamp(null, 'handler'));
            });

        $result = $this->tool->publishContent('page', 'uuid-1', 'en');

        $this->assertSame(['success' => true, 'type' => 'page', 'uuid' => 'uuid-1', 'action' => 'published', 'locale' => 'en'], $result);
    }

    public function testUnsupportedTypeReturnsError(): void
    {
        $this->messageBus->expects($this->never())->method('dispatch');
        $this->assertArrayHasKey('error', $this->tool->publishContent('media', 'uuid-1', 'en'));
    }

    public function testEntityNotFoundReturnsErrorWithoutDispatch(): void
    {
        $this->pageRepository->method('getOneBy')->willThrowException(new \RuntimeException('not found'));
        $this->messageBus->expects($this->never())->method('dispatch');

        $result = $this->tool->publishContent('page', 'missing-uuid', 'en');

        $this->assertArrayHasKey('error', $result);
    }

    public function testErrorOnException(): void
    {
        $this->setupEntity('article');
        $this->messageBus->method('dispatch')->willThrowException(new \RuntimeException('boom'));
        $this->assertStringContainsString('boom', $this->tool->publishContent('article', 'uuid-1', 'en')['error']);
    }

    public function testMethodHasMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionMethod(ContentPublishTool::class, 'publishContent'))->getAttributes(McpTool::class);
        $this->assertSame('sulu_content_publish', $attributes[0]->newInstance()->name);
    }

    public function testPublishContentThrowsToolCallExceptionWhenPermissionDenied(): void
    {
        $this->setupEntity('page');

        $this->permissionChecker
            ->method('check')
            ->willThrowException(new PermissionDeniedException('sulu.webspaces.example', PermissionTypes::LIVE, 'en'));

        $this->messageBus->expects($this->never())->method('dispatch');

        $this->expectException(ToolCallException::class);

        $this->tool->publishContent('page', 'uuid-1', 'en');
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
