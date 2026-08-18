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

namespace Sulu\Mcp\Tests\Unit\Application\Content;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Article\Application\Message\ApplyWorkflowTransitionArticleMessage;
use Sulu\Article\Application\Message\ModifyArticleMessage;
use Sulu\Article\Application\Message\RemoveArticleMessage;
use Sulu\Article\Domain\Model\Article;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Mcp\Application\Content\ContentTypeResolver;
use Sulu\Page\Application\Message\ApplyWorkflowTransitionPageMessage;
use Sulu\Page\Application\Message\ModifyPageMessage;
use Sulu\Page\Application\Message\RemovePageMessage;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Sulu\Snippet\Application\Message\ApplyWorkflowTransitionSnippetMessage;
use Sulu\Snippet\Application\Message\ModifySnippetMessage;
use Sulu\Snippet\Application\Message\RemoveSnippetMessage;
use Sulu\Snippet\Domain\Model\Snippet;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;

#[CoversClass(ContentTypeResolver::class)]
final class ContentTypeResolverTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<PageRepositoryInterface> */
    private ObjectProphecy $pageRepository;
    /** @var ObjectProphecy<ArticleRepositoryInterface> */
    private ObjectProphecy $articleRepository;
    /** @var ObjectProphecy<SnippetRepositoryInterface> */
    private ObjectProphecy $snippetRepository;
    private ContentTypeResolver $resolver;

    protected function setUp(): void
    {
        $this->pageRepository = $this->prophesize(PageRepositoryInterface::class);
        $this->articleRepository = $this->prophesize(ArticleRepositoryInterface::class);
        $this->snippetRepository = $this->prophesize(SnippetRepositoryInterface::class);
        $this->resolver = new ContentTypeResolver(
            $this->pageRepository->reveal(),
            $this->articleRepository->reveal(),
            $this->snippetRepository->reveal(),
        );
    }

    public function testSupportsKnownContentTypes(): void
    {
        $this->assertTrue($this->resolver->supports('page'));
        $this->assertTrue($this->resolver->supports('article'));
        $this->assertTrue($this->resolver->supports('snippet'));
        $this->assertFalse($this->resolver->supports('media'));
        $this->assertFalse($this->resolver->supports(''));
    }

    public function testLoadDraftLoadsPageFromPageRepository(): void
    {
        $page = new Page();
        $page->setWebspaceKey('example');
        $this->pageRepository->getOneBy(Argument::cetera())->shouldBeCalledOnce()->willReturn($page);
        $this->articleRepository->getOneBy(Argument::cetera())->shouldNotBeCalled();
        $this->snippetRepository->getOneBy(Argument::cetera())->shouldNotBeCalled();

        $this->assertSame($page, $this->resolver->loadDraft('page', 'uuid-1', 'en'));
    }

    public function testLoadDraftLoadsArticleFromArticleRepository(): void
    {
        $article = new Article();
        $this->articleRepository->getOneBy(Argument::cetera())->shouldBeCalledOnce()->willReturn($article);
        $this->pageRepository->getOneBy(Argument::cetera())->shouldNotBeCalled();
        $this->snippetRepository->getOneBy(Argument::cetera())->shouldNotBeCalled();

        $this->assertSame($article, $this->resolver->loadDraft('article', 'uuid-1', 'en'));
    }

    public function testLoadDraftLoadsSnippetFromSnippetRepository(): void
    {
        $snippet = new Snippet();
        $this->snippetRepository->getOneBy(Argument::cetera())->shouldBeCalledOnce()->willReturn($snippet);
        $this->pageRepository->getOneBy(Argument::cetera())->shouldNotBeCalled();
        $this->articleRepository->getOneBy(Argument::cetera())->shouldNotBeCalled();

        $this->assertSame($snippet, $this->resolver->loadDraft('snippet', 'uuid-1', 'en'));
    }

    public function testLoadDraftReturnsNullForUnsupportedType(): void
    {
        $this->pageRepository->getOneBy(Argument::cetera())->shouldNotBeCalled();
        $this->articleRepository->getOneBy(Argument::cetera())->shouldNotBeCalled();
        $this->snippetRepository->getOneBy(Argument::cetera())->shouldNotBeCalled();

        $this->assertNull($this->resolver->loadDraft('media', 'uuid-1', 'en'));
    }

    public function testLoadDraftReturnsNullWhenRepositoryThrows(): void
    {
        $this->pageRepository->getOneBy(Argument::cetera())->willThrow(new \RuntimeException('not found'));

        $this->assertNull($this->resolver->loadDraft('page', 'missing', 'en'));
    }

    public function testCreateModifyMessageReturnsPageMessage(): void
    {
        $message = $this->resolver->createModifyMessage('page', 'uuid-1', ['locale' => 'en']);

        $this->assertInstanceOf(ModifyPageMessage::class, $message);
        $this->assertSame(['uuid' => 'uuid-1'], $message->getIdentifier());
        $this->assertSame(['locale' => 'en'], $message->getData());
    }

    public function testCreateModifyMessageReturnsArticleMessage(): void
    {
        $message = $this->resolver->createModifyMessage('article', 'uuid-1', ['locale' => 'en']);

        $this->assertInstanceOf(ModifyArticleMessage::class, $message);
    }

    public function testCreateModifyMessageReturnsSnippetMessage(): void
    {
        $message = $this->resolver->createModifyMessage('snippet', 'uuid-1', ['locale' => 'en']);

        $this->assertInstanceOf(ModifySnippetMessage::class, $message);
    }

    public function testCreateModifyMessageThrowsForUnsupportedType(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->resolver->createModifyMessage('media', 'uuid-1', ['locale' => 'en']);
    }

    public function testCreateRemoveMessageBuildsPerTypeMessage(): void
    {
        $this->assertInstanceOf(
            RemovePageMessage::class,
            $this->resolver->createRemoveMessage('page', 'uuid-1', 'en', true),
        );
        $this->assertInstanceOf(
            RemoveArticleMessage::class,
            $this->resolver->createRemoveMessage('article', 'uuid-1', 'en'),
        );
        $this->assertInstanceOf(
            RemoveSnippetMessage::class,
            $this->resolver->createRemoveMessage('snippet', 'uuid-1', 'en'),
        );
    }

    public function testCreateTransitionMessageBuildsPerTypeMessage(): void
    {
        $this->assertInstanceOf(
            ApplyWorkflowTransitionPageMessage::class,
            $this->resolver->createTransitionMessage('page', 'uuid-1', 'en', 'publish'),
        );
        $this->assertInstanceOf(
            ApplyWorkflowTransitionArticleMessage::class,
            $this->resolver->createTransitionMessage('article', 'uuid-1', 'en', 'publish'),
        );
        $this->assertInstanceOf(
            ApplyWorkflowTransitionSnippetMessage::class,
            $this->resolver->createTransitionMessage('snippet', 'uuid-1', 'en', 'unpublish'),
        );
    }

    public function testCreateRemoveMessageThrowsForUnsupportedType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->resolver->createRemoveMessage('contact', 'uuid-1', 'en');
    }

    public function testCreateTransitionMessageThrowsForUnsupportedType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->resolver->createTransitionMessage('contact', 'uuid-1', 'en', 'publish');
    }
}
