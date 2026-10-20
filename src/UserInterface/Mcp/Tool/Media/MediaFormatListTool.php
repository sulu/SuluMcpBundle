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
use Mcp\Schema\ToolAnnotations;
use Sulu\Bundle\MediaBundle\Media\FormatManager\FormatManagerInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;

/**
 * @internal
 */
class MediaFormatListTool
{
    public function __construct(
        private readonly FormatManagerInterface $formatManager,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'sulu_media_format_list',
        title: 'List Media Formats',
        description: 'List the image formats this Sulu install can render (key, title, scale). Use a key to fill the {format} placeholder of the "formatUri" that sulu_media_get returns.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    #[RequiresPermission(requirements: [
        new PermissionRequirement('sulu.media.collections', PermissionTypes::VIEW),
    ])]
    public function listFormats(string $locale): array
    {
        $formats = [];
        foreach ($this->formatManager->getFormatDefinitions($locale) as $definition) {
            // Internal formats serve the admin UI only.
            if ($definition['internal']) {
                continue;
            }

            $formats[] = [
                'key' => $definition['key'],
                'title' => $definition['title'],
                'scale' => $definition['scale'],
            ];
        }

        return ['formats' => $formats, 'total' => \count($formats)];
    }
}
