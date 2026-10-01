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
use Sulu\Mcp\Application\Content\ContentTypeSchemaExpander;
use Sulu\Mcp\Application\Metadata\FieldNormalizer;
use Sulu\Mcp\Application\Metadata\MetadataLocaleResolver;
use Sulu\Mcp\Tests\Unit\Fixture\ArrayMetadataProvider;
use Sulu\Mcp\Tests\Unit\Fixture\ContentTypes;
use Sulu\Mcp\Tests\Unit\Fixture\FakeContentTypeExtension;
use Sulu\Mcp\UserInterface\Mcp\Resource\TemplatesResource;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

#[CoversClass(TemplatesResource::class)]
final class TemplateResourceTest extends TestCase
{
    use ProphecyTrait;

    private ArrayMetadataProvider $formMetadataProvider;
    private TemplatesResource $resource;

    protected function setUp(): void
    {
        $this->formMetadataProvider = new ArrayMetadataProvider();
        $this->resource = new TemplatesResource($this->formMetadataProvider, new FieldNormalizer(), new MetadataLocaleResolver(new TokenStorage(), 'en'), ContentTypes::inertResolver());
    }

    public function testGetTemplatesReturnsTemplatesGroupedByContentType(): void
    {
        $field = new FieldMetadata('title');
        $field->setType('text_line');

        $form = new FormMetadata();
        $form->setKey('default');
        $form->addItem($field);

        $typedMetadata = new TypedFormMetadata();
        $typedMetadata->addForm('default', $form);

        $this->formMetadataProvider->set('page', $typedMetadata);

        $result = $this->resource->getTemplates();

        $this->assertArrayHasKey('pages', $result);
        $this->assertArrayHasKey('default', $result['pages']);
        $this->assertArrayHasKey('fields', $result['pages']['default']);
        $this->assertIsArray($result['pages']['default']['fields']);
    }

    public function testGetTemplatesFieldIncludesNameTypeLabel(): void
    {
        $field = new FieldMetadata('title');
        $field->setType('text_line');
        $field->setLabel('Title', 'en');

        $form = new FormMetadata();
        $form->setKey('default');
        $form->addItem($field);

        $typedMetadata = new TypedFormMetadata();
        $typedMetadata->addForm('default', $form);

        $this->formMetadataProvider->set('page', $typedMetadata);

        $result = $this->resource->getTemplates();

        $fields = $result['pages']['default']['fields'];
        $this->assertCount(1, $fields);
        $this->assertArrayHasKey('name', $fields[0]);
        $this->assertArrayHasKey('type', $fields[0]);
        $this->assertArrayHasKey('label', $fields[0]);
        $this->assertArrayHasKey('required', $fields[0]);
        $this->assertSame('title', $fields[0]['name']);
        $this->assertSame('text_line', $fields[0]['type']);
    }

    public function testGetTemplatesGroupsPageArticleAndSnippet(): void
    {
        $buildTyped = function(string $templateKey, string $fieldName): TypedFormMetadata {
            $field = new FieldMetadata($fieldName);
            $field->setType('text_line');
            $form = new FormMetadata();
            $form->setKey($templateKey);
            $form->addItem($field);
            $typed = new TypedFormMetadata();
            $typed->addForm($templateKey, $form);

            return $typed;
        };

        $pageMetadata = $buildTyped('default', 'title');
        $articleMetadata = $buildTyped('blog', 'headline');
        $snippetMetadata = $buildTyped('teaser', 'label');

        $this->formMetadataProvider->set('page', $pageMetadata);
        $this->formMetadataProvider->set('article', $articleMetadata);
        $this->formMetadataProvider->set('snippet', $snippetMetadata);

        $result = $this->resource->getTemplates();

        $this->assertSame(['pages', 'articles', 'snippets'], \array_keys($result));
        $this->assertArrayHasKey('default', $result['pages']);
        $this->assertArrayHasKey('blog', $result['articles']);
        $this->assertArrayHasKey('teaser', $result['snippets']);
        $this->assertSame('headline', $result['articles']['blog']['fields'][0]['name']);
    }

    public function testGetTemplatesIncludesARegisteredExtensionType(): void
    {
        $resource = new TemplatesResource(
            $this->formMetadataProvider,
            new FieldNormalizer(),
            new MetadataLocaleResolver(new TokenStorage(), 'en'),
            ContentTypes::inertResolver([new FakeContentTypeExtension()]),
        );

        $field = new FieldMetadata('title');
        $field->setType('text_line');
        $form = new FormMetadata();
        $form->setKey('default');
        $form->addItem($field);
        $widgetMetadata = new TypedFormMetadata();
        $widgetMetadata->addForm('default', $form);

        $this->formMetadataProvider->set('widget', $widgetMetadata);

        $result = $resource->getTemplates();

        $this->assertArrayHasKey('widgets', $result);
        $this->assertArrayHasKey('default', $result['widgets']);
    }

    public function testGetTemplatesOmitsContentTypesWithoutMetadata(): void
    {
        $field = new FieldMetadata('title');
        $field->setType('text_line');
        $form = new FormMetadata();
        $form->setKey('default');
        $form->addItem($field);
        $pageMetadata = new TypedFormMetadata();
        $pageMetadata->addForm('default', $form);

        $this->formMetadataProvider->set('page', $pageMetadata);

        $result = $this->resource->getTemplates();

        $this->assertSame(['pages'], \array_keys($result));
    }

    public function testGetTemplatesMethodHasMcpResourceAttribute(): void
    {
        $reflection = new \ReflectionMethod(TemplatesResource::class, 'getTemplates');
        $attributes = $reflection->getAttributes(McpResource::class);

        $this->assertCount(1, $attributes, 'getTemplates() method must have exactly one #[McpResource] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu://templates', $instance->uri);
        $this->assertSame('sulu_templates', $instance->name);
    }

    public function testGetTemplatesReturnsEmptyArrayWhenProviderReturnsNonTypedFormMetadata(): void
    {
        $nonTypedMetadata = $this->prophesize(MetadataInterface::class);

        $this->formMetadataProvider->setDefault($nonTypedMetadata->reveal());

        $result = $this->resource->getTemplates();

        $this->assertSame([], $result);
    }

    public function testGetTemplatesResourceDescriptionMentionsGrouping(): void
    {
        $reflection = new \ReflectionMethod(TemplatesResource::class, 'getTemplates');
        $attribute = $reflection->getAttributes(McpResource::class)[0]->newInstance();

        $this->assertStringContainsString('resourceKey', $attribute->description);
        $this->assertStringContainsString(ContentTypeSchemaExpander::RESOURCE_KEYS, $attribute->description);
    }

    public function testGetTemplatesFlattensSectionFieldsWithoutSectionEntry(): void
    {
        $title = new FieldMetadata('title');
        $title->setType('text_line');

        $nestedField = new FieldMetadata('subtitle');
        $nestedField->setType('text_line');
        $nestedSection = new SectionMetadata('nested');
        $nestedSection->addItem($nestedField);

        $section = new SectionMetadata('content');
        $section->addItem($title);
        $section->addItem($nestedSection);

        $form = new FormMetadata();
        $form->setKey('default');
        $form->addItem($section);

        $typedMetadata = new TypedFormMetadata();
        $typedMetadata->addForm('default', $form);

        $this->formMetadataProvider->set('page', $typedMetadata);

        $result = $this->resource->getTemplates();

        $fields = $result['pages']['default']['fields'];
        $this->assertSame(['title', 'subtitle'], \array_column($fields, 'name'));
        $this->assertNotContains('section', \array_column($fields, 'type'));
    }

    public function testGetTemplatesDiscoversBlockFieldInsideSection(): void
    {
        $itemField = new FieldMetadata('headline');
        $itemField->setType('text_line');

        $typeForm = new FormMetadata();
        $typeForm->setKey('default');
        $typeForm->setTitle('Default', 'en');
        $typeForm->addItem($itemField);

        $block = new FieldMetadata('blocks');
        $block->setType('block');
        $block->addType($typeForm);

        $section = new SectionMetadata('content');
        $section->addItem($block);

        $form = new FormMetadata();
        $form->setKey('default');
        $form->addItem($section);

        $typedMetadata = new TypedFormMetadata();
        $typedMetadata->addForm('default', $form);

        $this->formMetadataProvider->set('page', $typedMetadata);

        $result = $this->resource->getTemplates();

        $fields = $result['pages']['default']['fields'];
        $this->assertSame('blocks', $fields[0]['name']);
        $this->assertArrayHasKey('types', $fields[0]);
        $this->assertSame('headline', $fields[0]['types']['default']['fields'][0]['name']);
    }
}
