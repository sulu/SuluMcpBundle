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

namespace Sulu\Mcp\Application\Metadata;

use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\ItemMetadata;

/**
 * @internal
 */
interface FieldSchemaGeneratorInterface
{
    /**
     * @param ItemMetadata[] $items
     *
     * @return array<string, mixed>
     */
    public function generate(array $items, string $locale): array;
}
