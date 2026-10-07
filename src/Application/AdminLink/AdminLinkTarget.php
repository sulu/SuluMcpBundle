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

namespace Sulu\Mcp\Application\AdminLink;

/**
 * The resource whose admin detail view shows another resource.
 */
final readonly class AdminLinkTarget
{
    /**
     * @param string $pathSuffix appended to the admin URL to open a tab of the view, e.g. "/variants"
     */
    public function __construct(
        public string $resourceKey,
        public string $resourceId,
        public string $pathSuffix = '',
    ) {
    }
}
