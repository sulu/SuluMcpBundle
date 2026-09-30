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

namespace Sulu\Mcp\Tests\Unit\Application\Content;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sulu\Mcp\Application\Content\ContentTypeExtensionRegistry;
use Sulu\Mcp\Tests\Unit\Fixture\FakeContentTypeExtension;

#[CoversClass(ContentTypeExtensionRegistry::class)]
final class ContentTypeExtensionRegistryTest extends TestCase
{
    public function testItIndexesExtensionsByTypeAndResourceKey(): void
    {
        $widget = new FakeContentTypeExtension('widget', 'widgets');
        $gadget = new FakeContentTypeExtension('gadget', 'gadgets');
        $registry = new ContentTypeExtensionRegistry([$widget, $gadget]);

        self::assertSame($gadget, $registry->get('gadget'));
        self::assertSame($widget, $registry->findByResourceKey('widgets'));
        self::assertSame(['widget', 'gadget'], $registry->types());
        self::assertSame(['widgets', 'gadgets'], $registry->resourceKeys());
    }

    public function testASecondExtensionForTheSameTypeIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Content type "widget" is registered twice');

        new ContentTypeExtensionRegistry([
            new FakeContentTypeExtension('widget', 'widgets'),
            new FakeContentTypeExtension('widget', 'other'),
        ]);
    }

    public function testASecondExtensionForTheSameResourceKeyIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Resource key "widgets" is registered twice');

        new ContentTypeExtensionRegistry([
            new FakeContentTypeExtension('widget', 'widgets'),
            new FakeContentTypeExtension('gadget', 'widgets'),
        ]);
    }
}
