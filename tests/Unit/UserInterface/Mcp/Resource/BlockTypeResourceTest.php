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
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataInterface;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Mcp\Application\Metadata\FieldNormalizer;
use Sulu\Mcp\UserInterface\Mcp\Resource\BlocksResource;

#[CoversClass(BlocksResource::class)]
final class BlockTypeResourceTest extends TestCase
{
    private MetadataProviderInterface&MockObject $formMetadataProvider;
    private BlocksResource $resource;

    protected function setUp(): void
    {
        $this->formMetadataProvider = $this->createMock(MetadataProviderInterface::class);
        $this->resource = new BlocksResource($this->formMetadataProvider, new FieldNormalizer());
    }

    public function testGetBlocksListsEachGlobalBlockFormOnceWithKeyLabelFields(): void
    {
        $headlineField = new FieldMetadata('headline');
        $headlineField->setType('text_line');

        $textBlockForm = new FormMetadata();
        $textBlockForm->setKey('text_block');
        $textBlockForm->setTitle('Text Block', 'en');
        $textBlockForm->addItem($headlineField);

        $blockMetadata = new TypedFormMetadata();
        $blockMetadata->addForm('text_block', $textBlockForm);

        $this->formMetadataProvider
            ->expects($this->once())
            ->method('getMetadata')
            ->with('block', 'en', ['ignore_global_blocks' => true])
            ->willReturn($blockMetadata);

        $result = $this->resource->getBlocks();

        $this->assertSame([
            [
                'key' => 'text_block',
                'label' => 'Text Block',
                'fields' => [
                    ['name' => 'headline', 'type' => 'text_line', 'label' => 'headline', 'required' => false],
                ],
            ],
        ], $result);
    }

    public function testGetBlocksFlattensSectionsInsideAGlobalBlockForm(): void
    {
        $headlineField = new FieldMetadata('headline');
        $headlineField->setType('text_line');

        $section = new SectionMetadata('content');
        $section->addItem($headlineField);

        $blockForm = new FormMetadata();
        $blockForm->setKey('text_block');
        $blockForm->setTitle('Text Block', 'en');
        $blockForm->addItem($section);

        $blockMetadata = new TypedFormMetadata();
        $blockMetadata->addForm('text_block', $blockForm);

        $this->formMetadataProvider
            ->method('getMetadata')
            ->willReturn($blockMetadata);

        $result = $this->resource->getBlocks();

        $this->assertSame(['headline'], \array_column($result[0]['fields'], 'name'));
        $this->assertNotContains('section', \array_column($result[0]['fields'], 'type'));
    }

    public function testGetBlocksNeverCallsProviderWithPageArticleOrSnippet(): void
    {
        $blockMetadata = new TypedFormMetadata();

        $this->formMetadataProvider
            ->method('getMetadata')
            ->willReturnCallback(function(string $key, string $locale, array $options) use ($blockMetadata) {
                $this->assertNotSame('page', $key);
                $this->assertNotSame('article', $key);
                $this->assertNotSame('snippet', $key);

                return $blockMetadata;
            });

        $this->resource->getBlocks();
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

    public function testGetBlocksReturnsEmptyArrayWhenNotTypedFormMetadata(): void
    {
        $nonTypedMetadata = $this->createMock(MetadataInterface::class);

        $this->formMetadataProvider
            ->method('getMetadata')
            ->willReturn($nonTypedMetadata);

        $result = $this->resource->getBlocks();

        $this->assertSame([], $result);
    }
}
