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
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataInterface;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Resource\Fixture\ArrayMetadataProvider;
use Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Resource\Fixture\RecordingFieldSchemaGenerator;
use Sulu\Mcp\UserInterface\Mcp\Resource\TemplatesResource;

#[CoversClass(TemplatesResource::class)]
final class TemplateResourceTest extends TestCase
{
    private RecordingFieldSchemaGenerator $schemaGenerator;

    protected function setUp(): void
    {
        $this->schemaGenerator = new RecordingFieldSchemaGenerator();
    }

    private function resource(array $metadata): TemplatesResource
    {
        return new TemplatesResource(new ArrayMetadataProvider($metadata), $this->schemaGenerator);
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

        $result = $this->resource(['page' => $typedMetadata])->getTemplates();

        $this->assertArrayHasKey('page', $result);
        $this->assertArrayHasKey('default', $result['page']);
        $this->assertArrayHasKey('schema', $result['page']['default']);
        $this->assertIsArray($result['page']['default']['schema']);
    }

    public function testGetTemplatesEnvelopeIncludesKeyLabelAndGeneratedSchema(): void
    {
        $field = new FieldMetadata('title');
        $field->setType('text_line');

        $form = new FormMetadata();
        $form->setKey('default');
        $form->setTitle('Default', 'en');
        $form->addItem($field);

        $typedMetadata = new TypedFormMetadata();
        $typedMetadata->addForm('default', $form);

        $result = $this->resource(['page' => $typedMetadata])->getTemplates();

        $entry = $result['page']['default'];
        $this->assertSame('default', $entry['key']);
        $this->assertSame('Default', $entry['label']);
        $this->assertSame(['title'], \array_values($entry['schema']['x-sulu-test-item-names']));
        $this->assertSame('en', $entry['schema']['x-sulu-test-locale']);
    }

    public function testGetTemplatesPassesTheFormsRawItemsToTheGenerator(): void
    {
        $field = new FieldMetadata('title');
        $field->setType('text_line');

        $form = new FormMetadata();
        $form->setKey('default');
        $form->addItem($field);

        $typedMetadata = new TypedFormMetadata();
        $typedMetadata->addForm('default', $form);

        $this->resource(['page' => $typedMetadata])->getTemplates();

        $this->assertCount(1, $this->schemaGenerator->calls);
        $this->assertSame($form->getItems(), $this->schemaGenerator->calls[0]['items']);
        $this->assertSame('en', $this->schemaGenerator->calls[0]['locale']);
    }

    public function testGetTemplatesGroupsPageArticleAndSnippet(): void
    {
        $buildTyped = function (string $templateKey, string $fieldName): TypedFormMetadata {
            $field = new FieldMetadata($fieldName);
            $field->setType('text_line');
            $form = new FormMetadata();
            $form->setKey($templateKey);
            $form->addItem($field);
            $typed = new TypedFormMetadata();
            $typed->addForm($templateKey, $form);

            return $typed;
        };

        $result = $this->resource([
            'page' => $buildTyped('default', 'title'),
            'article' => $buildTyped('blog', 'headline'),
            'snippet' => $buildTyped('teaser', 'label'),
        ])->getTemplates();

        $this->assertSame(['page', 'article', 'snippet'], array_keys($result));
        $this->assertArrayHasKey('default', $result['page']);
        $this->assertArrayHasKey('blog', $result['article']);
        $this->assertArrayHasKey('teaser', $result['snippet']);
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

        $provider = new class($pageMetadata) implements MetadataProviderInterface {
            public function __construct(private readonly TypedFormMetadata $pageMetadata)
            {
            }

            public function getMetadata(string $key, string $locale, array $metadataOptions): MetadataInterface
            {
                return match ($key) {
                    'page' => $this->pageMetadata,
                    'article' => throw new \RuntimeException('Article metadata not installed'),
                    default => throw new \RuntimeException(\sprintf('No metadata registered for "%s".', $key)),
                };
            }
        };

        $result = (new TemplatesResource($provider, $this->schemaGenerator))->getTemplates();

        $this->assertSame(['page'], array_keys($result));
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

        $result = (new TemplatesResource($provider, $this->schemaGenerator))->getTemplates();

        $this->assertSame([], $result);
    }

    public function testGetTemplatesResourceDescriptionMentionsGrouping(): void
    {
        $reflection = new \ReflectionMethod(TemplatesResource::class, 'getTemplates');
        $attribute = $reflection->getAttributes(McpResource::class)[0]->newInstance();

        $this->assertStringContainsString('page', $attribute->description);
        $this->assertStringContainsString('article', $attribute->description);
        $this->assertStringContainsString('snippet', $attribute->description);
    }
}
