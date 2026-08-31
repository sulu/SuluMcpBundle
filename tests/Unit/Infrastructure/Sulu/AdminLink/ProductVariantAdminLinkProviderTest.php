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
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Sulu\Mcp\Infrastructure\Sulu\AdminLink\ProductVariantAdminLinkProvider;
use Sulu\Mcp\Tests\Application\TestBundle\Admin\TestViewRegistry;

#[CoversClass(ProductVariantAdminLinkProvider::class)]
#[Group('product')]
final class ProductVariantAdminLinkProviderTest extends TestCase
{
    private ProductVariantAdminLinkProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new ProductVariantAdminLinkProvider(new TestViewRegistry());
    }

    public function testGetTypeReturnsProductVariant(): void
    {
        $this->assertSame('product_variant', $this->provider->getType());
    }

    public function testBuildPathTargetsTheParentsVariantsTab(): void
    {
        $this->assertSame(
            '/en/products/parent-uuid/variants',
            $this->provider->buildPath(['locale' => 'en', 'uuid' => 'parent-uuid']),
        );
    }

    public function testBuildPathReturnsNullWithoutRequiredContext(): void
    {
        $this->assertNull($this->provider->buildPath(['locale' => 'en']));
        $this->assertNull($this->provider->buildPath(['uuid' => 'parent-uuid']));
    }
}
