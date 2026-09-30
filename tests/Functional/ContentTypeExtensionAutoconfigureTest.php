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

namespace Sulu\Mcp\Tests\Functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use Sulu\Mcp\Application\Content\ContentTypeExtensionRegistry;
use Sulu\Mcp\Tests\Application\TestBundle\ContentType\WidgetContentTypeExtension;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The test bundle defines its extension in its own config without a tag.
 */
#[CoversNothing]
final class ContentTypeExtensionAutoconfigureTest extends KernelTestCase
{
    public function testAnExtensionFromAnotherBundleIsTaggedByAutoconfiguration(): void
    {
        self::bootKernel();

        $registry = self::getContainer()->get(ContentTypeExtensionRegistry::class);

        self::assertInstanceOf(WidgetContentTypeExtension::class, $registry->find('widgets'));
    }
}
