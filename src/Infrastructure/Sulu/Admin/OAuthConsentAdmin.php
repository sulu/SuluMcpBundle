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

namespace Sulu\Mcp\Infrastructure\Sulu\Admin;

use Sulu\Bundle\AdminBundle\Admin\Admin;
use Sulu\Bundle\AdminBundle\Admin\View\ViewBuilderFactoryInterface;
use Sulu\Bundle\AdminBundle\Admin\View\ViewCollection;

/**
 * @internal
 */
final class OAuthConsentAdmin extends Admin
{
    public const CONSENT_VIEW = 'sulu_mcp.oauth_consent';

    public function __construct(
        private readonly ViewBuilderFactoryInterface $viewBuilderFactory,
    ) {
    }

    public function configureViews(ViewCollection $viewCollection): void
    {
        $viewCollection->add(
            $this->viewBuilderFactory->createViewBuilder(
                self::CONSENT_VIEW,
                '/mcp/authorize/:requestId',
                'sulu_admin.authorization_consent',
            )
                ->setOption('detailsRoute', 'sulu_mcp_oauth_consent_details')
                ->setOption('decisionRoute', 'sulu_mcp_oauth_consent_decision'),
        );
    }
}
