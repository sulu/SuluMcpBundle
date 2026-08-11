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
use Sulu\Mcp\Application\AdminLink\AdminLinkProviderInterface;
use Sulu\Snippet\Infrastructure\Sulu\Admin\SnippetAdmin;

/**
 * @internal
 */
final readonly class SnippetAdminLinkProvider implements AdminLinkProviderInterface
{
    use AdminLinkContextTrait;

    public function __construct(
        private ViewRegistry $viewRegistry,
    ) {
    }

    public function getType(): string
    {
        return 'snippet';
    }

    public function buildPath(array $context): ?string
    {
        $locale = $this->requireString($context, 'locale');
        $uuid = $this->requireString($context, 'uuid');

        if (null === $locale || null === $uuid) {
            return null;
        }

        return $this->resolveViewPath($this->viewRegistry, SnippetAdmin::EDIT_TABS_VIEW, [
            ':locale' => $locale,
            ':id' => $uuid,
        ]);
    }
}
