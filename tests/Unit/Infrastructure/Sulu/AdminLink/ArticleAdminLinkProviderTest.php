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
use Sulu\Article\Infrastructure\Sulu\Admin\ArticleAdmin;
use Sulu\Bundle\AdminBundle\Admin\View\View;
use Sulu\Bundle\AdminBundle\Admin\View\ViewRegistry;
use Sulu\Bundle\AdminBundle\Exception\ViewNotFoundException;
use Sulu\Mcp\Infrastructure\Sulu\AdminLink\ArticleAdminLinkProvider;

#[CoversClass(ArticleAdminLinkProvider::class)]
final class ArticleAdminLinkProviderTest extends TestCase
{
    private ViewRegistry&MockObject $viewRegistry;
    private ArticleAdminLinkProvider $provider;

    protected function setUp(): void
    {
        $prefix = ArticleAdmin::EDIT_TABS_VIEW . '_';

        $this->viewRegistry = $this->createMock(ViewRegistry::class);
        $this->viewRegistry->method('findViewByName')->willReturnCallback(
            static function(string $name) use ($prefix): View {
                if (!\str_starts_with($name, $prefix)) {
                    throw new ViewNotFoundException($name);
                }

                // Each group registers its edit view with the group baked into
                // the path, mirroring how Sulu's ArticleAdmin builds it.
                $group = \substr($name, \strlen($prefix));

                return new View($name, '/:locale/' . $group . '/:id', 'form');
            }
        );

        $this->provider = new ArticleAdminLinkProvider($this->viewRegistry);
    }

    public function testGetTypeReturnsArticle(): void
    {
        $this->assertSame('article', $this->provider->getType());
    }

    public function testBuildPathUsesDefaultGroupWhenNotProvided(): void
    {
        $result = $this->provider->buildPath([
            'locale' => 'en',
            'uuid' => 'article-uuid',
        ]);

        $this->assertSame('/en/default/article-uuid', $result);
    }

    public function testBuildPathUsesExplicitGroup(): void
    {
        $result = $this->provider->buildPath([
            'locale' => 'en',
            'uuid' => 'article-uuid',
            'group' => 'blog',
        ]);

        $this->assertSame('/en/blog/article-uuid', $result);
    }

    public function testBuildPathUsesDefaultGroupWhenGroupIsEmpty(): void
    {
        $result = $this->provider->buildPath([
            'locale' => 'en',
            'uuid' => 'article-uuid',
            'group' => '',
        ]);

        $this->assertSame('/en/default/article-uuid', $result);
    }

    /**
     * @return array<string, array<array<string, string>>>
     */
    public static function missingRequiredKeyProvider(): array
    {
        return [
            'missing locale' => [['uuid' => 'article-uuid']],
            'missing uuid' => [['locale' => 'en']],
            'empty locale' => [['locale' => '', 'uuid' => 'article-uuid']],
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
