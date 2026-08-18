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

namespace Sulu\Mcp\Tests\Unit\Infrastructure\Symfony\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Mcp\Infrastructure\Sulu\AdminLink\SnippetAdminLinkProvider;
use Sulu\Mcp\Infrastructure\Symfony\Routing\AdminLinkGenerator;
use Sulu\Mcp\Tests\Application\TestBundle\Admin\TestViewRegistry;
use Symfony\Component\Routing\Loader\ClosureLoader;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Router;
use Symfony\Component\Routing\RouterInterface;

#[CoversClass(AdminLinkGenerator::class)]
final class AdminLinkGeneratorTest extends TestCase
{
    use ProphecyTrait;

    private SnippetAdminLinkProvider $snippetProvider;

    protected function setUp(): void
    {
        $this->snippetProvider = new SnippetAdminLinkProvider(new TestViewRegistry());
    }

    public function testGenerateReturnsAbsoluteDeeplink(): void
    {
        $generator = new AdminLinkGenerator($this->router(), [$this->snippetProvider]);

        $result = $generator->generate('snippet', ['locale' => 'en', 'uuid' => 'abc']);

        $this->assertSame('https://example.com/admin/#/snippets/en/abc', $result);
    }

    public function testGenerateStripsTrailingSlashFromBase(): void
    {
        $generator = new AdminLinkGenerator($this->router(), [$this->snippetProvider]);

        $result = $generator->generate('snippet', ['locale' => 'en', 'uuid' => 'abc']);

        $this->assertStringNotContainsString('//#', (string) $result);
        $this->assertSame('https://example.com/admin/#/snippets/en/abc', $result);
    }

    public function testGenerateReturnsNullForUnknownType(): void
    {
        $router = $this->prophesize(RouterInterface::class);
        $router->generate(Argument::cetera())->shouldNotBeCalled();

        $generator = new AdminLinkGenerator($router->reveal(), [$this->snippetProvider]);

        $result = $generator->generate('unknown_type', ['locale' => 'en', 'uuid' => 'abc']);

        $this->assertNull($result);
    }

    public function testGenerateReturnsNullWhenProviderBuildPathReturnsNull(): void
    {
        $router = $this->prophesize(RouterInterface::class);
        $router->generate(Argument::cetera())->shouldNotBeCalled();

        $generator = new AdminLinkGenerator($router->reveal(), [$this->snippetProvider]);

        // Missing 'uuid' causes buildPath to return null
        $result = $generator->generate('snippet', ['locale' => 'en']);

        $this->assertNull($result);
    }

    public function testGenerateReturnsNullWhenRouterThrows(): void
    {
        // No 'sulu_admin' route registered, so the real router throws RouteNotFoundException
        $generator = new AdminLinkGenerator($this->router(withAdminRoute: false), [$this->snippetProvider]);

        $result = $generator->generate('snippet', ['locale' => 'en', 'uuid' => 'abc']);

        $this->assertNull($result);
    }

    public function testGenerateReturnsNullWhenProviderListIsEmpty(): void
    {
        $router = $this->prophesize(RouterInterface::class);
        $router->generate(Argument::cetera())->shouldNotBeCalled();

        $generator = new AdminLinkGenerator($router->reveal(), []);

        $result = $generator->generate('snippet', ['locale' => 'en', 'uuid' => 'abc']);

        $this->assertNull($result);
    }

    public function testGenerateSkipsNonMatchingProviders(): void
    {
        $generator = new AdminLinkGenerator($this->router(), [$this->snippetProvider]);

        // 'media' type is not served by SnippetAdminLinkProvider
        $result = $generator->generate('media', ['locale' => 'en', 'id' => 42]);

        $this->assertNull($result);
    }

    private function router(bool $withAdminRoute = true): RouterInterface
    {
        $routes = new RouteCollection();
        if ($withAdminRoute) {
            $routes->add('sulu_admin', new Route('/admin/'));
        }

        return new Router(
            new ClosureLoader(),
            static fn () => $routes,
            [],
            new RequestContext(host: 'example.com', scheme: 'https'),
        );
    }
}
