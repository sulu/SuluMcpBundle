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
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TagMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Mcp\Application\Metadata\FieldNormalizer;
use Sulu\Mcp\Application\Metadata\MetadataLocaleResolver;
use Sulu\Mcp\UserInterface\Mcp\Resource\GlobalBlocksResource;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

#[CoversClass(GlobalBlocksResource::class)]
final class GlobalBlocksResourceReferenceTest extends TestCase
{
    private MetadataProviderInterface&MockObject $formMetadataProvider;
    private GlobalBlocksResource $resource;

    protected function setUp(): void
    {
        $this->formMetadataProvider = $this->createMock(MetadataProviderInterface::class);
        $this->resource = new GlobalBlocksResource($this->formMetadataProvider, new FieldNormalizer(), new MetadataLocaleResolver(new TokenStorage(), 'en'));
    }

    public function testGlobalBlockFieldReferencingAnotherGlobalBlockEmitsReferenceShape(): void
    {
        $tag = new TagMetadata();
        $tag->setName('sulu.global_block');
        $tag->setAttributes(['global_block' => 'hero']);

        $heroRefForm = new FormMetadata();
        $heroRefForm->setKey('hero');
        $heroRefForm->setTitle('Hero', 'en');
        $heroRefForm->addTag($tag);

        $itemsField = new FieldMetadata('items');
        $itemsField->setType('block');
        $itemsField->addType($heroRefForm);

        $sectionForm = new FormMetadata();
        $sectionForm->setKey('section');
        $sectionForm->setTitle('Section', 'en');
        $sectionForm->addItem($itemsField);

        $blockMetadata = new TypedFormMetadata();
        $blockMetadata->addForm('section', $sectionForm);

        $this->formMetadataProvider
            ->method('getMetadata')
            ->willReturn($blockMetadata);

        $result = $this->resource->getGlobalBlocks();

        $this->assertCount(1, $result);
        $this->assertSame('section', $result[0]['key']);
        $this->assertSame([
            'key' => 'hero',
            'label' => 'Hero',
            'globalBlock' => 'hero',
        ], $result[0]['fields'][0]['types']['hero']);
        $this->assertArrayNotHasKey('fields', $result[0]['fields'][0]['types']['hero']);
    }

    public function testSelfReferencingGlobalBlockTerminatesWithReferenceShape(): void
    {
        $tag = new TagMetadata();
        $tag->setName('sulu.global_block');
        $tag->setAttributes(['global_block' => 'section']);

        $sectionRefForm = new FormMetadata();
        $sectionRefForm->setKey('section');
        $sectionRefForm->setTitle('Section', 'en');
        $sectionRefForm->addTag($tag);

        $childrenField = new FieldMetadata('children');
        $childrenField->setType('block');
        $childrenField->addType($sectionRefForm);

        $sectionForm = new FormMetadata();
        $sectionForm->setKey('section');
        $sectionForm->setTitle('Section', 'en');
        $sectionForm->addItem($childrenField);

        $blockMetadata = new TypedFormMetadata();
        $blockMetadata->addForm('section', $sectionForm);

        $this->formMetadataProvider
            ->method('getMetadata')
            ->willReturn($blockMetadata);

        $result = $this->resource->getGlobalBlocks();

        $this->assertCount(1, $result);
        $this->assertSame('section', $result[0]['key']);
        $this->assertSame([
            'key' => 'section',
            'label' => 'Section',
            'globalBlock' => 'section',
        ], $result[0]['fields'][0]['types']['section']);
    }
}
