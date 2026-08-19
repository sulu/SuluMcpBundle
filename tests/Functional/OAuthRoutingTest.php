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

namespace Sulu\Mcp\Tests\Functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Routing\RouterInterface;

/**
 * If an attribute `resource` path stops matching its controller directory the
 * routes silently disappear, and no unit test exercises routing.
 */
#[CoversNothing]
final class OAuthRoutingTest extends FunctionalTestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function routeProvider(): iterable
    {
        // Loaded by attribute discovery over the controller directory.
        yield 'protected resource metadata' => ['sulu_mcp_prm', '/.well-known/oauth-protected-resource/admin/mcp', 'GET'];
        yield 'authorization server metadata' => ['sulu_mcp_as_metadata', '/.well-known/oauth-authorization-server/admin/mcp', 'GET'];
        yield 'dynamic client registration' => ['sulu_mcp_client_registration', '/admin/mcp/register', 'POST'];
        yield 'consent details' => ['sulu_mcp_oauth_consent_details', '/admin/mcp/consent/{requestId}', 'GET'];
        yield 'consent decision' => ['sulu_mcp_oauth_consent_decision', '/admin/mcp/consent/{requestId}', 'POST'];

        // Declared explicitly in routing_admin.yaml.
        yield 'authorize' => ['sulu_mcp_oauth_authorize', '/admin/mcp/authorize', ''];
        yield 'token' => ['sulu_mcp_oauth_token', '/admin/mcp/token', 'POST'];
    }

    #[DataProvider('routeProvider')]
    public function testRouteIsRegistered(string $name, string $path, string $method): void
    {
        /** @var RouterInterface $router */
        $router = self::getContainer()->get('router');

        $route = $router->getRouteCollection()->get($name);

        self::assertNotNull(
            $route,
            \sprintf(
                'Route "%s" is not registered. Check the attribute `resource` paths in '
                . 'config/routing_admin.yaml and config/routing_website.yaml still point at their controller directories.',
                $name,
            ),
        );
        self::assertSame($path, $route->getPath());

        if ('' !== $method) {
            self::assertContains($method, $route->getMethods());
        }
    }
}
