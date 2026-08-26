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

namespace Sulu\Mcp\UserInterface\Mcp\Tool\Page;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Application\AdminLink\AdminLinkGeneratorInterface;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Page\Application\Message\MovePageMessage;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Model\PageDimensionContentInterface;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @internal
 */
class PageMoveTool
{
    use HandleTrait;

    public function __construct(
        MessageBusInterface $messageBus,
        private readonly ContentManagerInterface $contentManager,
        private readonly PageRepositoryInterface $pageRepository,
        private readonly AdminLinkGeneratorInterface $adminLinkGenerator,
        private readonly ToolPermissionCheckerInterface $permissionChecker,
    ) {
        $this->messageBus = $messageBus;
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'sulu_page_move',
        title: 'Move Page',
        description: 'Move a page to a different parent inside the same webspace. Use sulu_page_tree to find both the page uuid and the targetParentId. The whole subtree moves with the page. This rewrites the resource locator of the page AND of every descendant: the old addresses keep working as redirects, but they change for real, so tell the user how many pages are affected before doing this at scale — the "affectedDescendants" count in the response is that number. The change reaches the live site as soon as the move is stored; no sulu_content_publish call is needed for the new addresses (page content edits still need one). A move is not per language — one call moves the page in every language it exists in; the "locale" argument only records which translation the activity log entry refers to.',
    )]
    #[RequiresPermission(
        requirements: [new PermissionRequirement('sulu.webspaces.#context#', PermissionTypes::EDIT)],
        objectResolved: true,
        discoveryContexts: [WebspacePermissionResolver::ANY_WEBSPACE_CONTEXT],
    )]
    public function movePage(
        string $uuid,
        string $targetParentId,
        string $locale,
    ): array {
        try {
            if ($uuid === $targetParentId) {
                return [
                    'error' => \sprintf('Page %s cannot be moved below itself.', $uuid),
                    'hint' => 'Pass the UUID of the new parent page as targetParentId (use sulu_page_tree).',
                ];
            }

            // loadGhost: tree position is language-independent, so a page with no content
            // in this locale is still movable.
            $page = $this->loadPage($uuid, $locale);
            if (null === $page) {
                return [
                    'error' => \sprintf('Page not found: %s', $uuid),
                    'hint' => 'Verify the UUID exists (use sulu_page_tree or sulu_page_get).',
                ];
            }

            $previousParent = $page->getParent();
            if (null === $previousParent) {
                return [
                    'error' => \sprintf('Page %s is the start page of webspace "%s" and cannot be moved.', $uuid, $page->getWebspaceKey()),
                    'hint' => 'A webspace start page has no parent. Move one of its child pages instead.',
                ];
            }

            if ($targetParentId === $previousParent->getUuid()) {
                return [
                    'error' => \sprintf('Page %s is already a child of %s.', $uuid, $targetParentId),
                    'hint' => 'moveOneBy() would re-append the page as the last child. Use sulu_page_reorder to change its position under the same parent.',
                ];
            }

            $targetParent = $this->loadPage($targetParentId, $locale);
            if (null === $targetParent) {
                return [
                    'error' => \sprintf('Target parent page not found: %s', $targetParentId),
                    'hint' => 'Verify the UUID exists (use sulu_page_tree).',
                ];
            }

            // moveOneBy() never rewrites webspaceKey, so a cross-webspace move would leave
            // the subtree claiming its old webspace.
            if ($page->getWebspaceKey() !== $targetParent->getWebspaceKey()) {
                throw new PermissionDeniedException(
                    'sulu.webspaces.' . $targetParent->getWebspaceKey(),
                    PermissionTypes::EDIT,
                    $locale,
                );
            }

            // The admin checks only the source page; a move writes into both branches, so
            // both are required. Before the descendant lookup, which would otherwise
            // disclose the tree to a caller without edit rights.
            $context = 'sulu.webspaces.' . $page->getWebspaceKey();
            $this->permissionChecker->check($context, PermissionTypes::EDIT, $locale, Page::class, $uuid);
            $this->permissionChecker->check($context, PermissionTypes::EDIT, $locale, Page::class, $targetParentId);

            $descendantIds = $this->pageRepository->findDescendantIdsById($uuid);

            if (\in_array($targetParentId, $descendantIds, true)) {
                return [
                    'error' => \sprintf('Page %s cannot be moved below its own descendant %s.', $uuid, $targetParentId),
                    'hint' => 'Choose a target parent outside the subtree of the page being moved (use sulu_page_tree).',
                ];
            }

            // MovePageMessageHandler dereferences the previous parent's title in this
            // locale without a null check.
            if (!$this->hasTranslation($previousParent, $locale)) {
                return [
                    'error' => \sprintf('The current parent page %s has no "%s" translation.', $previousParent->getUuid(), $locale),
                    'hint' => \sprintf('Call sulu_page_move with a locale the parent exists in, or create the "%s" translation of the parent first (sulu_page_update).', $locale),
                ];
            }

            $message = new MovePageMessage(['uuid' => $uuid], ['uuid' => $targetParentId], $locale);

            /** @var PageInterface $moved */
            $moved = $this->handle(new Envelope($message, [new EnableFlushStamp()]));

            $result = [
                'success' => true,
                'uuid' => $moved->getUuid(),
                'webspace' => $moved->getWebspaceKey(),
                'parentId' => $targetParentId,
                'previousParentId' => $previousParent->getUuid(),
                'affectedDescendants' => \count($descendantIds),
                'url' => $this->resolveUrl($moved, $locale),
            ];

            $adminUrl = $this->adminLinkGenerator->generate('page', [
                'webspace' => $moved->getWebspaceKey(),
                'locale' => $locale,
                'uuid' => $moved->getUuid(),
            ]);
            if (null !== $adminUrl) {
                $result['admin_url'] = $adminUrl;
            }

            return $result;
        } catch (PermissionDeniedException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            return [
                'error' => \sprintf('Failed to move page %s below %s: %s', $uuid, $targetParentId, $e->getMessage()),
                'hint' => 'Verify both UUIDs exist and belong to the same webspace (use sulu_page_tree).',
            ];
        }
    }

    private function loadPage(string $uuid, string $locale): ?PageInterface
    {
        return $this->pageRepository->findOneBy(
            [
                'uuid' => $uuid,
                'locale' => $locale,
                'stage' => DimensionContentInterface::STAGE_DRAFT,
                'loadGhost' => true,
            ],
            [
                PageRepositoryInterface::GROUP_SELECT_PAGE_ADMIN => true,
            ],
        );
    }

    private function hasTranslation(PageInterface $page, string $locale): bool
    {
        return \in_array($locale, $this->resolveDraft($page, $locale)->getAvailableLocales() ?? [], true);
    }

    private function resolveUrl(PageInterface $page, string $locale): ?string
    {
        return $this->resolveDraft($page, $locale)->getRoute()?->getSlug();
    }

    private function resolveDraft(PageInterface $page, string $locale): PageDimensionContentInterface
    {
        return $this->contentManager->resolve($page, [
            'locale' => $locale,
            'stage' => DimensionContentInterface::STAGE_DRAFT,
        ]);
    }
}
