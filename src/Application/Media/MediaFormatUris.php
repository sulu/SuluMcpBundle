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

namespace Sulu\Mcp\Application\Media;

use Sulu\Bundle\MediaBundle\Api\Media;
use Sulu\Bundle\MediaBundle\Media\FormatCache\FormatCacheInterface;
use Sulu\Bundle\MediaBundle\Media\ImageConverter\ImageConverterInterface;

/**
 * Sulu lists a media file's image formats as one URL per format and output type, which is
 * around 200 URLs for a single image. The URLs differ only in the format key and the file
 * extension, so one URI with {format} and {extension} placeholders carries the same
 * information. Same shape as the SuluHeadlessBundle media serializer.
 */
final class MediaFormatUris
{
    private const FORMAT_PLACEHOLDER = '{format}';

    public function __construct(
        private readonly FormatCacheInterface $formatCache,
        private readonly ImageConverterInterface $imageConverter,
    ) {
    }

    /**
     * @return array{formatUri?: string, formatPreferredExtension?: string, formatExtensions?: list<string>}
     */
    public function describe(Media $media): array
    {
        $extensions = \array_values($this->imageConverter->getSupportedOutputImageFormats($media->getMimeType()));
        if ([] === $extensions) {
            return [];
        }

        // The placeholder cannot go through getMediaUrl() as "{extension}": the cache URL-encodes the file name.
        $fileName = \pathinfo((string) $media->getName(), \PATHINFO_FILENAME) . '.extension';
        $uri = $this->formatCache->getMediaUrl(
            $media->getId(),
            $fileName,
            self::FORMAT_PLACEHOLDER,
            $media->getVersion(),
            $media->getSubVersion(),
        );

        return [
            'formatUri' => \str_replace('.extension', '.{extension}', $uri),
            'formatPreferredExtension' => $extensions[0],
            'formatExtensions' => $extensions,
        ];
    }
}
