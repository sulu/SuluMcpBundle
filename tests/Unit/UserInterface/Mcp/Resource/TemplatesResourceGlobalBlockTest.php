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
use Sulu\Mcp\UserInterface\Mcp\Resource\TemplatesResource;

#[CoversClass(TemplatesResource::class)]
final class TemplatesResourceGlobalBlockTest extends TestCase
{
    private MetadataProviderInterface&MockObject $formMetadataProvider;
    private TemplatesResource $resource;

    protected function setUp(): void
    {
        $this->formMetadataProvider = $this->createMock(MetadataProviderInterface::class);
        $this->resource = new TemplatesResource($this->formMetadataProvider);
    }

    public function testResolvesBlockFieldsFromGlobalBlockDefinition(): void
    {
        // Template with a ref-based block type (empty items)
        $refBlockForm = new FormMetadata();
        $refBlockForm->setKey('heading');
        $refBlockForm->setTitle('Heading', 'en');

        $blockField = new FieldMetadata('blocks');
        $blockField->setType('block');
        $blockField->addType($refBlockForm);

        $templateForm = new FormMetadata();
        $templateForm->setKey('default');
        $templateForm->addItem($blockField);

        $pageMetadata = new TypedFormMetadata();
        $pageMetadata->addForm('default', $templateForm);

        // Global block with actual fields
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
                default => throw new \LogicException('Unexpected: ' . $key),
            });

        $result = $this->resource->getTemplates();

        $this->assertArrayHasKey('page', $result);
        $this->assertArrayHasKey('default', $result['page']);
        $fields = $result['page']['default']['fields'];

        // Find the blocks field
        $blocksField = null;
        foreach ($fields as $f) {
            if ('blocks' === $f['name']) {
                $blocksField = $f;
            }
        }

        $this->assertNotNull($blocksField, 'Template should have a blocks field');
        $this->assertArrayHasKey('types', $blocksField);
        $this->assertArrayHasKey('heading', $blocksField['types']);

        $headingType = $blocksField['types']['heading'];
        $this->assertNotEmpty($headingType['fields'], 'Block type fields should be resolved from global block');
        $this->assertSame('title', $headingType['fields'][0]['name']);
        $this->assertSame('text_line', $headingType['fields'][0]['type']);
    }

    public function testPreservesInlineBlockFields(): void
    {
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

        $result = $this->resource->getTemplates();

        $blocksField = null;
        foreach ($result['page']['default']['fields'] as $f) {
            if ('blocks' === $f['name']) {
                $blocksField = $f;
            }
        }

        $this->assertNotNull($blocksField);
        $textType = $blocksField['types']['text'];
        $this->assertCount(1, $textType['fields']);
        $this->assertSame('content', $textType['fields'][0]['name']);
    }

    public function testResolvesMultipleGlobalBlockTypes(): void
    {
        $headingForm = new FormMetadata();
        $headingForm->setKey('heading');

        $quoteForm = new FormMetadata();
        $quoteForm->setKey('quote');

        $blockField = new FieldMetadata('blocks');
        $blockField->setType('block');
        $blockField->addType($headingForm);
        $blockField->addType($quoteForm);

        $templateForm = new FormMetadata();
        $templateForm->setKey('homepage');
        $templateForm->addItem($blockField);

        $pageMetadata = new TypedFormMetadata();
        $pageMetadata->addForm('homepage', $templateForm);

        // Global blocks
        $globalHeading = new FormMetadata();
        $globalHeading->setKey('heading');
        $globalHeading->setTitle('Heading', 'en');
        $titleField = new FieldMetadata('title');
        $titleField->setType('text_line');
        $globalHeading->addItem($titleField);

        $globalQuote = new FormMetadata();
        $globalQuote->setKey('quote');
        $globalQuote->setTitle('Quote', 'en');
        $textField = new FieldMetadata('text');
        $textField->setType('text_editor');
        $globalQuote->addItem($textField);
        $attrField = new FieldMetadata('attribution');
        $attrField->setType('text_line');
        $globalQuote->addItem($attrField);

        $blockMetadata = new TypedFormMetadata();
        $blockMetadata->addForm('heading', $globalHeading);
        $blockMetadata->addForm('quote', $globalQuote);

        $this->formMetadataProvider
            ->method('getMetadata')
            ->willReturnCallback(fn (string $key) => match ($key) {
                'page' => $pageMetadata,
                'block' => $blockMetadata,
                default => throw new \LogicException('Unexpected: ' . $key),
            });

        $result = $this->resource->getTemplates();

        $blocksField = null;
        foreach ($result['page']['homepage']['fields'] as $f) {
            if ('blocks' === $f['name']) {
                $blocksField = $f;
            }
        }

        $this->assertCount(2, $blocksField['types']);

        $headingType = $blocksField['types']['heading'];
        $this->assertCount(1, $headingType['fields']);
        $this->assertSame('title', $headingType['fields'][0]['name']);

        $quoteType = $blocksField['types']['quote'];
        $this->assertCount(2, $quoteType['fields']);
        $this->assertSame('text', $quoteType['fields'][0]['name']);
        $this->assertSame('attribution', $quoteType['fields'][1]['name']);
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

        $result = $this->resource->getTemplates();

        $blocksField = null;
        foreach ($result['page']['default']['fields'] as $f) {
            if ('blocks' === $f['name']) {
                $blocksField = $f;
            }
        }

        // Terminates and resolves the 'section' block type
        $this->assertNotNull($blocksField);
        $sectionType = $blocksField['types']['section'];

        // The self-referencing nested 'section' is detected: marked cyclic, not recursed
        $innerField = $sectionType['fields'][0];
        $this->assertSame('inner', $innerField['name']);
        $this->assertTrue($innerField['types']['section']['cyclic']);
        $this->assertSame([], $innerField['types']['section']['fields']);
    }
}
