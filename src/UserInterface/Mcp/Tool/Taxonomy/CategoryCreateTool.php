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
use Sulu\Bundle\CategoryBundle\Category\CategoryManagerInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Application\AdminLink\AdminLinkGeneratorInterface;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @internal
 */
class CategoryCreateTool
{
    public function __construct(
        private readonly CategoryManagerInterface $categoryManager,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly AdminLinkGeneratorInterface $adminLinkGenerator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'sulu_category_create',
        description: 'Create a new category. Categories are hierarchical (tree structure) used to classify content. Pass locale, name, optional key (slug), and optional parentId to nest under an existing category.',
    )]
    #[RequiresPermission(requirements: [
        new PermissionRequirement('sulu.settings.categories', PermissionTypes::VIEW),
        new PermissionRequirement('sulu.settings.categories', PermissionTypes::ADD),
    ])]
    public function createCategory(
        string $locale,
        string $name,
        ?string $key = null,
        #[Schema(description: 'Integer id of the parent category (NOT a UUID). Omit for a top-level category. Get ids from sulu_category_list.')]
        ?int $parentId = null,
    ): array {
        try {
            $user = $this->tokenStorage->getToken()?->getUser();
            if (!$user instanceof UserInterface || !\method_exists($user, 'getId')) {
                return [
                    'error' => 'No authenticated user found',
                    'hint' => 'Authenticate as a Sulu user with permission to manage categories before retrying.',
                ];
            }

            /** @var array<string, mixed> $data */
            $data = ['name' => $name, 'locale' => $locale];
            if (null !== $key) {
                $data['key'] = $key;
            }
            if (null !== $parentId) {
                $data['parent'] = $parentId;
            }

            $category = $this->categoryManager->save($data, $user->getId(), $locale);

            $result = [
                'success' => true,
                'id' => $category->getId(),
                'name' => $name,
                'key' => $category->getKey(),
            ];

            $adminUrl = $this->adminLinkGenerator->generate('category', ['locale' => $locale, 'id' => $category->getId()]);
            if (null !== $adminUrl) {
                $result['admin_url'] = $adminUrl;
            }

            return $result;
        } catch (\Throwable $e) {
            return [
                'error' => \sprintf('Failed to create category "%s": %s', $name, $e->getMessage()),
                'hint' => 'The key must be unique. Use sulu_category_list to check existing categories and verify the parentId is a valid integer category id.',
            ];
        }
    }
}
