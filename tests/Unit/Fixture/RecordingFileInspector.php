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

namespace Sulu\Mcp\Tests\Unit\Fixture;

use Sulu\Bundle\MediaBundle\FileInspector\FileInspectorInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Stands in for a project-registered inspector, to show that one is reached at all.
 *
 * @internal
 */
final class RecordingFileInspector implements FileInspectorInterface
{
    /** @var list<string> */
    public array $supportedCalls = [];

    public function supports(string $mimeType): bool
    {
        $this->supportedCalls[] = $mimeType;

        return false;
    }

    public function inspect(UploadedFile $file): UploadedFile
    {
        return $file;
    }
}
