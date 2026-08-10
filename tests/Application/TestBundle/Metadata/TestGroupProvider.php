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

namespace Sulu\Bundle\McpBundle\Tests\Application\TestBundle\Metadata;

use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormGroup;
use Sulu\Bundle\AdminBundle\Metadata\GroupProviderInterface;

/**
 * Stub GroupProviderInterface: returns the configured groups regardless of the requested key.
 */
final readonly class TestGroupProvider implements GroupProviderInterface
{
    /**
     * @param array<string, FormGroup> $groups
     */
    public function __construct(
        private array $groups = [],
    ) {
    }

    public function getGroups(string $key): array
    {
        return $this->groups;
    }

    /**
     * A single 'default' group with the 'article' template, reproducing the
     * single-group install every existing test implicitly assumes.
     */
    public static function singleGroup(): self
    {
        return new self([
            'default' => new FormGroup('default', 'Default', ['article']),
        ]);
    }
}
