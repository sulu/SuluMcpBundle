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
use Mcp\Exception\ToolCallException;
use Sulu\Bundle\MediaBundle\Entity\Collection;
use Sulu\Bundle\MediaBundle\Entity\MediaInterface;
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
class MediaGetTool
{
    public function __construct(
        private readonly MediaManagerInterface $mediaManager,
        private readonly ToolPermissionCheckerInterface $permissionChecker,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'sulu_media_get',
        description: 'Get detailed information about a media file by ID. Returns metadata (title, description, copyright, mime type, size), the original URL, and all available format/thumbnail URLs.',
    )]
    #[RequiresPermission(
        requirements: [new PermissionRequirement('sulu.media.collections', PermissionTypes::VIEW)],
        objectResolved: true,
        discoveryContexts: ['sulu.media.collections'],
    )]
    public function getMedia(int $id, string $locale): array
    {
        try {
            $media = $this->mediaManager->getById($id, $locale);

            // getEntity() has no return type at all; Media always wraps a MediaInterface
            /** @var MediaInterface $entity */
            $entity = $media->getEntity();
            $collection = $entity->getCollection();
            if (SystemCollectionManagerInterface::COLLECTION_TYPE === $collection->getType()->getKey()) {
                $this->permissionChecker->check('sulu.media.system_collections', PermissionTypes::VIEW, $locale);
            }
            $this->permissionChecker->check(
                'sulu.media.collections',
                PermissionTypes::VIEW,
                $locale,
                Collection::class,
                $collection->getId(),
            );

            return [
                'id' => $media->getId(),
                'title' => $media->getTitle(),
                'description' => $media->getDescription(),
                'copyright' => $media->getCopyright(),
                'mimeType' => $media->getMimeType(),
                'size' => $media->getSize(),
                'url' => $media->getUrl(),
                'formats' => $media->getFormats(),
            ];
        } catch (PermissionDeniedException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        } catch (\Throwable) {
            return [
                'error' => \sprintf('Media not found: %d', $id),
                'hint' => 'Verify the media id. Use sulu_media_list to browse available media.',
            ];
        }
    }
}
