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
use Mcp\Schema\ToolAnnotations;
use Sulu\Bundle\CategoryBundle\Category\CategoryManagerInterface;
use Sulu\Bundle\CategoryBundle\Domain\Exception\RemoveCategoryDependantResourcesFoundException;
use Sulu\Bundle\CategoryBundle\Entity\CategoryInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Domain\Security\DangerousTool;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;

/**
 * @internal
 */
class CategoryDeleteTool
{
    public function __construct(
        private readonly CategoryManagerInterface $categoryManager,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'sulu_category_delete',
        title: 'Delete Category',
        description: 'Delete a category by ID. Returns the id, resourceKey and deleted flag. The category must have no children: delete them first (deepest first), otherwise the call fails. Use sulu_category_list to find the children.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false),
    )]
    #[DangerousTool('delete')]
    #[RequiresPermission(requirements: [
        new PermissionRequirement('sulu.settings.categories', PermissionTypes::VIEW),
        new PermissionRequirement('sulu.settings.categories', PermissionTypes::DELETE),
    ])]
    public function deleteCategory(int $id): array
    {
        try {
            $this->categoryManager->delete($id);

            return [
                'success' => true,
                'id' => $id,
                'resourceKey' => CategoryInterface::RESOURCE_KEY,
                'deleted' => true,
            ];
        } catch (\Throwable $e) {
            if ($e instanceof RemoveCategoryDependantResourcesFoundException) {
                return [
                    'error' => \sprintf('Category %d has %d descendant categories and cannot be deleted.', $id, $e->getDependantResourcesCount()),
                    'hint' => 'Delete the descendants first, deepest first (use sulu_category_list to find them), then delete this category.',
                ];
            }

            return [
                'error' => \sprintf('Failed to delete category %d: %s', $id, $e->getMessage()),
                'hint' => 'Verify the category id exists (use sulu_category_list). A category with children cannot be deleted.',
            ];
        }
    }
}
