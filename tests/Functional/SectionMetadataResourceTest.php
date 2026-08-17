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
use Sulu\Mcp\Application\Content\BlockDataValidator;
use Sulu\Mcp\UserInterface\Mcp\Resource\GlobalBlocksResource;
use Sulu\Mcp\UserInterface\Mcp\Resource\TemplatesResource;

#[CoversNothing]
final class SectionMetadataResourceTest extends FunctionalTestCase
{
    public function testTemplateSectionFieldsSurfaceFlatWithLocalTypesInlinedAndGlobalTypesReferenced(): void
    {
        /** @var TemplatesResource $resource */
        $resource = self::getContainer()->get(TemplatesResource::class);
        $templates = $resource->getTemplates();

        self::assertArrayHasKey('sections_demo', $templates['page']);
        $fields = $templates['page']['sections_demo']['fields'];
        $byName = \array_column($fields, null, 'name');

        self::assertArrayHasKey('subtitle', $byName, 'field inside <section> must surface');
        self::assertArrayHasKey('teaser', $byName, 'field inside nested <section> must surface');
        self::assertArrayHasKey('section_blocks', $byName, 'block inside <section> must surface');
        self::assertNotContains('section', \array_column($fields, 'type'));

        $types = $byName['section_blocks']['types'];
        self::assertSame(['body'], \array_column($types['plain']['fields'], 'name'), 'local type is inlined');
        self::assertSame('heading', $types['heading']['globalBlock'], 'global type is a reference');
        self::assertArrayNotHasKey('fields', $types['heading']);
    }

    public function testBlocksCatalogueListsEachGlobalBlockOnceWithFlatFieldsAndNestedReferences(): void
    {
        /** @var GlobalBlocksResource $resource */
        $resource = self::getContainer()->get(GlobalBlocksResource::class);
        $byKey = \array_column($resource->getGlobalBlocks(), null, 'key');

        self::assertSame(['note', 'boxTitle'], \array_column($byKey['box']['fields'], 'name'), 'section inside a global block is flattened');
        $boxByName = \array_column($byKey['box']['fields'], null, 'name');
        self::assertTrue($boxByName['boxTitle']['required'], 'required survives flattening');

        $sectionBlock = \array_column($byKey['section']['fields'], null, 'name');
        foreach (['text', 'heading', 'quote'] as $ref) {
            self::assertSame($ref, $sectionBlock['blocks']['types'][$ref]['globalBlock']);
            self::assertArrayNotHasKey('fields', $sectionBlock['blocks']['types'][$ref], 'nested global reference is not expanded');
        }
    }

    public function testBlockDataInsideSectionValidates(): void
    {
        /** @var BlockDataValidator $validator */
        $validator = self::getContainer()->get(BlockDataValidator::class);

        self::assertNull(
            $validator->validate('page', 'default', 'box', ['boxTitle' => 'T']),
            'a field declared inside a <section> of a block form is a valid key',
        );

        $error = $validator->validate('page', 'default', 'box', ['bogus' => 'x']);
        self::assertNotNull($error);
        self::assertStringContainsString('boxTitle', $error['error'], 'section fields are listed among the valid keys');
    }
}
