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
use Sulu\Page\Infrastructure\Sulu\Admin\PageAdmin;

/**
 * @internal
 */
final readonly class PageAdminLinkProvider implements AdminLinkProviderInterface
{
    use AdminLinkContextTrait;

    public function __construct(
        private ViewRegistry $viewRegistry,
    ) {
    }

    public function getType(): string
    {
        return 'page';
    }

    public function buildPath(array $context): ?string
    {
        $webspace = $this->requireString($context, 'webspace');
        $locale = $this->requireString($context, 'locale');
        $uuid = $this->requireString($context, 'uuid');

        if (in_array(null, [$webspace, $locale, $uuid], true)) {
            return null;
        }

        return $this->resolveViewPath($this->viewRegistry, PageAdmin::EDIT_FORM_VIEW, [
            ':webspace' => $webspace,
            ':locale' => $locale,
            ':id' => $uuid,
        ]);
    }
}
