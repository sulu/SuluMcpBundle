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
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataInterface;
use Sulu\Mcp\Application\Metadata\FieldNormalizer;
use Sulu\Mcp\Application\Metadata\MetadataLocaleResolver;
use Sulu\Mcp\Tests\Unit\Fixture\ArrayMetadataProvider;
use Sulu\Mcp\UserInterface\Mcp\Resource\GlobalBlocksResource;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

#[CoversClass(GlobalBlocksResource::class)]
final class GlobalBlocksResourceTest extends TestCase
{
    use ProphecyTrait;

    private ArrayMetadataProvider $formMetadataProvider;
    private GlobalBlocksResource $resource;

    protected function setUp(): void
    {
        $this->formMetadataProvider = new ArrayMetadataProvider();
        $this->resource = new GlobalBlocksResource($this->formMetadataProvider, new FieldNormalizer(), new MetadataLocaleResolver(new TokenStorage(), 'en'));
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

        $this->formMetadataProvider->set('block', $blockMetadata);

        $result = $this->resource->getGlobalBlocks();

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

        $this->formMetadataProvider->setDefault($blockMetadata);

        $result = $this->resource->getGlobalBlocks();

        $this->assertSame(['headline'], \array_column($result[0]['fields'], 'name'));
        $this->assertNotContains('section', \array_column($result[0]['fields'], 'type'));
    }

    public function testGetBlocksNeverCallsProviderWithPageArticleOrSnippet(): void
    {
        $blockMetadata = new TypedFormMetadata();

        $this->formMetadataProvider->set('block', $blockMetadata);

        $this->resource->getGlobalBlocks();

        $this->assertSame(['block'], $this->formMetadataProvider->requestedKeys());
    }

    public function testGetBlocksMethodHasMcpResourceAttribute(): void
    {
        $reflection = new \ReflectionMethod(GlobalBlocksResource::class, 'getGlobalBlocks');
        $attributes = $reflection->getAttributes(McpResource::class);

        $this->assertCount(1, $attributes, 'getGlobalBlocks() method must have exactly one #[McpResource] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu://global_blocks', $instance->uri);
        $this->assertSame('sulu_global_blocks', $instance->name);
    }

    public function testGetBlocksReturnsEmptyArrayWhenNotTypedFormMetadata(): void
    {
        $nonTypedMetadata = $this->prophesize(MetadataInterface::class);

        $this->formMetadataProvider->setDefault($nonTypedMetadata->reveal());

        $result = $this->resource->getGlobalBlocks();

        $this->assertSame([], $result);
    }
}
