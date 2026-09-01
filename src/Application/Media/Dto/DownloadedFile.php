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

namespace Sulu\Mcp\Application\Media\Dto;

/**
 * A downloaded source image, sitting in a temporary file the caller owns and must
 * remove.
 *
 * @internal
 */
final readonly class DownloadedFile
{
    /**
     * @param non-empty-string $path absolute path of the temporary file
     * @param non-empty-string $fileName the name to store the media under
     * @param non-empty-string $mimeType determined from the bytes, never from a response header
     */
    public function __construct(
        public string $path,
        public string $fileName,
        public string $mimeType,
        public int $size,
    ) {
    }
}
