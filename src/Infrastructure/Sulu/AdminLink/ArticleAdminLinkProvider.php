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

use Sulu\Article\Infrastructure\Sulu\Admin\ArticleAdmin;
use Sulu\Bundle\AdminBundle\Admin\View\ViewRegistry;
use Sulu\Mcp\Application\AdminLink\AdminLinkProviderInterface;

/**
 * @internal
 */
final readonly class ArticleAdminLinkProvider implements AdminLinkProviderInterface
{
    use AdminLinkContextTrait;

    public function __construct(
        private ViewRegistry $viewRegistry,
    ) {
    }

    public function getType(): string
    {
        return 'article';
    }

    public function buildPath(array $context): ?string
    {
        $locale = $this->requireString($context, 'locale');
        $uuid = $this->requireString($context, 'uuid');

        if (null === $locale || null === $uuid) {
            return null;
        }

        $group = $this->requireString($context, 'group') ?? 'default';

        // Each article group registers its own edit view; the group segment is
        // baked into that view's path, so only :locale and :id remain.
        $viewName = ArticleAdmin::EDIT_TABS_VIEW.'_'.$group;

        return $this->resolveViewPath($this->viewRegistry, $viewName, [
            ':locale' => $locale,
            ':id' => $uuid,
        ]);
    }
}
