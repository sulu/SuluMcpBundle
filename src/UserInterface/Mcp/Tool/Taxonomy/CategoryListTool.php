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
use Mcp\Capability\Attribute\Schema;
use Sulu\Bundle\CategoryBundle\Api\Category as ApiCategory;
use Sulu\Bundle\CategoryBundle\Category\CategoryManagerInterface;
use Sulu\Bundle\CategoryBundle\Entity\CategoryInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;

/**
 * @internal
 */
class CategoryListTool
{
    public function __construct(
        private readonly CategoryManagerInterface $categoryManager,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'sulu_category_list',
        description: 'List all categories as a tree structure. Returns hierarchical array with nested children. Each category has id, name, key, hasChildren, and children array. Accepts an optional maxDepth to limit response size on deep category trees; when a node has hasChildren:true but children:[] the branch was depth-truncated — request again with a higher maxDepth or fetch that branch separately.',
    )]
    #[RequiresPermission(requirements: [
        new PermissionRequirement('sulu.settings.categories', PermissionTypes::VIEW),
    ])]
    public function listCategories(
        string $locale,
        #[Schema(description: 'Maximum category nesting depth (0 = top-level only). Omit for the full tree.')]
        ?int $maxDepth = null,
    ): array {
        try {
            // findChildrenByParentId() only declares `array`; elements are CategoryInterface
            /** @var array<CategoryInterface> $entities */
            $entities = $this->categoryManager->findChildrenByParentId(null);
            $apiCategories = $this->categoryManager->getApiObjects($entities, $locale);

            return [
                'categories' => $this->buildTree($apiCategories, 0, $maxDepth),
            ];
        } catch (\Throwable $e) {
            return [
                'error' => \sprintf('Failed to list categories: %s', $e->getMessage()),
                'hint' => 'Verify the locale is valid.',
            ];
        }
    }

    /**
     * @param iterable<ApiCategory> $categories
     *
     * @return list<array<string, mixed>>
     */
    private function buildTree(iterable $categories, int $depth = 0, ?int $maxDepth = null): array
    {
        $result = [];
        foreach ($categories as $category) {
            $children = $category->getChildren();
            $hasChildren = [] !== $children;

            $childNodes = [];
            if (null === $maxDepth || $depth < $maxDepth) {
                $childNodes = $this->buildTree($children, $depth + 1, $maxDepth);
            }

            $result[] = [
                'id' => $category->getId(),
                'name' => $category->getName(),
                'key' => $category->getKey(),
                'hasChildren' => $hasChildren,
                'children' => $childNodes,
            ];
        }

        return $result;
    }
}
