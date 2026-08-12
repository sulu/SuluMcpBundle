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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Resource\Fixture;

use Sulu\Bundle\AdminBundle\Metadata\MetadataInterface;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;

/**
 * @internal
 */
final readonly class ArrayMetadataProvider implements MetadataProviderInterface
{
    /** @param array<string, MetadataInterface> $metadata */
    public function __construct(
        private array $metadata,
    ) {
    }

    public function getMetadata(string $key, string $locale, array $metadataOptions): MetadataInterface
    {
        return $this->metadata[$key] ?? throw new \RuntimeException(\sprintf('No metadata registered for "%s".', $key));
    }
}
