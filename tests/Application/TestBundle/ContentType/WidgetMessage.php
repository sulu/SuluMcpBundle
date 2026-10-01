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

namespace Sulu\Mcp\Tests\Application\TestBundle\ContentType;

final readonly class WidgetMessage
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $kind,
        public string $uuid,
        public ?string $locale = null,
        public ?string $transition = null,
        public array $data = [],
    ) {
    }
}
