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

use Mcp\Capability\Attribute\McpResource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataInterface;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Mcp\UserInterface\Mcp\Resource\BlocksResource;

#[CoversClass(BlocksResource::class)]
final class BlockTypeResourceTest extends TestCase
{
    private MetadataProviderInterface&MockObject $formMetadataProvider;
    private BlocksResource $resource;

    protected function setUp(): void
    {
        $this->formMetadataProvider = $this->createMock(MetadataProviderInterface::class);
        $this->resource = new BlocksResource($this->formMetadataProvider);
    }

    public function testGetBlocksDeduplicatesBlockTypesAcrossTemplates(): void
    {
        $blockForm = new FormMetadata();
        $blockForm->setKey('text_block');

        $blockField1 = new FieldMetadata('blocks');
        $blockField1->setType('block');
        $blockField1->addType($blockForm);

        $blockField2 = new FieldMetadata('blocks');
        $blockField2->setType('block');
        $blockField2->addType($blockForm);

        $form1 = new FormMetadata();
        $form1->setKey('template1');
        $form1->addItem($blockField1);

        $form2 = new FormMetadata();
        $form2->setKey('template2');
        $form2->addItem($blockField2);

        $typedMetadata = new TypedFormMetadata();
        $typedMetadata->addForm('template1', $form1);
        $typedMetadata->addForm('template2', $form2);

        $this->formMetadataProvider
            ->method('getMetadata')
            ->willReturn($typedMetadata);

        $result = $this->resource->getBlocks();

        $this->assertCount(1, $result, 'text_block should be deduplicated across templates');
        $this->assertSame('text_block', $result[0]['key']);
    }

    public function testGetBlocksAvailableInTemplatesListsAllTemplatesContainingBlock(): void
    {
        $blockForm = new FormMetadata();
        $blockForm->setKey('text_block');

        $blockField1 = new FieldMetadata('blocks');
        $blockField1->setType('block');
        $blockField1->addType($blockForm);

        $blockField2 = new FieldMetadata('blocks');
        $blockField2->setType('block');
        $blockField2->addType($blockForm);

        $form1 = new FormMetadata();
        $form1->setKey('template1');
        $form1->addItem($blockField1);

        $form2 = new FormMetadata();
        $form2->setKey('template2');
        $form2->addItem($blockField2);

        $typedMetadata = new TypedFormMetadata();
        $typedMetadata->addForm('template1', $form1);
        $typedMetadata->addForm('template2', $form2);

        $this->formMetadataProvider
            ->method('getMetadata')
            ->willReturn($typedMetadata);

        $result = $this->resource->getBlocks();

        $this->assertCount(1, $result);
        $availableIn = $result[0]['available_in_templates'];
        $this->assertContains('template1', $availableIn);
        $this->assertContains('template2', $availableIn);
    }

    public function testGetBlocksMethodHasMcpResourceAttribute(): void
    {
        $reflection = new \ReflectionMethod(BlocksResource::class, 'getBlocks');
        $attributes = $reflection->getAttributes(McpResource::class);

        $this->assertCount(1, $attributes, 'getBlocks() method must have exactly one #[McpResource] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu://blocks', $instance->uri);
        $this->assertSame('sulu_blocks', $instance->name);
    }

    public function testGetBlocksReturnsEmptyArrayWhenNoTemplates(): void
    {
        $nonTypedMetadata = $this->createMock(MetadataInterface::class);

        $this->formMetadataProvider
            ->method('getMetadata')
            ->willReturn($nonTypedMetadata);

        $result = $this->resource->getBlocks();

        $this->assertSame([], $result);
    }
}
