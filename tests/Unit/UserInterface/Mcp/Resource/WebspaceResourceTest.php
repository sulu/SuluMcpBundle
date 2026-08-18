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
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Component\Localization\Localization;
use Sulu\Component\Webspace\Environment;
use Sulu\Component\Webspace\Manager\WebspaceCollection;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Component\Webspace\Portal;
use Sulu\Component\Webspace\Url;
use Sulu\Component\Webspace\Webspace;
use Sulu\Mcp\UserInterface\Mcp\Resource\WebspacesResource;

#[CoversClass(WebspacesResource::class)]
final class WebspaceResourceTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<WebspaceManagerInterface> */
    private ObjectProphecy $webspaceManager;

    private WebspacesResource $resource;

    protected function setUp(): void
    {
        $this->webspaceManager = $this->prophesize(WebspaceManagerInterface::class);
        $this->resource = new WebspacesResource($this->webspaceManager->reveal());
    }

    public function testGetWebspacesReturnsAllWebspacesWithLocalesAndUrl(): void
    {
        $ws1 = $this->createWebspaceWithPortal('example', 'Example', ['en', 'de'], 'example.com');
        $ws2 = $this->createWebspaceWithPortal('shop', 'Shop', ['en'], null);

        $collection = new WebspaceCollection([$ws1, $ws2]);
        $this->webspaceManager->getWebspaceCollection()->willReturn($collection);

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

        $collection = new WebspaceCollection([$ws]);
        $this->webspaceManager->getWebspaceCollection()->willReturn($collection);

        $result = $this->resource->getWebspaces();

        $this->assertSame('example.com', $result[0]['url']);
    }

    /**
     * Creates a webspace with a single prod-env portal.
     *
     * @param list<string> $locales
     */
    private function createWebspaceWithPortal(string $key, string $name, array $locales, ?string $prodUrl): Webspace
    {
        $localizations = \array_map(
            static fn (string $locale): Localization => new Localization($locale),
            $locales,
        );

        $portal = new Portal();
        if (null !== $prodUrl) {
            $env = new Environment();
            $env->setType('prod');
            $env->addUrl(new Url($prodUrl));
            $portal->setEnvironments([$env]);
        } else {
            // No environments at all: getEnvironment('prod') throws
            // EnvironmentNotFoundException, mirroring the "no prod url" case.
            $portal->setKey('portal');
            $portal->setEnvironments([]);
        }

        $ws = new Webspace();
        $ws->setKey($key);
        $ws->setName($name);
        $ws->setLocalizations($localizations);
        $ws->setPortals([$portal]);

        return $ws;
    }

    /**
     * Creates a webspace with a portal having multiple environments.
     *
     * @param list<string> $locales
     * @param array<string, string> $envUrls keyed by environment type
     */
    private function createWebspaceWithMultipleEnvPortal(string $key, string $name, array $locales, array $envUrls): Webspace
    {
        $localizations = \array_map(
            static fn (string $locale): Localization => new Localization($locale),
            $locales,
        );

        $environments = [];
        foreach ($envUrls as $envType => $url) {
            $env = new Environment();
            $env->setType($envType);
            $env->addUrl(new Url($url));
            $environments[] = $env;
        }

        $portal = new Portal();
        $portal->setEnvironments($environments);

        $ws = new Webspace();
        $ws->setKey($key);
        $ws->setName($name);
        $ws->setLocalizations($localizations);
        $ws->setPortals([$portal]);

        return $ws;
    }
}
