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
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Page\Application\Message\OrderPageMessage;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @internal
 */
class PageReorderTool
{
    use HandleTrait;

    public function __construct(
        MessageBusInterface $messageBus,
        private readonly PageRepositoryInterface $pageRepository,
        private readonly ToolPermissionCheckerInterface $permissionChecker,
    ) {
        $this->messageBus = $messageBus;
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'sulu_page_reorder',
        title: 'Reorder Page',
        description: 'Change the position of a page among its siblings, without changing its parent. Positions are 1-based: 1 makes the page the first child of its parent. Call sulu_page_tree first — every node there carries its current "position" among its siblings, which is what you compute the new position from. This only reorders; use sulu_page_move to give a page a different parent. Reordering does not change any addresses.',
    )]
    #[RequiresPermission(
        requirements: [new PermissionRequirement('sulu.webspaces.#context#', PermissionTypes::EDIT)],
        objectResolved: true,
        discoveryContexts: [WebspacePermissionResolver::ANY_WEBSPACE_CONTEXT],
    )]
    public function reorderPage(
        string $uuid,
        #[Schema(type: 'integer', description: '1-based target position among the siblings; 1 makes the page the first child of its parent.')]
        int $position,
        string $locale,
    ): array {
        try {
            // loadGhost: sibling order is language-independent, so a page with no content
            // in this locale is still reorderable.
            $page = $this->pageRepository->findOneBy(
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

            if (null === $page) {
                return [
                    'error' => \sprintf('Page not found: %s', $uuid),
                    'hint' => 'Verify the UUID exists (use sulu_page_tree or sulu_page_get).',
                ];
            }

            $parent = $page->getParent();
            if (null === $parent) {
                return [
                    'error' => \sprintf('Page %s is the start page of webspace "%s" and has no siblings to be ordered among.', $uuid, $page->getWebspaceKey()),
                    'hint' => 'A webspace start page has no parent. Reorder one of its child pages instead.',
                ];
            }

            // Before the sibling count, whose out-of-range message would otherwise report
            // the size of the branch to a caller without edit rights.
            $this->permissionChecker->check(
                'sulu.webspaces.' . $page->getWebspaceKey(),
                PermissionTypes::EDIT,
                $locale,
                Page::class,
                $uuid,
            );

            // Not $parent->getChildren(): a managed parent's collection can predate the
            // children this call is about to order.
            $siblingCount = $this->pageRepository->countBy(['parentId' => $parent->getUuid()]);
            if ($position < 1 || $position > $siblingCount) {
                return [
                    'error' => \sprintf('Position %d is out of range; page %s has %d siblings including itself.', $position, $uuid, $siblingCount),
                    'hint' => \sprintf('Pass a position between 1 and %d.', $siblingCount),
                ];
            }

            $message = new OrderPageMessage(['uuid' => $uuid], $position, $locale);

            /** @var PageInterface $reordered */
            $reordered = $this->handle(new Envelope($message, [new EnableFlushStamp()]));

            return [
                'success' => true,
                'uuid' => $reordered->getUuid(),
                'webspace' => $reordered->getWebspaceKey(),
                'parentId' => $parent->getUuid(),
                'position' => $position,
                'siblingCount' => $siblingCount,
            ];
        } catch (PermissionDeniedException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            return [
                'error' => \sprintf('Failed to reorder page %s to position %d: %s', $uuid, $position, $e->getMessage()),
                'hint' => 'Verify the UUID exists and the position is within the sibling count (use sulu_page_tree).',
            ];
        }
    }
}
