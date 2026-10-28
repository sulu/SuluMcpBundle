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

namespace Sulu\Mcp\Tests\Unit\Application\Metadata;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sulu\Content\Domain\Model\ExcerptInterface;
use Sulu\Content\Domain\Model\SeoInterface;
use Sulu\Content\Domain\Model\TaxonomyInterface;
use Sulu\Mcp\Application\Metadata\ExtensionFormKeys;

#[CoversClass(ExtensionFormKeys::class)]
final class ExtensionFormKeysTest extends TestCase
{
    public function testReturnsRegisteredFormKeysInRegistryOrder(): void
    {
        // ExcerptFormPass / SeoFormPass already sort the registries by priority.
        $formKeys = new ExtensionFormKeys(
            [
                'content_excerpt_metadata' => ['instanceOf' => ExcerptInterface::class, 'priority' => 100],
                'app_excerpt' => ['instanceOf' => ExcerptInterface::class, 'priority' => 75],
                'content_excerpt_taxonomies' => ['instanceOf' => TaxonomyInterface::class, 'priority' => 50],
            ],
            [
                'content_seo_metadata' => ['instanceOf' => SeoInterface::class, 'priority' => 100],
                'app_seo' => ['instanceOf' => SeoInterface::class, 'priority' => 10],
            ],
        );

        $this->assertSame(['content_excerpt_metadata', 'app_excerpt', 'content_excerpt_taxonomies'], $formKeys->excerpt());
        $this->assertSame(['content_seo_metadata', 'app_seo'], $formKeys->seo());
    }
}
