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
 * Asserts the bundle's OAuth routes are actually registered. The controllers are
 * otherwise only unit tested, which never exercises routing: if the attribute
 * `resource` path in config/routes.yaml stops matching the controller directory,
 * the routes silently disappear and every other gate stays green.
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
        yield 'protected resource metadata' => ['sulu_mcp_prm', '/.well-known/oauth-protected-resource', 'GET'];
        yield 'authorization server metadata' => ['sulu_mcp_as_metadata', '/.well-known/oauth-authorization-server', 'GET'];
        yield 'dynamic client registration' => ['sulu_mcp_client_registration', '/mcp/register', 'POST'];
        yield 'consent details' => ['sulu_mcp_oauth_consent_details', '/admin/mcp/consent/{requestId}', 'GET'];
        yield 'consent decision' => ['sulu_mcp_oauth_consent_decision', '/admin/mcp/consent/{requestId}', 'POST'];

        // Declared explicitly in config/routes.yaml.
        yield 'authorize' => ['sulu_mcp_oauth_authorize', '/admin/mcp/authorize', ''];
        yield 'token' => ['sulu_mcp_oauth_token', '/mcp/token', 'POST'];
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
                'Route "%s" is not registered. Check the attribute `resource` path in config/routes.yaml still '
                .'points at the controller directory.',
                $name,
            ),
        );
        self::assertSame($path, $route->getPath());

        if ('' !== $method) {
            self::assertContains($method, $route->getMethods());
        }
    }
}
