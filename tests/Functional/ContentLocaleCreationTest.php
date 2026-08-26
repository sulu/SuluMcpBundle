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
use PHPUnit\Framework\Attributes\CoversNothing;
use Sulu\Article\Application\Message\CreateArticleMessage;
use Sulu\Article\Domain\Model\ArticleInterface;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Bundle\SecurityBundle\System\SystemStoreInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\UserInterface\Mcp\Tool\Article\ArticleUpdateTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Block\BlockListTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Page\PageUpdateTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Snippet\SnippetUpdateTool;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Page\Application\Message\CreatePageMessage;
use Sulu\Page\Application\Message\ModifyPageMessage;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Sulu\Snippet\Application\Message\CreateSnippetMessage;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Updating content in a locale it has not been translated to yet must create
 * that locale, the same way the admin UI does when a ghost page is saved.
 */
#[CoversNothing]
final class ContentLocaleCreationTest extends FunctionalTestCase
{
    private const ALL_GRANTED = [
        PermissionTypes::VIEW => true, PermissionTypes::ADD => true, PermissionTypes::EDIT => true,
        PermissionTypes::DELETE => true, PermissionTypes::ARCHIVE => true, PermissionTypes::LIVE => true,
        PermissionTypes::SECURITY => true,
    ];

    public function testPageUpdateCreatesMissingLocale(): void
    {
        $this->authenticateEditor();
        $uuid = $this->createGermanPage();

        /** @var PageUpdateTool $tool */
        $tool = self::getContainer()->get(PageUpdateTool::class);

        $result = $tool->updatePage(
            $uuid,
            'en',
            title: 'English Page',
            url: '/english-page',
            template: 'default',
            content: ['article' => '<p>English body</p>'],
        );

        self::assertArrayNotHasKey('error', $result, (string) ($result['error'] ?? ''));
        self::assertTrue($result['success'] ?? false);

        $this->entityManager->clear();

        /** @var PageRepositoryInterface $pageRepository */
        $pageRepository = self::getContainer()->get('sulu_page.page_repository');
        $page = $pageRepository->getOneBy(
            ['uuid' => $uuid, 'locale' => 'en', 'stage' => DimensionContentInterface::STAGE_DRAFT],
            [PageRepositoryInterface::GROUP_SELECT_PAGE_ADMIN => true],
        );

        /** @var ContentManagerInterface $contentManager */
        $contentManager = self::getContainer()->get(ContentManagerInterface::class);
        $normalized = $contentManager->normalize($contentManager->resolve($page, [
            'locale' => 'en',
            'stage' => DimensionContentInterface::STAGE_DRAFT,
        ]));

        self::assertSame('English Page', $normalized['title'] ?? null);
        // Only what the caller passed -- the de body must not be carried over.
        self::assertSame('<p>English body</p>', $normalized['article'] ?? null);
        self::assertTrue($result['created_locale'] ?? false);

        // Clear first: the page is already managed with the dimension contents the "en" query hydrated.
        $this->entityManager->clear();

        $germanPage = $pageRepository->getOneBy(
            ['uuid' => $uuid, 'locale' => 'de', 'stage' => DimensionContentInterface::STAGE_DRAFT],
            [PageRepositoryInterface::GROUP_SELECT_PAGE_ADMIN => true],
        );
        $germanNormalized = $contentManager->normalize($contentManager->resolve($germanPage, [
            'locale' => 'de',
            'stage' => DimensionContentInterface::STAGE_DRAFT,
        ]));
        self::assertSame('Deutsche Seite', $germanNormalized['title'] ?? null);
        self::assertSame('<p>Deutscher Text</p>', $germanNormalized['article'] ?? null);
    }

    public function testPageUpdateStillUpdatesAnExistingLocale(): void
    {
        $this->authenticateEditor();
        $uuid = $this->createGermanPage();

        /** @var PageUpdateTool $tool */
        $tool = self::getContainer()->get(PageUpdateTool::class);

        $result = $tool->updatePage($uuid, 'de', title: 'Neuer Titel');

        self::assertArrayNotHasKey('error', $result, (string) ($result['error'] ?? ''));
        self::assertTrue($result['success'] ?? false);
        self::assertArrayNotHasKey('created_locale', $result);
        self::assertSame('Neuer Titel', $result['data']['title'] ?? null);
    }

    public function testPageUpdateRefusesIncompleteLocaleCreation(): void
    {
        $this->authenticateEditor();
        $uuid = $this->createGermanPage();

        /** @var PageUpdateTool $tool */
        $tool = self::getContainer()->get(PageUpdateTool::class);

        $result = $tool->updatePage($uuid, 'en', title: 'English Page');

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('has no "en" content yet', $result['error']);
        self::assertStringContainsString('de', $result['hint']);
    }

    public function testBlockToolsNameTheMissingTranslationInsteadOfNotFound(): void
    {
        $this->authenticateEditor();
        $uuid = $this->createGermanPage();

        /** @var BlockListTool $tool */
        $tool = self::getContainer()->get(BlockListTool::class);

        $result = $tool->listBlocks('page', $uuid, 'en', 'blocks');

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('has no "en" content yet', $result['error']);
        self::assertStringContainsString('sulu_page_update', $result['hint']);
    }

    public function testSnippetUpdateCreatesMissingLocale(): void
    {
        $this->authenticateEditor();
        $uuid = $this->createGermanSnippet();

        /** @var SnippetUpdateTool $tool */
        $tool = self::getContainer()->get(SnippetUpdateTool::class);

        $result = $tool->updateSnippet($uuid, 'en', title: 'English Snippet', template: 'default', content: ['description' => '<p>English body</p>']);

        self::assertArrayNotHasKey('error', $result, (string) ($result['error'] ?? ''));
        self::assertTrue($result['success'] ?? false);
        self::assertTrue($result['created_locale'] ?? false);
        self::assertSame('English Snippet', $result['data']['title'] ?? null);
    }

    public function testArticleUpdateCreatesMissingLocale(): void
    {
        $this->authenticateEditor(['sulu.webspaces.website', 'sulu.article.articles_blog']);
        $pageUuid = $this->createGermanPage();
        $this->translatePageToEnglish($pageUuid);
        $uuid = $this->createGermanArticle($pageUuid);

        /** @var ArticleUpdateTool $tool */
        $tool = self::getContainer()->get(ArticleUpdateTool::class);

        $result = $tool->updateArticle(
            $uuid,
            'en',
            title: 'English Article',
            template: 'blog',
            content: [
                'page' => ['uuid' => $pageUuid, 'path' => '/english-page', 'suffix' => 'english-article'],
                'article' => '<p>English body</p>',
            ],
        );

        self::assertArrayNotHasKey('error', $result, (string) ($result['error'] ?? ''));
        self::assertTrue($result['created_locale'] ?? false);

        $this->entityManager->clear();

        /** @var ArticleRepositoryInterface $articleRepository */
        $articleRepository = self::getContainer()->get('sulu_article.article_repository');
        $article = $articleRepository->getOneBy(
            ['uuid' => $uuid, 'locale' => 'en', 'stage' => DimensionContentInterface::STAGE_DRAFT],
            [ArticleRepositoryInterface::GROUP_SELECT_ARTICLE_ADMIN => true],
        );

        /** @var ContentManagerInterface $contentManager */
        $contentManager = self::getContainer()->get(ContentManagerInterface::class);
        $normalized = $contentManager->normalize($contentManager->resolve($article, [
            'locale' => 'en',
            'stage' => DimensionContentInterface::STAGE_DRAFT,
        ]));

        self::assertSame('English Article', $normalized['title'] ?? null);
        self::assertSame('<p>English body</p>', $normalized['article'] ?? null);
        self::assertSame('/english-article', $normalized['url']['suffix'] ?? null);
    }

    public function testArticleUpdateDeniesCreatingALocaleForAnotherGroup(): void
    {
        $this->authenticateEditor(['sulu.webspaces.website', 'sulu.article.articles']);
        $pageUuid = $this->createGermanPage();
        $uuid = $this->createGermanArticle($pageUuid);

        /** @var ArticleUpdateTool $tool */
        $tool = self::getContainer()->get(ArticleUpdateTool::class);

        $this->expectException(ToolCallException::class);

        $tool->updateArticle(
            $uuid,
            'en',
            title: 'English Article',
            template: 'article',
            content: [
                'page' => ['uuid' => $pageUuid, 'path' => '/', 'suffix' => '/english-article'],
            ],
        );
    }

    public function testArticleUpdateReportsRoutingThatDidNotResolve(): void
    {
        $this->authenticateEditor(['sulu.webspaces.website', 'sulu.article.articles_blog']);
        $pageUuid = $this->createGermanPage();
        $uuid = $this->createGermanArticle($pageUuid);

        /** @var ArticleUpdateTool $tool */
        $tool = self::getContainer()->get(ArticleUpdateTool::class);

        // The parent page has no "en" route, so Sulu resolves the article's url to null.
        $result = $tool->updateArticle(
            $uuid,
            'en',
            title: 'English Article',
            template: 'blog',
            content: ['page' => ['uuid' => $pageUuid, 'path' => '/', 'suffix' => 'english-article']],
        );

        self::assertArrayHasKey('error', $result);
        self::assertArrayNotHasKey('created_locale', $result);
    }

    private function translatePageToEnglish(string $pageUuid): void
    {
        /** @var MessageBusInterface $messageBus */
        $messageBus = self::getContainer()->get(MessageBusInterface::class);

        $messageBus->dispatch(new Envelope(
            new ModifyPageMessage(['uuid' => $pageUuid], [
                'locale' => 'en',
                'template' => 'default',
                'title' => 'English Page',
                'url' => '/english-page',
            ]),
            [new EnableFlushStamp()],
        ));
    }

    private function createGermanArticle(string $pageUuid): string
    {
        /** @var MessageBusInterface $messageBus */
        $messageBus = self::getContainer()->get(MessageBusInterface::class);

        $envelope = $messageBus->dispatch(new Envelope(
            new CreateArticleMessage([
                'locale' => 'de',
                'template' => 'blog',
                'title' => 'Deutscher Artikel',
                'url' => [
                    'page' => ['uuid' => $pageUuid, 'path' => '/'],
                    'suffix' => '/deutscher-artikel',
                ],
                'article' => '<p>Deutscher Text</p>',
            ]),
            [new EnableFlushStamp()],
        ));

        /** @var HandledStamp $stamp */
        $stamp = $envelope->last(HandledStamp::class);
        /** @var ArticleInterface $article */
        $article = $stamp->getResult();

        return $article->getUuid();
    }

    private function createGermanSnippet(): string
    {
        /** @var MessageBusInterface $messageBus */
        $messageBus = self::getContainer()->get(MessageBusInterface::class);

        $envelope = $messageBus->dispatch(new Envelope(
            new CreateSnippetMessage([
                'locale' => 'de',
                'template' => 'default',
                'title' => 'Deutscher Schnipsel',
            ]),
            [new EnableFlushStamp()],
        ));

        /** @var HandledStamp $stamp */
        $stamp = $envelope->last(HandledStamp::class);
        /** @var SnippetInterface $snippet */
        $snippet = $stamp->getResult();

        return $snippet->getUuid();
    }

    private function createGermanPage(): string
    {
        /** @var MessageBusInterface $messageBus */
        $messageBus = self::getContainer()->get(MessageBusInterface::class);

        $envelope = $messageBus->dispatch(new Envelope(
            new CreatePageMessage('website', 'homepage', [
                'locale' => 'de',
                'template' => 'default',
                'title' => 'Deutsche Seite',
                'url' => '/',
                'article' => '<p>Deutscher Text</p>',
            ]),
            [new EnableFlushStamp()],
        ));

        /** @var HandledStamp $stamp */
        $stamp = $envelope->last(HandledStamp::class);
        /** @var PageInterface $page */
        $page = $stamp->getResult();

        return $page->getUuid();
    }

    /**
     * @param list<string> $contexts
     */
    private function authenticateEditor(array $contexts = ['sulu.webspaces.website']): void
    {
        $container = self::getContainer();

        $builder = new PermissionFixtureBuilder(
            $this->entityManager,
            $container->get('sulu_security.mask_converter'),
            $container->get('security.token_storage'),
            $container->get(SystemStoreInterface::class),
        );

        $role = $builder->role('LocaleEditor', \array_fill_keys($contexts, self::ALL_GRANTED));
        $builder->authenticate($builder->user('locale-editor', $role, 'en', ['en', 'de']));
    }
}
