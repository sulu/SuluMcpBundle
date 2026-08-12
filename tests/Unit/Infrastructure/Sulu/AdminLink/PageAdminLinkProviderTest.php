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

namespace Sulu\Mcp\Tests\Unit\Infrastructure\Sulu\AdminLink;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Admin\View\View;
use Sulu\Bundle\AdminBundle\Admin\View\ViewRegistry;
use Sulu\Bundle\AdminBundle\Exception\ViewNotFoundException;
use Sulu\Mcp\Infrastructure\Sulu\AdminLink\PageAdminLinkProvider;
use Sulu\Page\Infrastructure\Sulu\Admin\PageAdmin;

#[CoversClass(PageAdminLinkProvider::class)]
final class PageAdminLinkProviderTest extends TestCase
{
    private ViewRegistry&MockObject $viewRegistry;
    private PageAdminLinkProvider $provider;

    protected function setUp(): void
    {
        $this->viewRegistry = $this->createMock(ViewRegistry::class);
        $this->viewRegistry->method('findViewByName')->willReturnCallback(
            static function(string $name): View {
                if (PageAdmin::EDIT_FORM_VIEW === $name) {
                    return new View($name, '/webspaces/:webspace/pages/:locale/:id', 'form');
                }

                throw new ViewNotFoundException($name);
            }
        );

        $this->provider = new PageAdminLinkProvider($this->viewRegistry);
    }

    public function testGetTypeReturnsPage(): void
    {
        $this->assertSame('page', $this->provider->getType());
    }

    public function testBuildPathReturnsCorrectPath(): void
    {
        $result = $this->provider->buildPath([
            'webspace' => 'example',
            'locale' => 'en',
            'uuid' => 'abc-123',
        ]);

        $this->assertSame('/webspaces/example/pages/en/abc-123', $result);
    }

    /**
     * @return array<string, array<array<string, string>>>
     */
    public static function missingRequiredKeyProvider(): array
    {
        return [
            'missing webspace' => [['locale' => 'en', 'uuid' => 'abc-123']],
            'missing locale' => [['webspace' => 'example', 'uuid' => 'abc-123']],
            'missing uuid' => [['webspace' => 'example', 'locale' => 'en']],
            'empty webspace' => [['webspace' => '', 'locale' => 'en', 'uuid' => 'abc-123']],
            'empty locale' => [['webspace' => 'example', 'locale' => '', 'uuid' => 'abc-123']],
            'empty uuid' => [['webspace' => 'example', 'locale' => 'en', 'uuid' => '']],
            'empty context' => [[]],
        ];
    }

    /**
     * @param array<string, string> $context
     */
    #[DataProvider('missingRequiredKeyProvider')]
    public function testBuildPathReturnsNullWhenRequiredKeyMissing(array $context): void
    {
        $this->assertNull($this->provider->buildPath($context));
    }
}
