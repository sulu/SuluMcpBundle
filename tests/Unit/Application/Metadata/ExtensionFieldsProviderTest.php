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

namespace Sulu\Mcp\Tests\Unit\Application\Metadata;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Mcp\Application\Metadata\ExtensionFieldsProvider;
use Sulu\Mcp\Application\Metadata\MetadataLocaleResolver;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

#[CoversClass(ExtensionFieldsProvider::class)]
final class ExtensionFieldsProviderTest extends TestCase
{
    public function testReturnsSeoAndExcerptFieldsWithStrippedNames(): void
    {
        $provider = $this->createMock(MetadataProviderInterface::class);
        $provider->method('getMetadata')->willReturnCallback(
            fn (string $key) => match ($key) {
                'content_seo_metadata' => $this->form(['seo/title' => 'text_line', 'seoNoIndex' => 'checkbox'], ['seoNoIndex' => true]),
                'content_excerpt_metadata' => $this->form(['excerpt/image' => 'single_media_selection']),
                'content_excerpt_taxonomies' => $this->form(['excerptCategories' => 'category_selection']),
                default => $this->form([]),
            },
        );

        $resource = new ExtensionFieldsProvider($provider, new MetadataLocaleResolver(new TokenStorage(), 'en'));
        $result = $resource->getExtensionFields();

        $this->assertSame([
            ['name' => 'title', 'type' => 'text_line', 'label' => 'title', 'required' => false],
            ['name' => 'seoNoIndex', 'type' => 'checkbox', 'label' => 'seoNoIndex', 'required' => true],
        ], $result['seo']);
        $this->assertSame([
            ['name' => 'image', 'type' => 'single_media_selection', 'label' => 'image', 'required' => false],
            ['name' => 'excerptCategories', 'type' => 'category_selection', 'label' => 'excerptCategories', 'required' => false],
        ], $result['excerpt']);
    }

    public function testFlattensSectionChildrenIntoSeoFields(): void
    {
        // The section itself is flattened away by Sulu's own
        // FormMetadata::getFlatFieldMetadata(); it never reaches this class.
        $ogTitleField = $this->createMock(FieldMetadata::class);
        $ogTitleField->method('getName')->willReturn('seo/ogTitle');
        $ogTitleField->method('getType')->willReturn('text_line');
        $ogTitleField->method('getLabel')->with('en')->willReturn('Og Title');
        $ogTitleField->method('isRequired')->willReturn(true);

        $seoForm = $this->createMock(FormMetadata::class);
        $seoForm->method('getFlatFieldMetadata')->willReturn(['seo/ogTitle' => $ogTitleField]);

        $provider = $this->createMock(MetadataProviderInterface::class);
        $provider->method('getMetadata')->willReturnCallback(
            fn (string $key) => 'content_seo_metadata' === $key ? $seoForm : $this->form([]),
        );

        $resource = new ExtensionFieldsProvider($provider, new MetadataLocaleResolver(new TokenStorage(), 'en'));
        $result = $resource->getExtensionFields();

        $this->assertSame([
            ['name' => 'ogTitle', 'type' => 'text_line', 'label' => 'Og Title', 'required' => true],
        ], $result['seo']);
    }

    /**
     * @param array<string,string> $fields name => type
     * @param array<string,bool> $required name => isRequired (defaults to false)
     */
    private function form(array $fields, array $required = []): FormMetadata
    {
        $items = [];
        foreach ($fields as $name => $type) {
            $field = $this->createMock(FieldMetadata::class);
            $field->method('getName')->willReturn($name);
            $field->method('getType')->willReturn($type);
            $strippedName = \str_contains($name, '/') ? \substr($name, (int) \strrpos($name, '/') + 1) : $name;
            $field->method('getLabel')->with('en')->willReturn($strippedName);
            $field->method('isRequired')->willReturn($required[$name] ?? false);
            $items[$name] = $field;
        }
        $form = $this->createMock(FormMetadata::class);
        $form->method('getFlatFieldMetadata')->willReturn($items);

        return $form;
    }
}
