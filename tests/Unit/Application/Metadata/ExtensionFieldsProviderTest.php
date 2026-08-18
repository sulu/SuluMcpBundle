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
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Mcp\Application\Metadata\ExtensionFieldsProvider;
use Sulu\Mcp\Application\Metadata\MetadataLocaleResolver;
use Sulu\Mcp\Tests\Unit\Fixture\ArrayMetadataProvider;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

#[CoversClass(ExtensionFieldsProvider::class)]
final class ExtensionFieldsProviderTest extends TestCase
{
    use ProphecyTrait;

    public function testReturnsSeoAndExcerptFieldsWithStrippedNames(): void
    {
        $provider = new ArrayMetadataProvider();
        $provider->set('content_seo_metadata', $this->form(['seo/title' => 'text_line', 'seoNoIndex' => 'checkbox'], ['seoNoIndex' => true]));
        $provider->set('content_excerpt_metadata', $this->form(['excerpt/image' => 'single_media_selection']));
        $provider->set('content_excerpt_taxonomies', $this->form(['excerptCategories' => 'category_selection']));
        $provider->setDefault($this->form([]));

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
        $ogTitleField = $this->prophesize(FieldMetadata::class);
        $ogTitleField->getName(Argument::cetera())->willReturn('seo/ogTitle');
        $ogTitleField->getType(Argument::cetera())->willReturn('text_line');
        $ogTitleField->getLabel('en')->willReturn('Og Title');
        $ogTitleField->isRequired(Argument::cetera())->willReturn(true);

        $seoForm = $this->prophesize(FormMetadata::class);
        $seoForm->getFlatFieldMetadata(Argument::cetera())->willReturn(['seo/ogTitle' => $ogTitleField->reveal()]);

        $provider = new ArrayMetadataProvider();
        $provider->set('content_seo_metadata', $seoForm->reveal());
        $provider->setDefault($this->form([]));

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
            $field = $this->prophesize(FieldMetadata::class);
            $field->getName(Argument::cetera())->willReturn($name);
            $field->getType(Argument::cetera())->willReturn($type);
            $strippedName = \str_contains($name, '/') ? \substr($name, (int) \strrpos($name, '/') + 1) : $name;
            $field->getLabel('en')->willReturn($strippedName);
            $field->isRequired(Argument::cetera())->willReturn($required[$name] ?? false);
            $items[$name] = $field->reveal();
        }
        $form = $this->prophesize(FormMetadata::class);
        $form->getFlatFieldMetadata(Argument::cetera())->willReturn($items);

        return $form->reveal();
    }
}
