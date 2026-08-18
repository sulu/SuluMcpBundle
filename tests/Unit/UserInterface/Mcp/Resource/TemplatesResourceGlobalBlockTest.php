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
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TagMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Mcp\Application\Metadata\FieldNormalizer;
use Sulu\Mcp\Application\Metadata\MetadataLocaleResolver;
use Sulu\Mcp\Tests\Unit\Fixture\ArrayMetadataProvider;
use Sulu\Mcp\UserInterface\Mcp\Resource\TemplatesResource;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

#[CoversClass(TemplatesResource::class)]
final class TemplatesResourceGlobalBlockTest extends TestCase
{
    private ArrayMetadataProvider $formMetadataProvider;
    private TemplatesResource $resource;

    protected function setUp(): void
    {
        $this->formMetadataProvider = new ArrayMetadataProvider();
        $this->resource = new TemplatesResource($this->formMetadataProvider, new FieldNormalizer(), new MetadataLocaleResolver(new TokenStorage(), 'en'));
    }

    public function testGlobalBlockTypeProducesReferenceWithoutFieldsOrRecursion(): void
    {
        $tag = new TagMetadata();
        $tag->setName('sulu.global_block');
        $tag->setAttributes(['global_block' => 'heading']);

        $typeForm = new FormMetadata();
        $typeForm->setKey('heading');
        $typeForm->setTitle('Heading', 'en');
        $typeForm->addTag($tag);

        $blockField = new FieldMetadata('blocks');
        $blockField->setType('block');
        $blockField->addType($typeForm);

        $templateForm = new FormMetadata();
        $templateForm->setKey('default');
        $templateForm->addItem($blockField);

        $pageMetadata = new TypedFormMetadata();
        $pageMetadata->addForm('default', $templateForm);

        $this->formMetadataProvider->set('page', $pageMetadata);

        $result = $this->resource->getTemplates();

        $blocksField = $result['page']['default']['fields'][0];
        $this->assertSame([
            'heading' => [
                'key' => 'heading',
                'label' => 'Heading',
                'globalBlock' => 'heading',
            ],
        ], $blocksField['types']);
        $this->assertArrayNotHasKey('fields', $blocksField['types']['heading']);
    }

    public function testUntaggedTypeIsInlined(): void
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

        $this->formMetadataProvider->set('page', $pageMetadata);

        $result = $this->resource->getTemplates();

        $blocksField = $result['page']['default']['fields'][0];
        $textType = $blocksField['types']['text'];
        $this->assertCount(1, $textType['fields']);
        $this->assertSame('content', $textType['fields'][0]['name']);
    }

    public function testMixOfTaggedAndUntaggedTypes(): void
    {
        $tag = new TagMetadata();
        $tag->setName('sulu.global_block');
        $tag->setAttributes(['global_block' => 'quote']);

        $globalTypeForm = new FormMetadata();
        $globalTypeForm->setKey('quote');
        $globalTypeForm->setTitle('Quote', 'en');
        $globalTypeForm->addTag($tag);

        $localTypeForm = new FormMetadata();
        $localTypeForm->setKey('text');
        $localTypeForm->setTitle('Text', 'en');
        $textField = new FieldMetadata('content');
        $textField->setType('text_editor');
        $localTypeForm->addItem($textField);

        $blockField = new FieldMetadata('blocks');
        $blockField->setType('block');
        $blockField->addType($globalTypeForm);
        $blockField->addType($localTypeForm);

        $templateForm = new FormMetadata();
        $templateForm->setKey('homepage');
        $templateForm->addItem($blockField);

        $pageMetadata = new TypedFormMetadata();
        $pageMetadata->addForm('homepage', $templateForm);

        $this->formMetadataProvider->set('page', $pageMetadata);

        $result = $this->resource->getTemplates();

        $blocksField = $result['page']['homepage']['fields'][0];
        $this->assertCount(2, $blocksField['types']);

        $quoteType = $blocksField['types']['quote'];
        $this->assertSame('quote', $quoteType['globalBlock']);
        $this->assertArrayNotHasKey('fields', $quoteType);

        $textType = $blocksField['types']['text'];
        $this->assertCount(1, $textType['fields']);
        $this->assertSame('content', $textType['fields'][0]['name']);
    }

    public function testProviderIsNotAskedForBlockCatalogue(): void
    {
        $tag = new TagMetadata();
        $tag->setName('sulu.global_block');
        $tag->setAttributes(['global_block' => 'section']);

        $typeForm = new FormMetadata();
        $typeForm->setKey('section');
        $typeForm->setTitle('Section', 'en');
        $typeForm->addTag($tag);

        $blockField = new FieldMetadata('blocks');
        $blockField->setType('block');
        $blockField->addType($typeForm);

        $templateForm = new FormMetadata();
        $templateForm->setKey('default');
        $templateForm->addItem($blockField);

        $pageMetadata = new TypedFormMetadata();
        $pageMetadata->addForm('default', $templateForm);

        $this->formMetadataProvider->set('page', $pageMetadata);

        $this->resource->getTemplates();

        $this->assertNotContains('block', $this->formMetadataProvider->requestedKeys());
    }
}
