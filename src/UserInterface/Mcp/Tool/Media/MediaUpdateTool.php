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
use Sulu\Bundle\MediaBundle\Api\Media;
use Sulu\Bundle\MediaBundle\Entity\Collection;
use Sulu\Bundle\MediaBundle\Entity\MediaInterface;
use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;
use Sulu\Bundle\SecurityBundle\Entity\User;
use Sulu\Component\Media\SystemCollections\SystemCollectionManagerInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Application\AdminLink\AdminLinkGeneratorInterface;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * @internal
 */
class MediaUpdateTool
{
    public function __construct(
        private readonly MediaManagerInterface $mediaManager,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly AdminLinkGeneratorInterface $adminLinkGenerator,
        private readonly ToolPermissionCheckerInterface $permissionChecker,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'sulu_media_update',
        title: 'Update Media',
        description: 'Update media metadata (title, description, copyright). Does not change the file itself — only metadata fields. Pass only the fields you want to change; the result echoes title, description and copyright as they were stored. Calling this with a locale the media has no metadata in yet creates that translation, copying the title from the existing one unless you pass your own, and the result carries "created_locale": true.',
    )]
    #[RequiresPermission(
        requirements: [new PermissionRequirement('sulu.media.collections', PermissionTypes::EDIT)],
        objectResolved: true,
        discoveryContexts: ['sulu.media.collections'],
    )]
    public function updateMedia(
        int $id,
        string $locale,
        ?string $title = null,
        ?string $description = null,
        ?string $copyright = null,
    ): array {
        try {
            $user = $this->tokenStorage->getToken()?->getUser();

            if (!$user instanceof User) {
                return [
                    'error' => 'Not authenticated — a valid Sulu user is required to update media.',
                    'hint' => 'Authenticate as a Sulu user with permission to edit media before retrying.',
                ];
            }

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
                PermissionTypes::EDIT,
                $locale,
                Collection::class,
                $collection->getId(),
            );

            $data = [
                'id' => $id,
                'locale' => $locale,
            ];

            if (null !== $title) {
                $data['title'] = $title;
            }

            if (null !== $description) {
                $data['description'] = $description;
            }

            if (null !== $copyright) {
                $data['copyright'] = $copyright;
            }

            $createsLocale = !$this->hasMetaForLocale($media, $locale);
            if ($createsLocale && !isset($data['title'])) {
                // MediaManager writes the payload verbatim, and the meta row it creates
                // here has a NOT NULL title.
                $fallbackTitle = $media->getTitle();
                if (null === $fallbackTitle) {
                    return [
                        'error' => \sprintf('Media %d has no title to copy into the new "%s" translation.', $id, $locale),
                        'hint' => 'Pass a title when updating a media in a locale it has no metadata in yet.',
                    ];
                }

                $data['title'] = $fallbackTitle;
            }

            $media = $this->mediaManager->save(null, $data, $user->getId());

            $result = [
                'success' => true,
                'id' => $media->getId(),
                'locale' => $locale,
                // Read back, not echoed: an argument that did not land must not look applied.
                'title' => $media->getTitle(),
                'description' => $media->getDescription(),
                'copyright' => $media->getCopyright(),
            ];

            if ($createsLocale) {
                $result['created_locale'] = true;
            }

            $adminUrl = $this->adminLinkGenerator->generate('media', ['locale' => $locale, 'id' => $media->getId()]);
            if (null !== $adminUrl) {
                $result['admin_url'] = $adminUrl;
            }

            return $result;
        } catch (PermissionDeniedException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            return [
                'error' => \sprintf('Failed to update media %d: %s', $id, $e->getMessage()),
                'hint' => 'Verify the media id exists (use sulu_media_list) and the locale is valid.',
            ];
        }
    }

    /**
     * {@see \Sulu\Bundle\MediaBundle\Api\Media::getTitle()} and friends fall back to the
     * default translation, so they cannot answer whether this locale has a row of its own.
     */
    private function hasMetaForLocale(Media $media, string $locale): bool
    {
        foreach ($media->getFileVersion()->getMeta() as $meta) {
            if ($meta->getLocale() === $locale) {
                return true;
            }
        }

        return false;
    }
}
