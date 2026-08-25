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
use Sulu\Bundle\TagBundle\Tag\TagInterface;
use Sulu\Bundle\TagBundle\Tag\TagManagerInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Application\AdminLink\AdminLinkGeneratorInterface;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;

/**
 * @internal
 */
class TagCreateTool
{
    public function __construct(
        private readonly TagManagerInterface $tagManager,
        private readonly AdminLinkGeneratorInterface $adminLinkGenerator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'sulu_tag_create',
        title: 'Create Tag',
        description: 'Create a new tag. Tags are flat labels used to classify content (pages, articles, media). Pass just the tag name.',
    )]
    #[RequiresPermission(requirements: [
        new PermissionRequirement('sulu.settings.tags', PermissionTypes::EDIT),
        new PermissionRequirement('sulu.settings.tags', PermissionTypes::ADD),
    ])]
    public function createTag(string $name): array
    {
        try {
            // save() declares no return type; it always returns the saved TagInterface
            /** @var TagInterface $tag */
            $tag = $this->tagManager->save(['name' => $name]);

            $result = [
                'success' => true,
                'id' => $tag->getId(),
                'name' => $tag->getName(),
            ];

            $adminUrl = $this->adminLinkGenerator->generate('tag', ['id' => $tag->getId()]);
            if (null !== $adminUrl) {
                $result['admin_url'] = $adminUrl;
            }

            return $result;
        } catch (\Throwable $e) {
            return [
                'error' => \sprintf('Failed to create tag "%s": %s', $name, $e->getMessage()),
                'hint' => 'Tag names must be unique. Use sulu_tag_list to check existing tags before creating.',
            ];
        }
    }
}
