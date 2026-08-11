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

namespace Sulu\Mcp\Infrastructure\Sulu\AdminLink;

use Sulu\Bundle\AdminBundle\Admin\View\ViewRegistry;
use Sulu\Bundle\TagBundle\Admin\TagAdmin;
use Sulu\Mcp\Application\AdminLink\AdminLinkProviderInterface;

/**
 * @internal
 */
final readonly class TagAdminLinkProvider implements AdminLinkProviderInterface
{
    use AdminLinkContextTrait;

    public function __construct(
        private ViewRegistry $viewRegistry,
    ) {
    }

    public function getType(): string
    {
        return 'tag';
    }

    public function buildPath(array $context): ?string
    {
        $id = $this->requireId($context, 'id');

        if (null === $id) {
            return null;
        }

        return $this->resolveViewPath($this->viewRegistry, TagAdmin::EDIT_FORM_VIEW, [
            ':id' => $id,
        ]);
    }
}
