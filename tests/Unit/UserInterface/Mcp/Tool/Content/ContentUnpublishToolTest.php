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
use Sulu\Article\Domain\Model\Article;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Mcp\Application\Content\ContentTypeResolver;
use Sulu\Mcp\Application\Security\ContentSecurityContextResolver;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\Tests\Application\TestBundle\Metadata\TestGroupProvider;
use Sulu\Mcp\Tests\Unit\Fixture\FakeToolPermissionChecker;
use Sulu\Mcp\UserInterface\Mcp\Tool\Content\ContentUnpublishTool;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Model\PageDimensionContent;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Sulu\Snippet\Application\Message\ApplyWorkflowTransitionSnippetMessage;
use Sulu\Snippet\Domain\Model\Snippet;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[CoversClass(ContentUnpublishTool::class)]
final class ContentUnpublishToolTest extends TestCase
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
    private ContentUnpublishTool $tool;

    protected function setUp(): void
    {
        $this->messageBus = $this->prophesize(MessageBusInterface::class);
        $this->pageRepository = $this->prophesize(PageRepositoryInterface::class);
        $this->articleRepository = $this->prophesize(ArticleRepositoryInterface::class);
        $this->snippetRepository = $this->prophesize(SnippetRepositoryInterface::class);
        $this->contentManager = $this->prophesize(ContentManagerInterface::class);
        $this->permissionChecker = FakeToolPermissionChecker::grantingAll();
        $groupProvider = new TestGroupProvider([]);

        $this->tool = new ContentUnpublishTool(
            $this->messageBus->reveal(),
            new ContentTypeResolver($this->pageRepository->reveal(), $this->articleRepository->reveal(), $this->snippetRepository->reveal()),
            $this->contentManager->reveal(),
            $this->permissionChecker,
            new ContentSecurityContextResolver(new ArticleSecurityContextResolver($groupProvider), $this->contentManager->reveal()),
        );
    }

    public function testUnpublishSnippetDispatchesTransition(): void
    {
        $this->setupEntity('snippet');

        $captured = new \stdClass();
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use ($captured) {
                $captured->envelope = $args[0];

                return $args[0]->with(new HandledStamp(null, 'handler'));
            });

        $result = $this->tool->unpublishContent('snippet', 'uuid-1', 'en');

        $this->assertInstanceOf(ApplyWorkflowTransitionSnippetMessage::class, $captured->envelope->getMessage());
        $this->assertArrayHasKey(EnableFlushStamp::class, $captured->envelope->all());
        $this->assertSame('unpublished', $result['action']);
    }

    public function testUnsupportedTypeReturnsError(): void
    {
        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();
        $this->assertArrayHasKey('error', $this->tool->unpublishContent('media', 'uuid-1', 'en'));
    }

    public function testEntityNotFoundReturnsErrorWithoutDispatch(): void
    {
        $this->snippetRepository->getOneBy(Argument::cetera())->willThrow(new \RuntimeException('not found'));
        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->unpublishContent('snippet', 'missing-uuid', 'en');

        $this->assertArrayHasKey('error', $result);
    }

    public function testMethodHasMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionMethod(ContentUnpublishTool::class, 'unpublishContent'))->getAttributes(McpTool::class);
        $this->assertSame('sulu_content_unpublish', $attributes[0]->newInstance()->name);
    }

    public function testUnpublishContentThrowsToolCallExceptionWhenPermissionDenied(): void
    {
        $this->setupEntity('snippet');

        $this->permissionChecker->denyAll();

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(ToolCallException::class);

        $this->tool->unpublishContent('snippet', 'uuid-1', 'en');
    }

    private function setupEntity(string $type): void
    {
        $entity = match ($type) {
            'article' => new Article('uuid-1'),
            'snippet' => new Snippet('uuid-1'),
            default => (static function(): Page {
                $page = new Page('uuid-1');
                $page->setWebspaceKey('example');

                return $page;
            })(),
        };

        match ($type) {
            'article' => $this->articleRepository->getOneBy(Argument::cetera())->willReturn($entity),
            'snippet' => $this->snippetRepository->getOneBy(Argument::cetera())->willReturn($entity),
            default => $this->pageRepository->getOneBy(Argument::cetera())->willReturn($entity),
        };

        if ('article' === $type) {
            $dimensionContent = new PageDimensionContent(new Page());
            $dimensionContent->setTemplateKey('default');
            $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        }
    }
}
