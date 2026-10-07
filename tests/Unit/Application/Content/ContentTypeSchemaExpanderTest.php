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
use Sulu\Mcp\Application\Content\ContentTypeSchemaExpander;
use Sulu\Mcp\Tests\Unit\Fixture\FakeContentTypeExtension;
use Sulu\Mcp\Tests\Unit\Fixture\FakeNotSearchableContentTypeExtension;

#[CoversClass(ContentTypeSchemaExpander::class)]
final class ContentTypeSchemaExpanderTest extends TestCase
{
    public function testThePreviewEnumListsPreviewableKeysAndSkipsNotSearchableOnes(): void
    {
        $expander = new ContentTypeSchemaExpander(new ContentTypeExtensionRegistry([
            new FakeContentTypeExtension('widget', 'widgets'),
            new FakeContentTypeExtension('gadget', 'gadgets'),
            new FakeNotSearchableContentTypeExtension('snippet', 'snippets'),
        ]));

        $schema = $expander->expandInputSchema(['type' => 'object', 'properties' => [
            'resourceKey' => ['type' => 'string', 'enum' => [ContentTypeSchemaExpander::SEARCHABLE_RESOURCE_KEYS]],
        ]]);

        self::assertSame(['widgets', 'gadgets'], $schema['properties']['resourceKey']['enum']);
    }

    public function testTheAdminLinkEnumListsEveryResourceKeyWithADetailView(): void
    {
        $expander = new ContentTypeSchemaExpander(new ContentTypeExtensionRegistry([]), [
            'tags' => ['views' => ['detail' => 'sulu_tag.edit_form']],
            'products' => ['views' => ['detail' => 'app.product_edit']],
            'pages_versions' => [],
        ]);

        $schema = $expander->expandInputSchema(['type' => 'object', 'properties' => [
            'resourceKey' => ['type' => 'string', 'enum' => [ContentTypeSchemaExpander::ADMIN_LINK_RESOURCE_KEYS]],
        ]]);

        self::assertSame(['products', 'tags'], $schema['properties']['resourceKey']['enum']);
        self::assertSame('Use "products", "tags".', $expander->expandText('Use ' . ContentTypeSchemaExpander::ADMIN_LINK_RESOURCE_KEYS . '.'));
    }
}
