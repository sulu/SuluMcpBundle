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
use Sulu\Bundle\TagBundle\Tag\TagManagerInterface;

class TagFixtures extends Fixture
{
    public function __construct(
        private readonly TagManagerInterface $tagManager,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        foreach ($this->getTagNames() as $name) {
            $this->tagManager->findOrCreateByName($name);
        }
    }

    /**
     * @return list<string>
     */
    private function getTagNames(): array
    {
        return [
            // Editorial topics
            'AI',
            'CMS',
            'Content Strategy',
            'Headless',
            'Sulu',
            'MCP',
            // Music genres
            'Rock',
            'Soul',
            'Jazz',
            'Hip-Hop',
            'Electronic',
            'Folk',
            // General
            'Tutorial',
            'Opinion',
            'Profile',
            'Interview',
        ];
    }
}
