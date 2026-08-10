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

namespace Sulu\Bundle\McpBundle\Tests\Application\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Sulu\Bundle\CategoryBundle\Category\CategoryManagerInterface;

class CategoryFixtures extends Fixture
{
    public function __construct(
        private readonly CategoryManagerInterface $categoryManager,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        foreach ($this->getCategoriesData() as $tree) {
            $children = $tree['children'] ?? [];
            unset($tree['children']);

            $parent = $this->categoryManager->save($tree, null, 'en');
            $parentId = $parent->getId();

            foreach ($children as $child) {
                $child['parent'] = $parentId;
                $this->categoryManager->save($child, null, 'en');
            }
        }
    }

    /**
     * @return list<array{name: string, key: string, children?: list<array{name: string, key: string}>}>
     */
    private function getCategoriesData(): array
    {
        return [
            [
                'name' => 'Music',
                'key' => 'music',
                'children' => [
                    ['name' => 'Rock & Roll', 'key' => 'music-rock'],
                    ['name' => 'Soul & R&B', 'key' => 'music-soul'],
                    ['name' => 'Jazz', 'key' => 'music-jazz'],
                    ['name' => 'Hip-Hop', 'key' => 'music-hip-hop'],
                    ['name' => 'Electronic', 'key' => 'music-electronic'],
                    ['name' => 'Folk & Singer-Songwriter', 'key' => 'music-folk'],
                ],
            ],
            [
                'name' => 'Technology',
                'key' => 'technology',
                'children' => [
                    ['name' => 'Content Management', 'key' => 'tech-cms'],
                    ['name' => 'Artificial Intelligence', 'key' => 'tech-ai'],
                    ['name' => 'Headless Architecture', 'key' => 'tech-headless'],
                    ['name' => 'Developer Tools', 'key' => 'tech-devtools'],
                ],
            ],
            [
                'name' => 'Editorial',
                'key' => 'editorial',
                'children' => [
                    ['name' => 'Tutorials', 'key' => 'editorial-tutorials'],
                    ['name' => 'Opinion', 'key' => 'editorial-opinion'],
                    ['name' => 'Interviews', 'key' => 'editorial-interviews'],
                    ['name' => 'Case Studies', 'key' => 'editorial-case-studies'],
                ],
            ],
        ];
    }
}
