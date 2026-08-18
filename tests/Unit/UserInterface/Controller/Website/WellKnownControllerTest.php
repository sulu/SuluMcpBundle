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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Controller\Website;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sulu\Mcp\UserInterface\Controller\Website\WellKnownController;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

#[CoversClass(WellKnownController::class)]
final class WellKnownControllerTest extends TestCase
{
    public function testProtectedResourceMetadataUsesConfiguredScopesAndMcpPath(): void
    {
        $controller = new WellKnownController($this->urlGenerator(), 'https://sulu.example.com/', '/admin/custom-mcp', ['mcp:tools']);

        $response = $controller->protectedResourceMetadata();
        $body = $this->json($response->getContent());

        self::assertSame('https://sulu.example.com/admin/custom-mcp', $body['resource']);
        self::assertSame(['mcp:tools'], $body['scopes_supported']);
    }

    public function testAuthorizationServerMetadataUsesConfiguredScopes(): void
    {
        $controller = new WellKnownController($this->urlGenerator(), 'https://sulu.example.com', '/admin/mcp', ['mcp:tools']);

        $response = $controller->authorizationServerMetadata();
        $body = $this->json($response->getContent());

        self::assertSame('https://sulu.example.com/admin/mcp/authorize', $body['authorization_endpoint']);
        self::assertSame('https://sulu.example.com/admin/mcp/token', $body['token_endpoint']);
        self::assertSame('https://sulu.example.com/admin/mcp/register', $body['registration_endpoint']);
        self::assertSame(['mcp:tools'], $body['scopes_supported']);
        self::assertContains('none', $body['token_endpoint_auth_methods_supported']);
    }

    private function urlGenerator(): UrlGeneratorInterface
    {
        $routes = new RouteCollection();
        $routes->add('sulu_mcp_oauth_authorize', new Route('/admin/mcp/authorize'));
        $routes->add('sulu_mcp_oauth_token', new Route('/admin/mcp/token'));
        $routes->add('sulu_mcp_client_registration', new Route('/admin/mcp/register'));

        return new UrlGenerator($routes, new RequestContext());
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string|false $content): array
    {
        self::assertIsString($content);
        $data = \json_decode($content, true);
        self::assertIsArray($data);

        return $data;
    }
}
