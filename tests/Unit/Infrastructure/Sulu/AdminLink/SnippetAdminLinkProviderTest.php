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
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Bundle\AdminBundle\Admin\View\View;
use Sulu\Bundle\AdminBundle\Admin\View\ViewRegistry;
use Sulu\Bundle\AdminBundle\Exception\ViewNotFoundException;
use Sulu\Mcp\Infrastructure\Sulu\AdminLink\SnippetAdminLinkProvider;
use Sulu\Snippet\Infrastructure\Sulu\Admin\SnippetAdmin;

#[CoversClass(SnippetAdminLinkProvider::class)]
final class SnippetAdminLinkProviderTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<ViewRegistry> */
    private ObjectProphecy $viewRegistry;
    private SnippetAdminLinkProvider $provider;

    protected function setUp(): void
    {
        $this->viewRegistry = $this->prophesize(ViewRegistry::class);
        $this->viewRegistry->findViewByName(Argument::cetera())->will(fn (array $args) => (
            static function(string $name): View {
                if (SnippetAdmin::EDIT_TABS_VIEW === $name) {
                    return new View($name, '/snippets/:locale/:id', 'form');
                }

                throw new ViewNotFoundException($name);
            }
        )(...$args));

        $this->provider = new SnippetAdminLinkProvider($this->viewRegistry->reveal());
    }

    public function testGetTypeReturnsSnippet(): void
    {
        $this->assertSame('snippet', $this->provider->getType());
    }

    public function testBuildPathReturnsCorrectPath(): void
    {
        $result = $this->provider->buildPath([
            'locale' => 'en',
            'uuid' => 'snippet-uuid',
        ]);

        $this->assertSame('/snippets/en/snippet-uuid', $result);
    }

    /**
     * @return array<string, array<array<string, string>>>
     */
    public static function missingRequiredKeyProvider(): array
    {
        return [
            'missing locale' => [['uuid' => 'snippet-uuid']],
            'missing uuid' => [['locale' => 'en']],
            'empty locale' => [['locale' => '', 'uuid' => 'snippet-uuid']],
            'empty uuid' => [['locale' => 'en', 'uuid' => '']],
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
