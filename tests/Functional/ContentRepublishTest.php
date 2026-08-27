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
use Sulu\Bundle\SecurityBundle\Entity\User;
use Sulu\Bundle\SecurityBundle\System\SystemStoreInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\UserInterface\Mcp\Tool\Content\ContentPublishTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Content\ContentUnpublishTool;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Page\Application\Message\CreatePageMessage;
use Sulu\Page\Application\Message\ModifyPageMessage;
use Sulu\Page\Domain\Model\PageInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[CoversNothing]
final class ContentRepublishTest extends FunctionalTestCase
{
    private const ALL_GRANTED = [
        PermissionTypes::VIEW => true, PermissionTypes::ADD => true, PermissionTypes::EDIT => true,
        PermissionTypes::DELETE => true, PermissionTypes::ARCHIVE => true, PermissionTypes::LIVE => true,
        PermissionTypes::SECURITY => true,
    ];

    private const USERNAME = 'publish-editor';

    private PermissionFixtureBuilder $permissionFixtureBuilder;

    public function testRepublishingUpdatesTheLiveRowsInsteadOfDuplicatingThem(): void
    {
        $this->authenticateEditor();
        $uuid = $this->createEnglishPage();

        $this->publish($uuid);
        $this->startNextRequest();

        $this->updateExcerpt($uuid, 'REPRO EXCERPT V2');
        $this->startNextRequest();

        $this->publish($uuid);
        $this->startNextRequest();

        $liveRows = $this->liveRows($uuid);

        self::assertSame(
            ['' => 1, 'en' => 1],
            $this->countPerLocale($liveRows),
            'the second publish must update the live rows written by the first one, not add a second set',
        );
        self::assertSame(
            'REPRO EXCERPT V2',
            $this->localizedRow($liveRows)['excerptData']['description'] ?? null,
            'the surviving live row must carry the content of the latest publish',
        );
    }

    public function testUnpublishingRemovesTheLiveRows(): void
    {
        $this->authenticateEditor();
        $uuid = $this->createEnglishPage();

        $this->publish($uuid);
        $this->startNextRequest();

        $this->unpublish($uuid);
        $this->startNextRequest();

        self::assertSame(
            ['' => 1],
            $this->countPerLocale($this->liveRows($uuid)),
            'the transition must see the live row of the earlier publish to remove it; core keeps the unlocalized row and only drops the locale from its available locales',
        );
    }

    /** Only a fresh entity manager, as every tool call gets one, makes the transition load again. */
    private function startNextRequest(): void
    {
        $this->entityManager->clear();

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['username' => self::USERNAME]);
        self::assertInstanceOf(User::class, $user);

        $this->permissionFixtureBuilder->authenticate($user);
    }

    /**
     * @param list<array{locale: string|null, excerptData: array<string, mixed>}> $liveRows
     *
     * @return array<string, int>
     */
    private function countPerLocale(array $liveRows): array
    {
        $counts = [];
        foreach ($liveRows as $row) {
            $key = $row['locale'] ?? '';
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        \ksort($counts);

        return $counts;
    }

    /**
     * @param list<array{locale: string|null, excerptData: array<string, mixed>}> $liveRows
     *
     * @return array{locale: string|null, excerptData: array<string, mixed>}
     */
    private function localizedRow(array $liveRows): array
    {
        foreach ($liveRows as $row) {
            if ('en' === $row['locale']) {
                return $row;
            }
        }

        self::fail('no live row for locale "en"');
    }

    /**
     * @return list<array{locale: string|null, excerptData: array<string, mixed>}>
     */
    private function liveRows(string $uuid): array
    {
        /** @var list<array{locale: string|null, excerptData: array<string, mixed>}> $rows */
        $rows = $this->entityManager->createQuery(
            'SELECT d.locale, d.excerptData FROM Sulu\Page\Domain\Model\PageDimensionContent d'
            . ' WHERE IDENTITY(d.page) = :uuid AND d.stage = :stage AND d.version = :version'
            . ' ORDER BY d.id',
        )->setParameters([
            'uuid' => $uuid,
            'stage' => DimensionContentInterface::STAGE_LIVE,
            'version' => DimensionContentInterface::CURRENT_VERSION,
        ])->getArrayResult();

        return $rows;
    }

    private function publish(string $uuid): void
    {
        /** @var ContentPublishTool $tool */
        $tool = self::getContainer()->get(ContentPublishTool::class);

        $result = $tool->publishContent('page', $uuid, 'en');

        self::assertArrayNotHasKey('error', $result, (string) ($result['error'] ?? ''));
    }

    private function unpublish(string $uuid): void
    {
        /** @var ContentUnpublishTool $tool */
        $tool = self::getContainer()->get(ContentUnpublishTool::class);

        $result = $tool->unpublishContent('page', $uuid, 'en');

        self::assertArrayNotHasKey('error', $result, (string) ($result['error'] ?? ''));
    }

    private function updateExcerpt(string $uuid, string $description): void
    {
        /** @var MessageBusInterface $messageBus */
        $messageBus = self::getContainer()->get(MessageBusInterface::class);

        $messageBus->dispatch(new Envelope(
            new ModifyPageMessage(['uuid' => $uuid], $this->pageData($description)),
            [new EnableFlushStamp()],
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function pageData(string $excerptDescription): array
    {
        return [
            'locale' => 'en',
            'template' => 'default',
            'title' => 'Repro Page',
            'url' => '/repro-page',
            'article' => '<p>Body</p>',
            'excerpt' => ['description' => $excerptDescription],
        ];
    }

    private function createEnglishPage(): string
    {
        /** @var MessageBusInterface $messageBus */
        $messageBus = self::getContainer()->get(MessageBusInterface::class);

        $envelope = $messageBus->dispatch(new Envelope(
            new CreatePageMessage('website', 'homepage', $this->pageData('REPRO EXCERPT V1')),
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

        $this->permissionFixtureBuilder = new PermissionFixtureBuilder(
            $this->entityManager,
            $container->get('sulu_security.mask_converter'),
            $container->get('security.token_storage'),
            $container->get(SystemStoreInterface::class),
        );

        $role = $this->permissionFixtureBuilder->role('PublishEditor', ['sulu.webspaces.website' => self::ALL_GRANTED]);
        $this->permissionFixtureBuilder->authenticate($this->permissionFixtureBuilder->user(self::USERNAME, $role, 'en', ['en']));
    }
}
