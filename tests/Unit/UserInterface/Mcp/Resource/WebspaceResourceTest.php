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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Resource;

use Mcp\Capability\Attribute\McpResource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sulu\Component\Localization\Localization;
use Sulu\Component\Webspace\Environment;
use Sulu\Component\Webspace\Exception\EnvironmentNotFoundException;
use Sulu\Component\Webspace\Manager\WebspaceCollection;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Component\Webspace\Portal;
use Sulu\Component\Webspace\Url;
use Sulu\Component\Webspace\Webspace;
use Sulu\Mcp\UserInterface\Mcp\Resource\WebspacesResource;

#[CoversClass(WebspacesResource::class)]
final class WebspaceResourceTest extends TestCase
{
    private WebspaceManagerInterface&MockObject $webspaceManager;
    private WebspacesResource $resource;

    protected function setUp(): void
    {
        $this->webspaceManager = $this->createMock(WebspaceManagerInterface::class);
        $this->resource = new WebspacesResource($this->webspaceManager);
    }

    public function testGetWebspacesReturnsAllWebspacesWithLocalesAndUrl(): void
    {
        $ws1 = $this->createWebspaceWithPortal('example', 'Example', ['en', 'de'], 'example.com');
        $ws2 = $this->createWebspaceWithPortal('shop', 'Shop', ['en'], null);

        $collection = $this->createMock(WebspaceCollection::class);
        $collection->method('getWebspaces')->willReturn([$ws1, $ws2]);
        $this->webspaceManager->method('getWebspaceCollection')->willReturn($collection);

        $result = $this->resource->getWebspaces();

        $this->assertCount(2, $result);

        $this->assertSame('example', $result[0]['key']);
        $this->assertSame('Example', $result[0]['name']);
        $this->assertContains('en', $result[0]['locales']);
        $this->assertContains('de', $result[0]['locales']);
        $this->assertArrayHasKey('url', $result[0]);
        $this->assertSame('example.com', $result[0]['url']);

        $this->assertSame('shop', $result[1]['key']);
        $this->assertNull($result[1]['url']);
    }

    public function testGetWebspacesMethodHasMcpResourceAttribute(): void
    {
        $reflection = new \ReflectionMethod(WebspacesResource::class, 'getWebspaces');
        $attributes = $reflection->getAttributes(McpResource::class);

        $this->assertCount(1, $attributes, 'getWebspaces() method must have exactly one #[McpResource] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu://webspaces', $instance->uri);
        $this->assertSame('sulu_webspaces', $instance->name);
    }

    public function testGetWebspacesFiltersToMainEnvironmentUrl(): void
    {
        $ws = $this->createWebspaceWithMultipleEnvPortal('example', 'Example', ['en'], [
            'dev' => 'example.localhost',
            'prod' => 'example.com',
        ]);

        $collection = $this->createMock(WebspaceCollection::class);
        $collection->method('getWebspaces')->willReturn([$ws]);
        $this->webspaceManager->method('getWebspaceCollection')->willReturn($collection);

        $result = $this->resource->getWebspaces();

        $this->assertSame('example.com', $result[0]['url']);
    }

    /**
     * Helper: create a webspace mock with a single prod-env portal.
     *
     * @param list<string> $locales
     */
    private function createWebspaceWithPortal(string $key, string $name, array $locales, ?string $prodUrl): Webspace&MockObject
    {
        $localizations = \array_map(function (string $locale) {
            $loc = $this->createMock(Localization::class);
            $loc->method('getLocale')->willReturn($locale);

            return $loc;
        }, $locales);

        if (null !== $prodUrl) {
            $url = $this->createMock(Url::class);
            $url->method('getUrl')->willReturn($prodUrl);

            $prodEnv = $this->createMock(Environment::class);
            $prodEnv->method('getUrls')->willReturn([$url]);

            $portal = $this->createMock(Portal::class);
            $portal->method('getEnvironment')->with('prod')->willReturn($prodEnv);
            $portal->method('getEnvironments')->willReturn([$prodEnv]);
            $portals = [$portal];
        } else {
            $portal = $this->createMock(Portal::class);
            $portal->method('getKey')->willReturn('portal');
            $portal->method('getEnvironments')->willReturn([]);
            /** @var Portal $portal */
            $portalForException = $portal;
            $portal->method('getEnvironment')->with('prod')->willReturnCallback(
                static function () use ($portalForException) {
                    throw new EnvironmentNotFoundException($portalForException, 'prod');
                }
            );
            $portals = [$portal];
        }

        $ws = $this->createMock(Webspace::class);
        $ws->method('getKey')->willReturn($key);
        $ws->method('getName')->willReturn($name);
        $ws->method('getAllLocalizations')->willReturn($localizations);
        $ws->method('getPortals')->willReturn($portals);

        return $ws;
    }

    /**
     * Helper: create webspace mock with portal having multiple environments.
     *
     * @param list<string>          $locales
     * @param array<string, string> $envUrls keyed by environment type
     */
    private function createWebspaceWithMultipleEnvPortal(string $key, string $name, array $locales, array $envUrls): Webspace&MockObject
    {
        $localizations = \array_map(function (string $locale) {
            $loc = $this->createMock(Localization::class);
            $loc->method('getLocale')->willReturn($locale);

            return $loc;
        }, $locales);

        $portal = $this->createMock(Portal::class);
        $portal->method('getEnvironment')->willReturnCallback(function (string $envType) use ($envUrls, $portal) {
            if (isset($envUrls[$envType])) {
                $url = $this->createMock(Url::class);
                $url->method('getUrl')->willReturn($envUrls[$envType]);

                $env = $this->createMock(Environment::class);
                $env->method('getUrls')->willReturn([$url]);

                return $env;
            }
            throw new EnvironmentNotFoundException($portal, $envType);
        });

        $ws = $this->createMock(Webspace::class);
        $ws->method('getKey')->willReturn($key);
        $ws->method('getName')->willReturn($name);
        $ws->method('getAllLocalizations')->willReturn($localizations);
        $ws->method('getPortals')->willReturn([$portal]);

        return $ws;
    }
}
