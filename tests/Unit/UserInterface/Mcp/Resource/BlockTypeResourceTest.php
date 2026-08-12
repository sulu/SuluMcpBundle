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
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TagMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataInterface;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Resource\Fixture\ArrayMetadataProvider;
use Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Resource\Fixture\RecordingFieldSchemaGenerator;
use Sulu\Mcp\UserInterface\Mcp\Resource\BlocksResource;

#[CoversClass(BlocksResource::class)]
final class BlockTypeResourceTest extends TestCase
{
    private RecordingFieldSchemaGenerator $schemaGenerator;

    protected function setUp(): void
    {
        $this->schemaGenerator = new RecordingFieldSchemaGenerator();
    }

    private function resource(array $metadata): BlocksResource
    {
        return new BlocksResource(new ArrayMetadataProvider($metadata), $this->schemaGenerator);
    }

    private function globalBlockTag(string $blockName): TagMetadata
    {
        $tag = new TagMetadata();
        $tag->setName('sulu.global_block');
        $tag->setAttributes(['global_block' => $blockName]);

        return $tag;
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

        $result = $this->resource(['page' => $typedMetadata])->getBlocks();

        $this->assertCount(1, $result, 'text_block should be deduplicated across templates');
        $this->assertSame('text_block', $result[0]['key']);
        $this->assertArrayHasKey('schema', $result[0]);
        $this->assertCount(1, $this->schemaGenerator->calls);
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

        $result = $this->resource(['page' => $typedMetadata])->getBlocks();

        $this->assertCount(1, $result);
        $availableIn = $result[0]['available_in_templates'];
        $this->assertContains('template1', $availableIn);
        $this->assertContains('template2', $availableIn);
    }

    public function testGetBlocksResolvesGlobalBlockReferenceBeforeGeneratingSchema(): void
    {
        $refForm = new FormMetadata();
        $refForm->setKey('heading');
        $refForm->addTag($this->globalBlockTag('heading'));

        $blockField = new FieldMetadata('blocks');
        $blockField->setType('block');
        $blockField->addType($refForm);

        $templateForm = new FormMetadata();
        $templateForm->setKey('default');
        $templateForm->addItem($blockField);

        $pageMetadata = new TypedFormMetadata();
        $pageMetadata->addForm('default', $templateForm);

        $titleField = new FieldMetadata('title');
        $titleField->setType('text_line');
        $globalHeading = new FormMetadata();
        $globalHeading->setKey('heading');
        $globalHeading->setTitle('Heading', 'en');
        $globalHeading->addItem($titleField);

        $blockMetadata = new TypedFormMetadata();
        $blockMetadata->addForm('heading', $globalHeading);

        $result = $this->resource(['page' => $pageMetadata, 'block' => $blockMetadata])->getBlocks();

        $this->assertSame('heading', $result[0]['key']);
        $this->assertSame('Heading', $result[0]['label']);
        $this->assertSame($globalHeading->getItems(), $this->schemaGenerator->calls[0]['items']);
    }

    public function testGetBlocksFallsBackToPlaceholderFormWhenGlobalBlockNotFound(): void
    {
        $refForm = new FormMetadata();
        $refForm->setKey('unknown_block');
        $refForm->addTag($this->globalBlockTag('unknown_block'));

        $blockField = new FieldMetadata('blocks');
        $blockField->setType('block');
        $blockField->addType($refForm);

        $templateForm = new FormMetadata();
        $templateForm->setKey('default');
        $templateForm->addItem($blockField);

        $pageMetadata = new TypedFormMetadata();
        $pageMetadata->addForm('default', $templateForm);

        $blockMetadata = new TypedFormMetadata();

        $result = $this->resource(['page' => $pageMetadata, 'block' => $blockMetadata])->getBlocks();

        $this->assertCount(1, $result);
        $this->assertSame('unknown_block', $result[0]['key']);
        $this->assertSame([], $this->schemaGenerator->calls[0]['items']);
    }

    public function testGetBlocksUsesInlineItemsWhenTypeIsNotTaggedGlobal(): void
    {
        $blockForm = new FormMetadata();
        $blockForm->setKey('text');
        $contentField = new FieldMetadata('content');
        $contentField->setType('text_editor');
        $blockForm->addItem($contentField);

        $blockField = new FieldMetadata('blocks');
        $blockField->setType('block');
        $blockField->addType($blockForm);

        $templateForm = new FormMetadata();
        $templateForm->setKey('default');
        $templateForm->addItem($blockField);

        $pageMetadata = new TypedFormMetadata();
        $pageMetadata->addForm('default', $templateForm);

        $result = $this->resource(['page' => $pageMetadata])->getBlocks();

        $this->assertSame('text', $result[0]['key']);
        $this->assertSame($blockForm->getItems(), $this->schemaGenerator->calls[0]['items']);
    }

    public function testGetBlocksFindsBlockFieldDeclaredInsideSection(): void
    {
        $blockForm = new FormMetadata();
        $blockForm->setKey('text');

        $blockField = new FieldMetadata('homeBlocks');
        $blockField->setType('block');
        $blockField->addType($blockForm);

        $content = new SectionMetadata('content');
        $content->addItem($blockField);

        $form = new FormMetadata();
        $form->setKey('homepage');
        $form->addItem($content);

        $pageMetadata = new TypedFormMetadata();
        $pageMetadata->addForm('homepage', $form);

        $result = $this->resource(['page' => $pageMetadata])->getBlocks();

        $this->assertCount(1, $result);
        $this->assertSame('text', $result[0]['key']);
        $this->assertContains('homepage', $result[0]['available_in_templates']);
    }

    public function testGetBlocksDiscoversBlockTypesInArticleTemplatesToo(): void
    {
        $quoteForm = new FormMetadata();
        $quoteForm->setKey('quote');

        $blockField = new FieldMetadata('blocks');
        $blockField->setType('block');
        $blockField->addType($quoteForm);

        $articleForm = new FormMetadata();
        $articleForm->setKey('article_default');
        $articleForm->addItem($blockField);

        $articleMetadata = new TypedFormMetadata();
        $articleMetadata->addForm('article_default', $articleForm);

        $result = $this->resource(['article' => $articleMetadata])->getBlocks();

        $this->assertCount(1, $result);
        $this->assertSame('quote', $result[0]['key']);
        $this->assertSame(['article_default'], $result[0]['available_in_templates']);
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
        $nonTypedMetadata = new class implements MetadataInterface {
            public function isCacheable(): bool
            {
                return false;
            }
        };

        $provider = new class($nonTypedMetadata) implements MetadataProviderInterface {
            public function __construct(private readonly MetadataInterface $metadata)
            {
            }

            public function getMetadata(string $key, string $locale, array $metadataOptions): MetadataInterface
            {
                return $this->metadata;
            }
        };

        $result = (new BlocksResource($provider, $this->schemaGenerator))->getBlocks();

        $this->assertSame([], $result);
    }
}
