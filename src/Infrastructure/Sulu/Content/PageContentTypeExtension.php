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

namespace Sulu\Mcp\Infrastructure\Sulu\Content;

use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Infrastructure\Doctrine\DimensionContentQueryEnhancer;
use Sulu\Mcp\Application\Security\PageDescendantPermissionChecker;
use Sulu\Mcp\Domain\Content\ContentTypeExtensionInterface;
use Sulu\Page\Application\Message\ApplyWorkflowTransitionPageMessage;
use Sulu\Page\Application\Message\ModifyPageMessage;
use Sulu\Page\Application\Message\RemovePageMessage;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;

/**
 * @internal
 */
final readonly class PageContentTypeExtension implements ContentTypeExtensionInterface
{
    public function __construct(
        private PageRepositoryInterface $repository,
        private PageDescendantPermissionChecker $descendantPermissionChecker,
    ) {
    }

    public function getType(): string
    {
        return 'page';
    }

    public function getResourceKey(): string
    {
        return 'pages';
    }

    public function getTemplateType(): string
    {
        return 'page';
    }

    public function getViewSecurityContexts(): array
    {
        return [];
    }

    public function getEntitySecurityContext(object $aggregate, ?string $templateKey): string
    {
        return $aggregate instanceof PageInterface ? 'sulu.webspaces.' . $aggregate->getWebspaceKey() : '';
    }

    public function requiresResolvedContent(): bool
    {
        return false;
    }

    public function getAclObjectType(): string
    {
        return Page::class;
    }

    public function getWebspaceKey(object $aggregate): ?string
    {
        return $aggregate instanceof PageInterface ? $aggregate->getWebspaceKey() : null;
    }

    public function assertCanRemove(string $uuid): void
    {
        $this->descendantPermissionChecker->assertCanDeleteDescendants($uuid);
    }

    public function createRemoveMessage(string $uuid, string $locale, bool $forceRemoveChildren = false): object
    {
        return new RemovePageMessage(['uuid' => $uuid], $locale, $forceRemoveChildren);
    }

    public function loadDraft(string $uuid, string $locale, bool $loadGhost = false): ?object
    {
        try {
            return $this->repository->getOneBy([
                'uuid' => $uuid,
                'locale' => $locale,
                'stage' => DimensionContentInterface::STAGE_DRAFT,
                'loadGhost' => $loadGhost,
            ], [PageRepositoryInterface::GROUP_SELECT_PAGE_ADMIN => true]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Hydrated for draft and live: a draft-only aggregate duplicates the live rows on re-query.
     */
    public function loadForTransition(string $uuid, string $locale): ?object
    {
        try {
            return $this->repository->getOneBy([
                'uuid' => $uuid,
                'locale' => $locale,
                'stage' => DimensionContentInterface::STAGE_DRAFT,
            ], [PageRepositoryInterface::SELECT_PAGE_CONTENT => [
                'selects' => [DimensionContentQueryEnhancer::GROUP_SELECT_CONTENT_ADMIN => true],
                'dimensionAttributes' => [
                    'locale' => $locale,
                    'stage' => [DimensionContentInterface::STAGE_DRAFT, DimensionContentInterface::STAGE_LIVE],
                ],
            ]]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createModifyMessage(string $uuid, array $data): object
    {
        return new ModifyPageMessage(['uuid' => $uuid], $data);
    }

    public function createTransitionMessage(string $uuid, string $locale, string $transition): object
    {
        return new ApplyWorkflowTransitionPageMessage(['uuid' => $uuid], $locale, $transition);
    }
}
