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
use Sulu\Bundle\TagBundle\Tag\TagRepositoryInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;

/**
 * @internal
 */
class TagListTool
{
    public function __construct(
        private readonly TagRepositoryInterface $tagRepository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'sulu_tag_list',
        title: 'List Tags',
        description: 'List tags with pagination. Returns a page of tag objects (each with id and name), plus total tag count so you know how many pages exist. Use page and limit to navigate large tag collections.',
    )]
    #[RequiresPermission(requirements: [
        new PermissionRequirement('sulu.settings.tags', PermissionTypes::VIEW),
    ])]
    public function listTags(int $page = 1, int $limit = 20): array
    {
        try {
            $allTags = $this->tagRepository->findAll();
            $total = \count($allTags);
            $offset = ($page - 1) * $limit;
            $pageTags = \array_slice($allTags, $offset, $limit);

            return [
                'tags' => \array_map(
                    fn ($tag) => ['id' => $tag->getId(), 'name' => $tag->getName()],
                    $pageTags,
                ),
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
            ];
        } catch (\Throwable $e) {
            return [
                'error' => \sprintf('Failed to list tags: %s', $e->getMessage()),
                'hint' => 'Verify the database connection.',
            ];
        }
    }
}
