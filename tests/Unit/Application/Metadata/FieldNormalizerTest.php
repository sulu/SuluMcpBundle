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
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\ItemMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TagMetadata;
use Sulu\Mcp\Application\Metadata\FieldNormalizer;

#[CoversClass(FieldNormalizer::class)]
final class FieldNormalizerTest extends TestCase
{
    private FieldNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new FieldNormalizer();
    }

    /**
     * @param list<ItemMetadata> $items
     */
    private function form(array $items): FormMetadata
    {
        $form = new FormMetadata();
        foreach ($items as $item) {
            $form->addItem($item);
        }

        return $form;
    }

    public function testNormalizeFormIncludesNameTypeLabelRequired(): void
    {
        $field = new FieldMetadata('title');
        $field->setType('text_line');
        $field->setLabel('Title', 'en');
        $field->setRequired(true);

        $result = $this->normalizer->normalizeForm($this->form([$field]), 'en');

        $this->assertSame([
            ['name' => 'title', 'type' => 'text_line', 'label' => 'Title', 'required' => true],
        ], $result);
    }

    public function testNormalizeFormLabelFallsBackToNameWhenNoLabel(): void
    {
        $field = new FieldMetadata('title');
        $field->setType('text_line');

        $result = $this->normalizer->normalizeForm($this->form([$field]), 'en');

        $this->assertSame('title', $result[0]['label']);
    }

    public function testNormalizeFormRequiredIsFalseWhenNotRequired(): void
    {
        $field = new FieldMetadata('title');
        $field->setType('text_line');

        $result = $this->normalizer->normalizeForm($this->form([$field]), 'en');

        $this->assertFalse($result[0]['required']);
    }

    public function testNormalizeFormHoistsSectionFieldsFlatWithoutSectionEntry(): void
    {
        $title = new FieldMetadata('title');
        $title->setType('text_line');
        $subtitle = new FieldMetadata('subtitle');
        $subtitle->setType('text_line');

        $section = new SectionMetadata('content');
        $section->addItem($title);
        $section->addItem($subtitle);

        $result = $this->normalizer->normalizeForm($this->form([$section]), 'en');

        $this->assertSame(['title', 'subtitle'], \array_column($result, 'name'));
        $this->assertNotContains('section', \array_column($result, 'type'));
    }

    public function testNormalizeFormFlattensNestedSectionsRecursively(): void
    {
        $field = new FieldMetadata('teaser');
        $field->setType('text_line');

        $innerSection = new SectionMetadata('inner');
        $innerSection->addItem($field);

        $outerSection = new SectionMetadata('outer');
        $outerSection->addItem($innerSection);

        $result = $this->normalizer->normalizeForm($this->form([$outerSection]), 'en');

        $this->assertSame([
            ['name' => 'teaser', 'type' => 'text_line', 'label' => 'teaser', 'required' => false],
        ], $result);
    }

    public function testNormalizeFormBlockFieldListsUntaggedLocalType(): void
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

        $result = $this->normalizer->normalizeForm($this->form([$block]), 'en');

        $this->assertSame([
            'default' => [
                'key' => 'default',
                'label' => 'Default',
                'fields' => [
                    ['name' => 'headline', 'type' => 'text_line', 'label' => 'headline', 'required' => false],
                ],
            ],
        ], $result[0]['types']);
    }

    public function testNormalizeFormBlockTypeFlattensSectionInsideItsForm(): void
    {
        $itemField = new FieldMetadata('headline');
        $itemField->setType('text_line');

        $section = new SectionMetadata('content');
        $section->addItem($itemField);

        $typeForm = new FormMetadata();
        $typeForm->setKey('default');
        $typeForm->setTitle('Default', 'en');
        $typeForm->addItem($section);

        $block = new FieldMetadata('blocks');
        $block->setType('block');
        $block->addType($typeForm);

        $result = $this->normalizer->normalizeForm($this->form([$block]), 'en');

        $this->assertSame([
            ['name' => 'headline', 'type' => 'text_line', 'label' => 'headline', 'required' => false],
        ], $result[0]['types']['default']['fields']);
    }

    public function testNormalizeFormGlobalBlockTypeOmitsFieldsAndDoesNotRecurse(): void
    {
        $itemField = new FieldMetadata('headline');
        $itemField->setType('text_line');

        $tag = new TagMetadata();
        $tag->setName('sulu.global_block');
        $tag->setAttributes(['global_block' => 'target_name']);

        $typeForm = new FormMetadata();
        $typeForm->setKey('shared');
        $typeForm->setTitle('Shared', 'en');
        $typeForm->addItem($itemField);
        $typeForm->addTag($tag);

        $block = new FieldMetadata('blocks');
        $block->setType('block');
        $block->addType($typeForm);

        $result = $this->normalizer->normalizeForm($this->form([$block]), 'en');

        $this->assertSame([
            'shared' => [
                'key' => 'shared',
                'label' => 'Shared',
                'globalBlock' => 'target_name',
            ],
        ], $result[0]['types']);
        $this->assertArrayNotHasKey('fields', $result[0]['types']['shared']);
    }
}
