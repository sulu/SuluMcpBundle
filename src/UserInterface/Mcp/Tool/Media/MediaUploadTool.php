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
use Sulu\Bundle\MediaBundle\Api\Media;
use Sulu\Bundle\MediaBundle\Entity\Collection;
use Sulu\Bundle\MediaBundle\Entity\CollectionInterface;
use Sulu\Bundle\MediaBundle\Entity\MediaInterface;
use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;
use Sulu\Bundle\SecurityBundle\Entity\User;
use Sulu\Component\Media\SystemCollections\SystemCollectionManagerInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Application\AdminLink\AdminLinkGeneratorInterface;
use Sulu\Mcp\Application\Media\DownloadedFile;
use Sulu\Mcp\Application\Media\MediaDownloader;
use Sulu\Mcp\Application\Media\MediaSource;
use Sulu\Mcp\Application\Media\MediaSourceUrlResolver;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Domain\Exception\MediaDownloadException;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * @internal
 */
class MediaUploadTool
{
    /**
     * The values sulu_media's `origin` single_select offers.
     *
     * @var list<string>
     */
    private const ORIGINS = ['human_created', 'ai_generated', 'ai_modified', 'unknown'];

    public function __construct(
        private readonly MediaManagerInterface $mediaManager,
        private readonly MediaSourceUrlResolver $sourceUrlResolver,
        private readonly MediaDownloader $downloader,
        private readonly SystemCollectionManagerInterface $systemCollectionManager,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly AdminLinkGeneratorInterface $adminLinkGenerator,
        private readonly ToolPermissionCheckerInterface $permissionChecker,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'sulu_media_upload',
        title: 'Upload Media From URL',
        description: 'Import an image from a URL into a media collection and return its media id, ready to use in block and page fields. Pass the URL exactly as it appears on the source page: a Sulu thumbnail URL is rewritten to the original automatically, so never hand-edit it. A URL that already points at this Sulu instance returns the existing media instead of importing a copy. Before calling, gather the image\'s caption and credits from the page it appears on — the alt attribute, a figcaption, a nearby credit line, a meta author tag — and pass them as description and copyright/credits. Do not invent credits: leave the field empty if the page does not state one. Set origin to ai_generated when you created the image yourself, ai_modified when you altered an existing one, and human_created when you copied it from a real site.',
    )]
    #[RequiresPermission(
        requirements: [new PermissionRequirement('sulu.media.collections', PermissionTypes::ADD)],
        objectResolved: true,
        discoveryContexts: ['sulu.media.collections'],
    )]
    public function uploadMedia(
        #[Schema(description: 'Source image URL, http or https.')]
        string $url,
        #[Schema(description: 'Id of the target collection. Use sulu_media_list to find one.')]
        int $collectionId,
        string $locale,
        #[Schema(description: 'Defaults to the file name without its extension.')]
        ?string $title = null,
        #[Schema(description: 'Alt text or caption, taken from the source page.')]
        ?string $description = null,
        #[Schema(description: 'Rights holder or license, taken from the source page. Leave empty rather than guessing.')]
        ?string $copyright = null,
        #[Schema(description: 'Credit line, taken from the source page. Leave empty rather than guessing.')]
        ?string $credits = null,
        #[Schema(description: 'Overrides the file name derived from the URL.')]
        ?string $fileName = null,
        #[Schema(description: 'How the image came to be. Drives the frontend AI disclosure badge.', enum: ['human_created', 'ai_generated', 'ai_modified', 'unknown'])]
        ?string $origin = null,
        #[Schema(description: 'The page the image was found on, stored as provenance.')]
        ?string $sourceUrl = null,
    ): array {
        try {
            if (null !== $origin && !\in_array($origin, self::ORIGINS, true)) {
                return [
                    'error' => \sprintf('Unsupported origin "%s".', $origin),
                    'hint' => \sprintf('Use one of: %s.', \implode(', ', self::ORIGINS)),
                ];
            }

            $user = $this->tokenStorage->getToken()?->getUser();

            if (!$user instanceof User) {
                return [
                    'error' => 'Not authenticated — a valid Sulu user is required to upload media.',
                    'hint' => 'Authenticate as a Sulu user with permission to add media before retrying.',
                ];
            }

            $this->checkTargetCollection($collectionId, $locale);

            $source = $this->sourceUrlResolver->resolve($url);

            if (MediaSource::KIND_LOCAL_MEDIA === $source->kind && null !== $source->localMediaId) {
                return $this->describeExisting($source->localMediaId, $locale);
            }

            return $this->import($source, $collectionId, $locale, $user->getId(), [
                'title' => $title,
                'description' => $description,
                'copyright' => $copyright,
                'credits' => $credits,
                'origin' => $origin,
            ], $fileName, $sourceUrl);
        } catch (PermissionDeniedException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        } catch (MediaDownloadException $e) {
            return [
                'error' => $e->getMessage(),
                'hint' => 'Pass a public http(s) URL of an image. Check sulu_mcp.media_upload for the size limit and the allowed hosts.',
            ];
        } catch (\Throwable $e) {
            return [
                'error' => \sprintf('Failed to upload media from "%s": %s', $url, $e->getMessage()),
                'hint' => 'Verify the collection id exists (use sulu_media_list) and the locale is valid.',
            ];
        }
    }

    /**
     * @param array<string, string|null> $metadata
     *
     * @return array<string, mixed>
     */
    private function import(
        MediaSource $source,
        int $collectionId,
        string $locale,
        int $userId,
        array $metadata,
        ?string $fileName,
        ?string $sourceUrl,
    ): array {
        $file = $this->downloadWithFallback($source);

        try {
            $storedName = $fileName ?? $file->fileName;

            $data = [
                'collection' => $collectionId,
                'locale' => $locale,
            ];

            // No title falls through to MediaManager::getTitleFromUpload(), which strips the
            // extension off the file name. Deriving it here too would be the same rule kept
            // in two places, and would drift from what the admin UI produces.
            foreach (['title', 'description', 'copyright', 'credits', 'origin'] as $field) {
                if (null !== $metadata[$field]) {
                    $data[$field] = $metadata[$field];
                }
            }

            // The file is already on disk and was validated here, hence test: true —
            // it is not a PHP upload and has no UPLOAD_ERR to report.
            $media = $this->mediaManager->save(
                new UploadedFile($file->path, $storedName, $file->mimeType, null, true),
                $data,
                $userId,
            );

            if (null !== $sourceUrl) {
                $media = $this->recordProvenance($media, $locale, $userId, $sourceUrl);
            }

            return $this->describe($media, $locale) + [
                'success' => true,
                'resolved_from' => $source->kind,
                'existing' => false,
            ];
        } finally {
            @\unlink($file->path);
        }
    }

    /**
     * FileVersion has no provenance column, so the page an image was found on goes into the
     * free-form properties bag. It cannot ride along with the upload: MediaManager::buildData()
     * replaces `properties` wholesale with what its extractors read out of the file, so the
     * source URL is merged into that result afterwards instead of being silently dropped.
     */
    private function recordProvenance(Media $media, string $locale, int $userId, string $sourceUrl): Media
    {
        $properties = $media->getProperties() ?? [];
        $properties['sourceUrl'] = $sourceUrl;

        return $this->mediaManager->save(null, [
            'id' => $media->getId(),
            'locale' => $locale,
            'properties' => $properties,
        ], $userId);
    }

    /**
     * A rewritten format URL is a guess about the remote's routing, so a failure to fetch it
     * falls back to the URL as given rather than failing the call.
     */
    private function downloadWithFallback(MediaSource $source): DownloadedFile
    {
        try {
            return $this->downloader->download($source->url);
        } catch (MediaDownloadException $e) {
            if (!$source->hasFallback()) {
                throw $e;
            }

            return $this->downloader->download($source->fallbackUrl);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function describeExisting(int $mediaId, string $locale): array
    {
        $media = $this->mediaManager->getById($mediaId, $locale);

        // getEntity() has no return type at all; Media always wraps a MediaInterface
        /** @var MediaInterface $entity */
        $entity = $media->getEntity();
        $this->checkViewOfCollection($entity->getCollection(), $locale);

        return $this->describe($media, $locale) + [
            'success' => true,
            'resolved_from' => MediaSource::KIND_LOCAL_MEDIA,
            'existing' => true,
            'note' => 'This URL already points at media in this Sulu instance, so nothing was imported and any metadata passed was ignored. Use sulu_media_update to change it.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Media $media, string $locale): array
    {
        $described = [
            'id' => $media->getId(),
            'title' => $media->getTitle(),
            'url' => $media->getUrl(),
            'mimeType' => $media->getMimeType(),
            'size' => $media->getSize(),
            'formats' => $media->getFormats(),
        ];

        $adminUrl = $this->adminLinkGenerator->generate('media', ['locale' => $locale, 'id' => $media->getId()]);

        if (null !== $adminUrl) {
            $described['admin_url'] = $adminUrl;
        }

        return $described;
    }

    /**
     * @throws PermissionDeniedException
     */
    private function checkTargetCollection(int $collectionId, string $locale): void
    {
        if ($this->systemCollectionManager->isSystemCollection($collectionId)) {
            $this->permissionChecker->check('sulu.media.system_collections', PermissionTypes::VIEW, $locale);
        }

        $this->permissionChecker->check(
            'sulu.media.collections',
            PermissionTypes::ADD,
            $locale,
            Collection::class,
            $collectionId,
        );
    }

    /**
     * The collection of media that already exists here, read off the entity the same way
     * MediaGetTool does.
     *
     * @throws PermissionDeniedException
     */
    private function checkViewOfCollection(CollectionInterface $collection, string $locale): void
    {
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
    }
}
