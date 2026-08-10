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

namespace Sulu\Bundle\McpBundle\Tests\Application\TestBundle\Admin;

use Sulu\Article\Infrastructure\Sulu\Admin\ArticleAdmin;
use Sulu\Bundle\AdminBundle\Admin\View\View;
use Sulu\Bundle\AdminBundle\Admin\View\ViewRegistry;
use Sulu\Bundle\AdminBundle\Exception\ViewNotFoundException;
use Sulu\Bundle\CategoryBundle\Admin\CategoryAdmin;
use Sulu\Bundle\MediaBundle\Admin\MediaAdmin;
use Sulu\Bundle\TagBundle\Admin\TagAdmin;
use Sulu\Page\Infrastructure\Sulu\Admin\PageAdmin;
use Sulu\Snippet\Infrastructure\Sulu\Admin\SnippetAdmin;

/**
 * Test stub returning the admin edit-view path templates that Sulu registers,
 * so AdminLink providers can be exercised without booting the admin kernel.
 */
final class TestViewRegistry extends ViewRegistry
{
    public function __construct()
    {
    }

    public function findViewByName(string $name): View
    {
        $articlePrefix = ArticleAdmin::EDIT_TABS_VIEW.'_';
        if (\str_starts_with($name, $articlePrefix)) {
            $group = \substr($name, \strlen($articlePrefix));

            return new View($name, '/:locale/'.$group.'/:id', 'form');
        }

        $paths = [
            PageAdmin::EDIT_FORM_VIEW => '/webspaces/:webspace/pages/:locale/:id',
            SnippetAdmin::EDIT_TABS_VIEW => '/snippets/:locale/:id',
            MediaAdmin::EDIT_FORM_VIEW => '/media/:locale/:id',
            TagAdmin::EDIT_FORM_VIEW => '/tags/:id',
            CategoryAdmin::EDIT_FORM_VIEW => '/categories/:locale/:id',
        ];

        if (isset($paths[$name])) {
            return new View($name, $paths[$name], 'form');
        }

        throw new ViewNotFoundException($name);
    }
}
