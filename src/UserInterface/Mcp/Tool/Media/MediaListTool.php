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

namespace Sulu\Mcp\UserInterface\Mcp\Tool\Media;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Sulu\Bundle\MediaBundle\Entity\Collection;
use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;
use Sulu\Component\Media\SystemCollections\SystemCollectionManagerInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;

/**
 * @internal
 */
class MediaListTool
{
    public function __construct(
        private readonly MediaManagerInterface $mediaManager,
        private readonly ToolPermissionCheckerInterface $permissionChecker,
        private readonly SystemCollectionManagerInterface $systemCollectionManager,
    ) {
    }

    /**
     * @param string[]|null $types
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'sulu_media_list',
        title: 'List Media',
        description: 'List/search media files. Filter by collection ID, media types, or search text. Note: tag-based filtering is not supported — use search text instead. Returns paginated list with total count.',
    )]
    #[RequiresPermission(
        requirements: [new PermissionRequirement('sulu.media.collections', PermissionTypes::VIEW)],
        objectResolved: true,
        discoveryContexts: ['sulu.media.collections'],
    )]
    public function listMedia(
        string $locale,
        ?int $collectionId = null,
        ?string $search = null,
        #[Schema(type: 'array', description: 'Filter by media type name(s). Typical values: "image", "video", "audio", "document" (the type names configured in this Sulu install). Omit for all types.', items: ['type' => 'string'])]
        ?array $types = null,
        int $page = 1,
        int $limit = 20,
    ): array {
        $offset = ($page - 1) * $limit;

        $filter = [];

        $hasSystemView = $this->permissionChecker->has('sulu.media.system_collections', PermissionTypes::VIEW, $locale);

        if (null !== $collectionId) {
            $filter['collection'] = $collectionId;

            try {
                if (!$hasSystemView && $this->systemCollectionManager->isSystemCollection($collectionId)) {
                    throw new PermissionDeniedException('sulu.media.system_collections', PermissionTypes::VIEW, $locale);
                }
                $this->permissionChecker->check(
                    'sulu.media.collections',
                    PermissionTypes::VIEW,
                    $locale,
                    Collection::class,
                    $collectionId,
                );
            } catch (PermissionDeniedException $e) {
                throw new ToolCallException($e->getMessage(), 0, $e);
            }
        }

        if (null !== $search) {
            $filter['search'] = $search;
        }

        if (null !== $types) {
            $filter['types'] = $types;
        }

        // Excluding system collections in SQL keeps them out of `total` too.
        if (!$hasSystemView) {
            $filter['systemCollections'] = false;
        }

        // Per-collection ACLs are not expressible: getIdsQuery() backs both the rows
        // and count() and takes no permission argument. Rows are filtered per item
        // below and `total` stays an upper bound, flagged via `hint`.
        $media = $this->mediaManager->get($locale, $filter, $limit, $offset);
        $total = $this->mediaManager->getCount();

        $results = [];
        foreach ($media as $m) {
            if (null === $collectionId) {
                $mediaCollectionId = $m->getCollection();
                if (null === $mediaCollectionId || !$this->permissionChecker->has(
                    'sulu.media.collections',
                    PermissionTypes::VIEW,
                    $locale,
                    Collection::class,
                    $mediaCollectionId,
                )) {
                    continue;
                }

                if (!$hasSystemView && $this->systemCollectionManager->isSystemCollection($mediaCollectionId)) {
                    continue;
                }
            }

            $results[] = [
                'id' => $m->getId(),
                'title' => $m->getTitle(),
                'mimeType' => $m->getMimeType(),
                'size' => $m->getSize(),
                'url' => $m->getUrl(),
            ];
        }

        $result = [
            'media' => $results,
            'total' => $total,
            'limit' => $limit,
            'page' => $page,
        ];

        // `total` counts the query before the per-row ACL filter, so it can exceed
        // what this user may see. Say so rather than report it silently.
        if (null === $collectionId && \count($results) !== \count($media)) {
            $result['hint'] = 'Some media are hidden by collection permissions; "total" counts the unfiltered library and may exceed the items you can see.';
        }

        return $result;
    }
}
