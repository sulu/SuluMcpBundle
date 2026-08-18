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
use Sulu\Bundle\MediaBundle\Admin\MediaAdmin;
use Sulu\Mcp\Infrastructure\Sulu\AdminLink\MediaAdminLinkProvider;

#[CoversClass(MediaAdminLinkProvider::class)]
final class MediaAdminLinkProviderTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<ViewRegistry> */
    private ObjectProphecy $viewRegistry;
    private MediaAdminLinkProvider $provider;

    protected function setUp(): void
    {
        $this->viewRegistry = $this->prophesize(ViewRegistry::class);
        $this->viewRegistry->findViewByName(Argument::cetera())->will(fn (array $args) => (
            static function(string $name): View {
                if (MediaAdmin::EDIT_FORM_VIEW === $name) {
                    return new View($name, '/media/:locale/:id', 'form');
                }

                throw new ViewNotFoundException($name);
            }
        )(...$args));

        $this->provider = new MediaAdminLinkProvider($this->viewRegistry->reveal());
    }

    public function testGetTypeReturnsMedia(): void
    {
        $this->assertSame('media', $this->provider->getType());
    }

    public function testBuildPathWithIntegerId(): void
    {
        $result = $this->provider->buildPath(['locale' => 'en', 'id' => 42]);

        $this->assertSame('/media/en/42', $result);
    }

    public function testBuildPathWithStringId(): void
    {
        $result = $this->provider->buildPath(['locale' => 'en', 'id' => '42']);

        $this->assertSame('/media/en/42', $result);
    }

    /**
     * @return array<string, array<array<string, mixed>>>
     */
    public static function invalidContextProvider(): array
    {
        return [
            'missing locale' => [['id' => 42]],
            'missing id' => [['locale' => 'en']],
            'empty locale' => [['locale' => '', 'id' => 42]],
            'empty string id' => [['locale' => 'en', 'id' => '']],
            'zero int id' => [['locale' => 'en', 'id' => 0]],
            'negative int id' => [['locale' => 'en', 'id' => -1]],
            'empty context' => [[]],
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
