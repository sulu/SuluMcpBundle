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

use PHPUnit\Framework\Attributes\CoversNothing;
use Sulu\Bundle\SecurityBundle\System\SystemStoreInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\UserInterface\Mcp\Tool\Block\BlockListTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Page\PageUpdateTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Snippet\SnippetUpdateTool;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Page\Application\Message\CreatePageMessage;
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

        // The locale it was translated from must be untouched. Clear first: the page is
        // already managed with the dimension contents the "en" query hydrated.
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

    private function authenticateEditor(): void
    {
        $container = self::getContainer();

        $builder = new PermissionFixtureBuilder(
            $this->entityManager,
            $container->get('sulu_security.mask_converter'),
            $container->get('security.token_storage'),
            $container->get(SystemStoreInterface::class),
        );

        $role = $builder->role('LocaleEditor', ['sulu.webspaces.website' => self::ALL_GRANTED]);
        $builder->authenticate($builder->user('locale-editor', $role, 'en', ['en', 'de']));
    }
}
