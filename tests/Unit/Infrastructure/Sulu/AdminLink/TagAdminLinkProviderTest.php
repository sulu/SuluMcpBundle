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
use Sulu\Bundle\TagBundle\Admin\TagAdmin;
use Sulu\Mcp\Infrastructure\Sulu\AdminLink\TagAdminLinkProvider;

#[CoversClass(TagAdminLinkProvider::class)]
final class TagAdminLinkProviderTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<ViewRegistry> */
    private ObjectProphecy $viewRegistry;
    private TagAdminLinkProvider $provider;

    protected function setUp(): void
    {
        $this->viewRegistry = $this->prophesize(ViewRegistry::class);
        $this->viewRegistry->findViewByName(Argument::cetera())->will(fn (array $args) => (
            static function(string $name): View {
                if (TagAdmin::EDIT_FORM_VIEW === $name) {
                    return new View($name, '/tags/:id', 'form');
                }

                throw new ViewNotFoundException($name);
            }
        )(...$args));

        $this->provider = new TagAdminLinkProvider($this->viewRegistry->reveal());
    }

    public function testGetTypeReturnsTag(): void
    {
        $this->assertSame('tag', $this->provider->getType());
    }

    public function testBuildPathWithIntegerId(): void
    {
        $result = $this->provider->buildPath(['id' => 7]);

        $this->assertSame('/tags/7', $result);
    }

    public function testBuildPathWithStringId(): void
    {
        $result = $this->provider->buildPath(['id' => 'tag-slug']);

        $this->assertSame('/tags/tag-slug', $result);
    }

    /**
     * @return array<string, array<array<string, mixed>>>
     */
    public static function invalidContextProvider(): array
    {
        return [
            'missing id' => [[]],
            'empty string id' => [['id' => '']],
            'zero int id' => [['id' => 0]],
            'negative int id' => [['id' => -5]],
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    #[DataProvider('invalidContextProvider')]
    public function testBuildPathReturnsNullForInvalidContext(array $context): void
    {
        $this->assertNull($this->provider->buildPath($context));
    }
}
