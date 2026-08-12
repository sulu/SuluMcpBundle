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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Mcp\UserInterface\Mcp\Resource\BlocksResource;

#[CoversClass(BlocksResource::class)]
final class BlocksResourceGlobalBlockTest extends TestCase
{
    private MetadataProviderInterface&MockObject $formMetadataProvider;
    private BlocksResource $resource;

    protected function setUp(): void
    {
        $this->formMetadataProvider = $this->createMock(MetadataProviderInterface::class);
        $this->resource = new BlocksResource($this->formMetadataProvider);
    }

    public function testResolvesFieldsFromGlobalBlockWhenTypeHasEmptyItems(): void
    {
        // Simulate a ref-based block type: FormMetadata with no items (empty)
        $refBlockForm = new FormMetadata();
        $refBlockForm->setKey('heading');
        $refBlockForm->setTitle('Heading', 'en');
        // No items added — simulates <type ref="heading"/>

        $blockField = new FieldMetadata('blocks');
        $blockField->setType('block');
        $blockField->addType($refBlockForm);

        $templateForm = new FormMetadata();
        $templateForm->setKey('default');
        $templateForm->addItem($blockField);

        $pageMetadata = new TypedFormMetadata();
        $pageMetadata->addForm('default', $templateForm);

        // Global block definition with actual fields
        $globalBlockForm = new FormMetadata();
        $globalBlockForm->setKey('heading');
        $globalBlockForm->setTitle('Heading', 'en');
        $titleField = new FieldMetadata('title');
        $titleField->setType('text_line');
        $titleField->setLabel('Heading Text', 'en');
        $globalBlockForm->addItem($titleField);

        $blockMetadata = new TypedFormMetadata();
        $blockMetadata->addForm('heading', $globalBlockForm);

        $this->formMetadataProvider
            ->method('getMetadata')
            ->willReturnCallback(fn (string $key) => match ($key) {
                'page' => $pageMetadata,
                'block' => $blockMetadata,
                default => throw new \LogicException('Unexpected metadata key: ' . $key),
            });

        $result = $this->resource->getBlocks();

        $this->assertCount(1, $result);
        $this->assertSame('heading', $result[0]['key']);
        $this->assertNotEmpty($result[0]['fields'], 'Fields should be resolved from global block definition');
        $this->assertSame('title', $result[0]['fields'][0]['name']);
        $this->assertSame('text_line', $result[0]['fields'][0]['type']);
    }

    public function testUsesInlineFieldsWhenTypeHasItems(): void
    {
        // Block type with inline items (not a ref)
        $blockForm = new FormMetadata();
        $blockForm->setKey('text');
        $blockForm->setTitle('Text', 'en');
        $contentField = new FieldMetadata('content');
        $contentField->setType('text_editor');
        $contentField->setLabel('Content', 'en');
        $blockForm->addItem($contentField);

        $blockField = new FieldMetadata('blocks');
        $blockField->setType('block');
        $blockField->addType($blockForm);

        $templateForm = new FormMetadata();
        $templateForm->setKey('default');
        $templateForm->addItem($blockField);

        $pageMetadata = new TypedFormMetadata();
        $pageMetadata->addForm('default', $templateForm);

        $this->formMetadataProvider
            ->method('getMetadata')
            ->willReturnCallback(fn (string $key) => match ($key) {
                'page' => $pageMetadata,
                default => throw new \LogicException('Should not load block metadata for inline blocks'),
            });

        $result = $this->resource->getBlocks();

        $this->assertCount(1, $result);
        $this->assertSame('text', $result[0]['key']);
        $this->assertCount(1, $result[0]['fields']);
        $this->assertSame('content', $result[0]['fields'][0]['name']);
    }

    public function testReturnsEmptyFieldsWhenGlobalBlockNotFound(): void
    {
        $refBlockForm = new FormMetadata();
        $refBlockForm->setKey('unknown_block');
        // No items — simulates ref to a non-existent block

        $blockField = new FieldMetadata('blocks');
        $blockField->setType('block');
        $blockField->addType($refBlockForm);

        $templateForm = new FormMetadata();
        $templateForm->setKey('default');
        $templateForm->addItem($blockField);

        $pageMetadata = new TypedFormMetadata();
        $pageMetadata->addForm('default', $templateForm);

        // Empty block metadata — no global blocks registered
        $blockMetadata = new TypedFormMetadata();

        $this->formMetadataProvider
            ->method('getMetadata')
            ->willReturnCallback(fn (string $key) => match ($key) {
                'page' => $pageMetadata,
                'block' => $blockMetadata,
                default => throw new \LogicException('Unexpected key: ' . $key),
            });

        $result = $this->resource->getBlocks();

        $this->assertCount(1, $result);
        $this->assertSame([], $result[0]['fields']);
    }

    public function testGlobalBlockMetadataIsCachedAcrossMultipleBlockTypes(): void
    {
        // Two ref-based block types
        $headingForm = new FormMetadata();
        $headingForm->setKey('heading');

        $textForm = new FormMetadata();
        $textForm->setKey('text');

        $blockField = new FieldMetadata('blocks');
        $blockField->setType('block');
        $blockField->addType($headingForm);
        $blockField->addType($textForm);

        $templateForm = new FormMetadata();
        $templateForm->setKey('default');
        $templateForm->addItem($blockField);

        $pageMetadata = new TypedFormMetadata();
        $pageMetadata->addForm('default', $templateForm);

        // Global blocks
        $globalHeading = new FormMetadata();
        $globalHeading->setKey('heading');
        $titleField = new FieldMetadata('title');
        $titleField->setType('text_line');
        $globalHeading->addItem($titleField);

        $globalText = new FormMetadata();
        $globalText->setKey('text');
        $contentField = new FieldMetadata('content');
        $contentField->setType('text_editor');
        $globalText->addItem($contentField);

        $blockMetadata = new TypedFormMetadata();
        $blockMetadata->addForm('heading', $globalHeading);
        $blockMetadata->addForm('text', $globalText);

        $callCount = 0;
        $this->formMetadataProvider
            ->method('getMetadata')
            ->willReturnCallback(function(string $key) use ($pageMetadata, $blockMetadata, &$callCount) {
                ++$callCount;

                return match ($key) {
                    'page' => $pageMetadata,
                    'block' => $blockMetadata,
                    default => throw new \LogicException('Unexpected key: ' . $key),
                };
            });

        $result = $this->resource->getBlocks();

        $this->assertCount(2, $result);
        // 'page' called once + 'block' called once = 2 total
        $this->assertSame(2, $callCount, 'Block metadata should only be loaded once (cached)');
    }

    public function testCyclicGlobalBlockDoesNotRecurseInfinitely(): void
    {
        // Template references global block 'section'
        $refSection = new FormMetadata();
        $refSection->setKey('section');

        $blockField = new FieldMetadata('blocks');
        $blockField->setType('block');
        $blockField->addType($refSection);

        $templateForm = new FormMetadata();
        $templateForm->setKey('default');
        $templateForm->addItem($blockField);

        $pageMetadata = new TypedFormMetadata();
        $pageMetadata->addForm('default', $templateForm);

        // Global 'section' contains a nested block field that refs 'section' again (cycle)
        $refSectionNested = new FormMetadata();
        $refSectionNested->setKey('section');

        $nestedBlockField = new FieldMetadata('inner');
        $nestedBlockField->setType('block');
        $nestedBlockField->addType($refSectionNested);

        $globalSection = new FormMetadata();
        $globalSection->setKey('section');
        $globalSection->setTitle('Section', 'en');
        $globalSection->addItem($nestedBlockField);

        $blockMetadata = new TypedFormMetadata();
        $blockMetadata->addForm('section', $globalSection);

        $this->formMetadataProvider
            ->method('getMetadata')
            ->willReturnCallback(fn (string $key) => match ($key) {
                'page' => $pageMetadata,
                'block' => $blockMetadata,
                default => throw new \LogicException('Unexpected key: ' . $key),
            });

        $result = $this->resource->getBlocks();

        // Terminates and lists 'section' once
        $this->assertCount(1, $result);
        $this->assertSame('section', $result[0]['key']);

        // The self-referencing nested 'section' is detected: marked cyclic, not recursed
        $innerField = $result[0]['fields'][0];
        $this->assertSame('inner', $innerField['name']);
        $this->assertTrue($innerField['types']['section']['cyclic']);
        $this->assertSame([], $innerField['types']['section']['fields']);
    }
}
