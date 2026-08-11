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

namespace Sulu\Mcp\Tests\Unit\Infrastructure\Sulu\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Admin\View\ViewBuilderFactory;
use Sulu\Bundle\AdminBundle\Admin\View\ViewCollection;
use Sulu\Mcp\Infrastructure\Sulu\Admin\OAuthConsentAdmin;

#[CoversClass(OAuthConsentAdmin::class)]
final class OAuthConsentAdminTest extends TestCase
{
    public function testConfiguresConsentViewUsingCoreAuthorizationConsentType(): void
    {
        $viewCollection = new ViewCollection();
        $admin = new OAuthConsentAdmin(new ViewBuilderFactory());

        $admin->configureViews($viewCollection);

        self::assertTrue($viewCollection->has(OAuthConsentAdmin::CONSENT_VIEW));

        $view = $viewCollection->get(OAuthConsentAdmin::CONSENT_VIEW)->getView();
        self::assertSame('/mcp/authorize/:requestId', $view->getPath());
        self::assertSame('sulu_admin.authorization_consent', $view->getType());
        self::assertSame('sulu_mcp_oauth_consent_details', $view->getOption('detailsRoute'));
        self::assertSame('sulu_mcp_oauth_consent_decision', $view->getOption('decisionRoute'));
    }
}
