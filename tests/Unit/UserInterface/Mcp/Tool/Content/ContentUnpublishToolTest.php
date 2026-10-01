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
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormGroup;
use Sulu\Mcp\Application\Security\ContentSecurityContextResolver;
use Sulu\Mcp\Infrastructure\Sulu\Security\SnippetSecurityContextResolver;
use Sulu\Mcp\Tests\Application\TestBundle\Metadata\TestGroupProvider;
use Sulu\Mcp\Tests\Unit\Fixture\ContentTypes;
use Sulu\Mcp\Tests\Unit\Fixture\FakeToolPermissionChecker;
use Sulu\Mcp\UserInterface\Mcp\Tool\Content\ContentUnpublishTool;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Sulu\Snippet\Application\Message\ApplyWorkflowTransitionSnippetMessage;
use Sulu\Snippet\Domain\Model\Snippet;
use Sulu\Snippet\Domain\Model\SnippetDimensionContent;
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

    private FakeToolPermissionChecker $permissionChecker;
    private ContentUnpublishTool $tool;

    protected function setUp(): void
    {
        $this->messageBus = $this->prophesize(MessageBusInterface::class);
        $this->pageRepository = $this->prophesize(PageRepositoryInterface::class);
        $this->articleRepository = $this->prophesize(ArticleRepositoryInterface::class);
        $this->snippetRepository = $this->prophesize(SnippetRepositoryInterface::class);
        $this->permissionChecker = FakeToolPermissionChecker::grantingAll();
        $groupProvider = new TestGroupProvider([]);

        $this->tool = new ContentUnpublishTool(
            $this->messageBus->reveal(),
            ContentTypes::resolver($this->pageRepository->reveal(), $this->articleRepository->reveal(), $this->snippetRepository->reveal(), $groupProvider),
            $this->permissionChecker,
            ContentTypes::securityResolver($groupProvider),
        );
    }

    public function testUnpublishSnippetDispatchesTransition(): void
    {
        $this->setupEntity('snippets');

        $captured = new \stdClass();
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use ($captured) {
                $captured->envelope = $args[0];

                return $args[0]->with(new HandledStamp(null, 'handler'));
            });

        $result = $this->tool->unpublishContent('snippets', 'uuid-1', 'en');

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

        $result = $this->tool->unpublishContent('snippets', 'missing-uuid', 'en');

        $this->assertArrayHasKey('error', $result);
    }

    public function testMethodHasMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionMethod(ContentUnpublishTool::class, 'unpublishContent'))->getAttributes(McpTool::class);
        $this->assertSame('sulu_content_unpublish', $attributes[0]->newInstance()->name);
    }

    public function testUnpublishContentThrowsToolCallExceptionWhenPermissionDenied(): void
    {
        $this->setupEntity('snippets');

        $this->permissionChecker->denyAll();

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(ToolCallException::class);

        $this->tool->unpublishContent('snippets', 'uuid-1', 'en');
    }

    public function testUnpublishSnippetOfAGroupTheRoleHolds(): void
    {
        $this->useTwoSnippetGroups();
        $this->setupSnippetWithTemplate('promo');
        $this->permissionChecker->grantingNoneExcept()->grantContext('sulu.snippet.snippets_marketing');

        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(fn (array $args) => $args[0]->with(new HandledStamp(null, 'handler')));

        $result = $this->tool->unpublishContent('snippets', 'uuid-1', 'en');

        $this->assertTrue($result['success']);
    }

    public function testUnpublishSnippetIsDeniedWithoutItsGroup(): void
    {
        $this->useTwoSnippetGroups();
        $this->setupSnippetWithTemplate('promo');
        $this->permissionChecker->grantingNoneExcept()->grantContext('sulu.snippet.snippets');

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('security context "sulu.snippet.snippets_marketing"');

        $this->tool->unpublishContent('snippets', 'uuid-1', 'en');
    }

    /**
     * Rebuilds the tool over a two-group install: `default` (template "default") and
     * `marketing` (template "promo").
     */
    private function useTwoSnippetGroups(): void
    {
        $contentTypeResolver = ContentTypes::snippetResolver($this->snippetRepository->reveal(), new SnippetSecurityContextResolver(new TestGroupProvider([
            (new FormGroup('default', 'Default'))->withTemplate('default'),
            (new FormGroup('marketing', 'Marketing'))->withTemplate('promo'),
        ]), true));

        $this->tool = new ContentUnpublishTool(
            $this->messageBus->reveal(),
            $contentTypeResolver,
            $this->permissionChecker,
            new ContentSecurityContextResolver($contentTypeResolver),
        );
    }

    private function setupSnippetWithTemplate(string $templateKey): void
    {
        $snippet = new Snippet('uuid-1');
        $dimensionContent = new SnippetDimensionContent($snippet);
        $dimensionContent->setLocale('en');
        $dimensionContent->setTemplateKey($templateKey);
        $snippet->addDimensionContent($dimensionContent);

        $this->snippetRepository->getOneBy(Argument::cetera())->willReturn($snippet);
    }

    private function setupEntity(string $type): void
    {
        $entity = match ($type) {
            'articles' => new Article('uuid-1'),
            'snippets' => new Snippet('uuid-1'),
            default => (static function(): Page {
                $page = new Page('uuid-1');
                $page->setWebspaceKey('example');

                return $page;
            })(),
        };

        match ($type) {
            'articles' => $this->articleRepository->getOneBy(Argument::cetera())->willReturn($entity),
            'snippets' => $this->snippetRepository->getOneBy(Argument::cetera())->willReturn($entity),
            default => $this->pageRepository->getOneBy(Argument::cetera())->willReturn($entity),
        };

        if ('articles' === $type) {
            $dimensionContent = new ArticleDimensionContent($entity);
            $dimensionContent->setTemplateKey('default');
            $entity->addDimensionContent($dimensionContent);
        }
    }
}
