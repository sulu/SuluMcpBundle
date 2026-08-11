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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sulu\Article\Application\Message\ApplyWorkflowTransitionArticleMessage;
use Sulu\Article\Application\Message\ModifyArticleMessage;
use Sulu\Article\Application\Message\RemoveArticleMessage;
use Sulu\Article\Domain\Model\ArticleInterface;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Mcp\Application\Content\ContentTypeResolver;
use Sulu\Page\Application\Message\ApplyWorkflowTransitionPageMessage;
use Sulu\Page\Application\Message\ModifyPageMessage;
use Sulu\Page\Application\Message\RemovePageMessage;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Sulu\Snippet\Application\Message\ApplyWorkflowTransitionSnippetMessage;
use Sulu\Snippet\Application\Message\ModifySnippetMessage;
use Sulu\Snippet\Application\Message\RemoveSnippetMessage;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;

#[CoversClass(ContentTypeResolver::class)]
final class ContentTypeResolverTest extends TestCase
{
    private PageRepositoryInterface&MockObject $pageRepository;
    private ArticleRepositoryInterface&MockObject $articleRepository;
    private SnippetRepositoryInterface&MockObject $snippetRepository;
    private ContentTypeResolver $resolver;

    protected function setUp(): void
    {
        $this->pageRepository = $this->createMock(PageRepositoryInterface::class);
        $this->articleRepository = $this->createMock(ArticleRepositoryInterface::class);
        $this->snippetRepository = $this->createMock(SnippetRepositoryInterface::class);
        $this->resolver = new ContentTypeResolver(
            $this->pageRepository,
            $this->articleRepository,
            $this->snippetRepository,
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
        $page = $this->createMock(PageInterface::class);
        $this->pageRepository->expects($this->once())->method('getOneBy')->willReturn($page);
        $this->articleRepository->expects($this->never())->method('getOneBy');
        $this->snippetRepository->expects($this->never())->method('getOneBy');

        $this->assertSame($page, $this->resolver->loadDraft('page', 'uuid-1', 'en'));
    }

    public function testLoadDraftLoadsArticleFromArticleRepository(): void
    {
        $article = $this->createMock(ArticleInterface::class);
        $this->articleRepository->expects($this->once())->method('getOneBy')->willReturn($article);
        $this->pageRepository->expects($this->never())->method('getOneBy');
        $this->snippetRepository->expects($this->never())->method('getOneBy');

        $this->assertSame($article, $this->resolver->loadDraft('article', 'uuid-1', 'en'));
    }

    public function testLoadDraftLoadsSnippetFromSnippetRepository(): void
    {
        $snippet = $this->createMock(SnippetInterface::class);
        $this->snippetRepository->expects($this->once())->method('getOneBy')->willReturn($snippet);
        $this->pageRepository->expects($this->never())->method('getOneBy');
        $this->articleRepository->expects($this->never())->method('getOneBy');

        $this->assertSame($snippet, $this->resolver->loadDraft('snippet', 'uuid-1', 'en'));
    }

    public function testLoadDraftReturnsNullForUnsupportedType(): void
    {
        $this->pageRepository->expects($this->never())->method('getOneBy');
        $this->articleRepository->expects($this->never())->method('getOneBy');
        $this->snippetRepository->expects($this->never())->method('getOneBy');

        $this->assertNull($this->resolver->loadDraft('media', 'uuid-1', 'en'));
    }

    public function testLoadDraftReturnsNullWhenRepositoryThrows(): void
    {
        $this->pageRepository->method('getOneBy')->willThrowException(new \RuntimeException('not found'));

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
