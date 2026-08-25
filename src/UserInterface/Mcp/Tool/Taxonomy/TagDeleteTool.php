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

namespace Sulu\Mcp\UserInterface\Mcp\Tool\Taxonomy;

use Mcp\Capability\Attribute\McpTool;
use Sulu\Bundle\TagBundle\Tag\TagManagerInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;

/**
 * @internal
 */
class TagDeleteTool
{
    public function __construct(
        private readonly TagManagerInterface $tagManager,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'sulu_tag_delete',
        title: 'Delete Tag',
        description: 'Delete a tag by ID. This removes the tag but does not affect content that was tagged with it.',
    )]
    #[RequiresPermission(requirements: [
        new PermissionRequirement('sulu.settings.tags', PermissionTypes::EDIT),
        new PermissionRequirement('sulu.settings.tags', PermissionTypes::DELETE),
    ])]
    public function deleteTag(int $id): array
    {
        try {
            $this->tagManager->delete($id);

            return [
                'success' => true,
                'id' => $id,
                'deleted' => true,
            ];
        } catch (\Throwable $e) {
            return [
                'error' => \sprintf('Failed to delete tag %d: %s', $id, $e->getMessage()),
                'hint' => 'Verify the tag id exists (use sulu_tag_list). Deleting a tag does not delete content that referenced it.',
            ];
        }
    }
}
