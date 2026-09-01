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

namespace Sulu\Mcp\Tests\Unit\Application\Content;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TagMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Mcp\Application\Content\BlockDataValidator;
use Sulu\Mcp\Application\Metadata\MetadataLocaleResolver;
use Sulu\Mcp\Tests\Unit\Fixture\ArrayMetadataProvider;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

#[CoversClass(BlockDataValidator::class)]
final class BlockDataValidatorTest extends TestCase
{
    private ArrayMetadataProvider $formMetadataProvider;
    private BlockDataValidator $validator;

    protected function setUp(): void
    {
        // Template "default" with a "blocks" block field exposing:
        //   - text:    fields [title]
        //   - section: fields [title, blocks] where nested blocks expose text[title]
        $titleField = new FieldMetadata('title');
        $titleField->setType('text_line');

        $textBlock = new FormMetadata();
        $textBlock->setKey('text');
        $textBlock->addItem($titleField);

        $nestedBlocksField = new FieldMetadata('blocks');
        $nestedBlocksField->setType('block');
        $nestedBlocksField->addType($textBlock);

        $sectionBlock = new FormMetadata();
        $sectionBlock->setKey('section');
        $sectionBlock->addItem($titleField);
        $sectionBlock->addItem($nestedBlocksField);

        $blocksField = new FieldMetadata('blocks');
        $blocksField->setType('block');
        $blocksField->addType($textBlock);
        $blocksField->addType($sectionBlock);

        $template = new FormMetadata();
        $template->setKey('default');
        $template->addItem($blocksField);

        $typed = new TypedFormMetadata();
        $typed->addForm('default', $template);

        $this->formMetadataProvider = new ArrayMetadataProvider();
        $this->formMetadataProvider->set('page', $typed);

        $this->validator = new BlockDataValidator($this->formMetadataProvider, new MetadataLocaleResolver(new TokenStorage(), 'en'));
    }

    public function testValidContentTreeReturnsNull(): void
    {
        $content = [
            'blocks' => [
                ['type' => 'text', 'title' => 'Hello'],
                ['type' => 'section', 'title' => 'Sec', 'blocks' => [
                    ['type' => 'text', 'title' => 'Nested'],
                ]],
            ],
        ];

        $this->assertNull($this->validator->validateContentTree($content, 'page', 'default'));
    }

    public function testContentWithoutBlocksReturnsNull(): void
    {
        $content = ['article' => '<p>Just text, no blocks.</p>'];

        $this->assertNull($this->validator->validateContentTree($content, 'page', 'default'));
    }

    public function testRejectsUnknownKeyInTopLevelBlock(): void
    {
        $content = [
            'blocks' => [
                ['type' => 'text', 'bogus' => 'x'],
            ],
        ];

        $error = $this->validator->validateContentTree($content, 'page', 'default');

        $this->assertNotNull($error);
        $this->assertArrayHasKey('error', $error);
        $this->assertStringContainsString('bogus', $error['error']);
    }

    public function testRejectsUnknownKeyInNestedBlock(): void
    {
        $content = [
            'blocks' => [
                ['type' => 'section', 'title' => 'Sec', 'blocks' => [
                    ['type' => 'text', 'bogus' => 'x'],
                ]],
            ],
        ];

        $error = $this->validator->validateContentTree($content, 'page', 'default');

        $this->assertNotNull($error, 'unknown key inside a nested block must be rejected (recursive validation)');
        $this->assertStringContainsString('bogus', $error['error']);
    }

    public function testSkipsUndiscoverableBlockType(): void
    {
        $content = [
            'blocks' => [
                ['type' => 'project_specific_block', 'whatever' => 'x'],
            ],
        ];

        $this->assertNull($this->validator->validateContentTree($content, 'page', 'default'));
    }

    public function testRejectsBlockMissingRequiredField(): void
    {
        $validator = $this->validatorWithRequiredImageBlock();

        $content = ['blocks' => [['type' => 'image', 'caption' => 'no image set']]];

        $error = $validator->validateContentTree($content, 'page', 'default');

        $this->assertNotNull($error);
        $this->assertStringContainsString('image', $error['error']);
        $this->assertStringContainsString('required', \strtolower((string) $error['error']));
    }

    public function testAcceptsBlockWithRequiredFieldPresent(): void
    {
        $validator = $this->validatorWithRequiredImageBlock();

        $content = ['blocks' => [['type' => 'image', 'image' => ['ids' => [1]], 'caption' => 'ok']]];

        $this->assertNull($validator->validateContentTree($content, 'page', 'default'));
    }

    public function testRejectsNestedBlockMissingRequiredField(): void
    {
        $validator = $this->validatorWithRequiredImageBlock();

        $content = ['blocks' => [
            ['type' => 'section', 'blocks' => [
                ['type' => 'image', 'caption' => 'still no image'],
            ]],
        ]];

        $error = $validator->validateContentTree($content, 'page', 'default');

        $this->assertNotNull($error, 'a missing required field inside a nested block must be rejected');
        $this->assertStringContainsString('image', $error['error']);
    }

    public function testValidSnippetContentTreeReturnsNull(): void
    {
        $titleField = new FieldMetadata('title');
        $titleField->setType('text_line');

        $textBlock = new FormMetadata();
        $textBlock->setKey('text');
        $textBlock->addItem($titleField);

        $blocksField = new FieldMetadata('blocks');
        $blocksField->setType('block');
        $blocksField->addType($textBlock);

        $template = new FormMetadata();
        $template->setKey('default');
        $template->addItem($blocksField);

        $typed = new TypedFormMetadata();
        $typed->addForm('default', $template);

        $provider = new ArrayMetadataProvider();
        $provider->set('snippet', $typed);

        $validator = new BlockDataValidator($provider, new MetadataLocaleResolver(new TokenStorage(), 'en'));

        $content = [
            'blocks' => [
                ['type' => 'text', 'title' => 'Hello from snippet'],
            ],
        ];

        $this->assertNull($validator->validateContentTree($content, 'snippet', 'default'));
    }

    public function testSnippetContentTreeRejectsUnknownKey(): void
    {
        $titleField = new FieldMetadata('title');
        $titleField->setType('text_line');

        $textBlock = new FormMetadata();
        $textBlock->setKey('text');
        $textBlock->addItem($titleField);

        $blocksField = new FieldMetadata('blocks');
        $blocksField->setType('block');
        $blocksField->addType($textBlock);

        $template = new FormMetadata();
        $template->setKey('default');
        $template->addItem($blocksField);

        $typed = new TypedFormMetadata();
        $typed->addForm('default', $template);

        $provider = new ArrayMetadataProvider();
        $provider->set('snippet', $typed);

        $validator = new BlockDataValidator($provider, new MetadataLocaleResolver(new TokenStorage(), 'en'));

        $content = [
            'blocks' => [
                ['type' => 'text', 'bogus' => 'x'],
            ],
        ];

        $error = $validator->validateContentTree($content, 'snippet', 'default');

        $this->assertNotNull($error);
        $this->assertArrayHasKey('error', $error);
        $this->assertStringContainsString('bogus', $error['error']);
    }

    public function testSnippetContentTreeRejectsMissingRequiredField(): void
    {
        $bodyField = new FieldMetadata('body');
        $bodyField->setType('text_editor');
        $bodyField->setRequired(true);

        $textBlock = new FormMetadata();
        $textBlock->setKey('text');
        $textBlock->addItem($bodyField);

        $blocksField = new FieldMetadata('blocks');
        $blocksField->setType('block');
        $blocksField->addType($textBlock);

        $template = new FormMetadata();
        $template->setKey('default');
        $template->addItem($blocksField);

        $typed = new TypedFormMetadata();
        $typed->addForm('default', $template);

        $provider = new ArrayMetadataProvider();
        $provider->set('snippet', $typed);

        $validator = new BlockDataValidator($provider, new MetadataLocaleResolver(new TokenStorage(), 'en'));

        $content = [
            'blocks' => [
                ['type' => 'text'],
            ],
        ];

        $error = $validator->validateContentTree($content, 'snippet', 'default');

        $this->assertNotNull($error);
        $this->assertStringContainsString('body', $error['error']);
        $this->assertStringContainsString('required', \strtolower((string) $error['error']));
    }

    public function testFieldsInsideSectionAreAcceptedAsValidKeysAndListedInError(): void
    {
        $titleField = new FieldMetadata('title');
        $titleField->setType('text_line');
        $bodyField = new FieldMetadata('body');
        $bodyField->setType('text_editor');

        $section = new SectionMetadata('content');
        $section->addItem($titleField);
        $section->addItem($bodyField);

        $textBlock = new FormMetadata();
        $textBlock->setKey('text');
        $textBlock->addItem($section);

        $blocksField = new FieldMetadata('blocks');
        $blocksField->setType('block');
        $blocksField->addType($textBlock);

        $template = new FormMetadata();
        $template->setKey('default');
        $template->addItem($blocksField);

        $typed = new TypedFormMetadata();
        $typed->addForm('default', $template);

        $provider = new ArrayMetadataProvider();
        $provider->set('page', $typed);

        $validator = new BlockDataValidator($provider, new MetadataLocaleResolver(new TokenStorage(), 'en'));

        $validContent = ['blocks' => [['type' => 'text', 'title' => 'Hello', 'body' => '<p>Body</p>']]];
        $this->assertNull($validator->validateContentTree($validContent, 'page', 'default'));

        $invalidContent = ['blocks' => [['type' => 'text', 'title' => 'Hello', 'bogus' => 'x']]];
        $error = $validator->validateContentTree($invalidContent, 'page', 'default');

        $this->assertNotNull($error);
        $this->assertStringContainsString('bogus', $error['error']);
        $this->assertStringContainsString('title', $error['error']);
        $this->assertStringContainsString('body', $error['error']);
    }

    public function testRequiredFieldInsideSectionIsEnforcedByContentTreeValidation(): void
    {
        $imageField = new FieldMetadata('image');
        $imageField->setType('media_selection');
        $imageField->setRequired(true);
        $captionField = new FieldMetadata('caption');
        $captionField->setType('text_line');

        $section = new SectionMetadata('content');
        $section->addItem($imageField);
        $section->addItem($captionField);

        $imageBlock = new FormMetadata();
        $imageBlock->setKey('image');
        $imageBlock->addItem($section);

        $blocksField = new FieldMetadata('blocks');
        $blocksField->setType('block');
        $blocksField->addType($imageBlock);

        $template = new FormMetadata();
        $template->setKey('default');
        $template->addItem($blocksField);

        $typed = new TypedFormMetadata();
        $typed->addForm('default', $template);

        $provider = new ArrayMetadataProvider();
        $provider->set('page', $typed);

        $validator = new BlockDataValidator($provider, new MetadataLocaleResolver(new TokenStorage(), 'en'));

        $missingContent = ['blocks' => [['type' => 'image', 'caption' => 'no image set']]];
        $error = $validator->validateContentTree($missingContent, 'page', 'default');

        $this->assertNotNull($error);
        $this->assertStringContainsString('image', $error['error']);
        $this->assertStringContainsString('required', \strtolower((string) $error['error']));

        $presentContent = ['blocks' => [['type' => 'image', 'image' => ['ids' => [1]], 'caption' => 'ok']]];
        $this->assertNull($validator->validateContentTree($presentContent, 'page', 'default'));
    }

    public function testNestedSectionInsideSectionInBlockFormIsFlattened(): void
    {
        $teaserField = new FieldMetadata('teaser');
        $teaserField->setType('text_line');

        $innerSection = new SectionMetadata('inner');
        $innerSection->addItem($teaserField);

        $outerSection = new SectionMetadata('outer');
        $outerSection->addItem($innerSection);

        $textBlock = new FormMetadata();
        $textBlock->setKey('text');
        $textBlock->addItem($outerSection);

        $blocksField = new FieldMetadata('blocks');
        $blocksField->setType('block');
        $blocksField->addType($textBlock);

        $template = new FormMetadata();
        $template->setKey('default');
        $template->addItem($blocksField);

        $typed = new TypedFormMetadata();
        $typed->addForm('default', $template);

        $provider = new ArrayMetadataProvider();
        $provider->set('page', $typed);

        $validator = new BlockDataValidator($provider, new MetadataLocaleResolver(new TokenStorage(), 'en'));

        $content = ['blocks' => [['type' => 'text', 'teaser' => 'Hello']]];
        $this->assertNull($validator->validateContentTree($content, 'page', 'default'));

        $invalidContent = ['blocks' => [['type' => 'text', 'bogus' => 'x']]];
        $error = $validator->validateContentTree($invalidContent, 'page', 'default');

        $this->assertNotNull($error);
        $this->assertStringContainsString('bogus', $error['error']);
    }

    public function testBlockFieldInsideTemplateSectionIsFoundAndValidated(): void
    {
        $titleField = new FieldMetadata('title');
        $titleField->setType('text_line');

        $textBlock = new FormMetadata();
        $textBlock->setKey('text');
        $textBlock->addItem($titleField);

        $blocksField = new FieldMetadata('blocks');
        $blocksField->setType('block');
        $blocksField->addType($textBlock);

        $section = new SectionMetadata('content');
        $section->addItem($blocksField);

        $template = new FormMetadata();
        $template->setKey('default');
        $template->addItem($section);

        $typed = new TypedFormMetadata();
        $typed->addForm('default', $template);

        $provider = new ArrayMetadataProvider();
        $provider->set('page', $typed);

        $validator = new BlockDataValidator($provider, new MetadataLocaleResolver(new TokenStorage(), 'en'));

        $validContent = ['blocks' => [['type' => 'text', 'title' => 'Hello']]];
        $this->assertNull($validator->validateContentTree($validContent, 'page', 'default'));

        $invalidContent = ['blocks' => [['type' => 'text', 'bogus' => 'x']]];
        $error = $validator->validateContentTree($invalidContent, 'page', 'default');

        $this->assertNotNull($error, 'block field declared inside a template section must still be discovered and validated');
        $this->assertStringContainsString('bogus', $error['error']);
    }

    public function testNestedTypeOfTheSameNameIsResolvedAgainstItsOwnBlockProperty(): void
    {
        $validator = $this->validatorWithDuplicateItemTypes();

        $trustBar = ['content' => [[
            'type' => 'trust_bar',
            'items' => [['type' => 'item', 'value' => '15', 'label' => 'Jahre']],
        ]]];
        $this->assertNull($validator->validateContentTree($trustBar, 'page', 'default'));

        $featureCards = ['content' => [[
            'type' => 'feature_cards',
            'headline' => 'Cards',
            'items' => [['type' => 'item', 'eyebrow' => 'A', 'headline' => 'Card A', 'text' => '<p>…</p>']],
        ]]];
        $this->assertNull(
            $validator->validateContentTree($featureCards, 'page', 'default'),
            'a nested "item" must be validated against the schema of the block property it sits in, not against the first "item" declared anywhere in the template',
        );
    }

    public function testNestedTypeOfTheSameNameStillRejectsKeysOfTheForeignSchema(): void
    {
        $validator = $this->validatorWithDuplicateItemTypes();

        $content = ['content' => [[
            'type' => 'feature_cards',
            'headline' => 'Cards',
            'items' => [['type' => 'item', 'value' => '15', 'label' => 'Jahre']],
        ]]];

        $error = $validator->validateContentTree($content, 'page', 'default');

        $this->assertNotNull($error, 'keys of the "trust_bar" item schema are not valid for a "feature_cards" item');
        $this->assertStringContainsString('value', $error['error']);
        $this->assertStringContainsString('eyebrow', $error['error']);
    }

    public function testTypeNestedInsideGlobalBlockIsValidated(): void
    {
        $validator = $this->validatorWithGlobalTimelineBlock();

        $valid = ['content' => [[
            'type' => 'timeline_process',
            'stages' => [['type' => 'stage', 'title' => 'Kickoff']],
        ]]];
        $this->assertNull($validator->validateContentTree($valid, 'page', 'default'));

        $invalid = ['content' => [[
            'type' => 'timeline_process',
            'stages' => [['type' => 'stage', 'gibt_es_nicht' => 'test']],
        ]]];

        $error = $validator->validateContentTree($invalid, 'page', 'default');

        $this->assertNotNull($error, 'a type nested inside a global block must be discoverable, otherwise unknown keys are written through to Sulu');
        $this->assertStringContainsString('gibt_es_nicht', $error['error']);
    }

    public function testSingleBlockValidationFollowsTheBlockPath(): void
    {
        $validator = $this->validatorWithDuplicateItemTypes();

        $path = [
            ['property' => 'content', 'type' => 'feature_cards'],
            ['property' => 'items', 'type' => 'item'],
        ];

        $this->assertNull($validator->validate('page', 'default', 'item', $path, ['eyebrow' => 'A']));

        $error = $validator->validate('page', 'default', 'item', $path, ['value' => '15']);

        $this->assertNotNull($error);
        $this->assertStringContainsString('value', $error['error']);
    }

    public function testSingleBlockValidationOfTypeNestedInsideGlobalBlock(): void
    {
        $validator = $this->validatorWithGlobalTimelineBlock();

        $path = [
            ['property' => 'content', 'type' => 'timeline_process'],
            ['property' => 'stages', 'type' => 'stage'],
        ];

        $this->assertNull($validator->validate('page', 'default', 'stage', $path, ['title' => 'Kickoff']));

        $error = $validator->validate('page', 'default', 'stage', $path, ['gibt_es_nicht' => 'test']);

        $this->assertNotNull($error);
        $this->assertStringContainsString('gibt_es_nicht', $error['error']);
    }

    public function testUndescribableChainSkipsSchemaValidation(): void
    {
        $validator = $this->validatorWithDuplicateItemTypes();

        $this->assertNull(
            $validator->validate('page', 'default', 'item', [], ['whatever' => 'x']),
            'an empty chain means the caller could not describe where the block sits, not that the block has no schema',
        );
    }

    public function testUndescribableChainStillRejectsTheStorageShape(): void
    {
        $validator = $this->validatorWithDuplicateItemTypes();

        $error = $validator->validate('page', 'default', 'item', [], ['name' => 'title', 'value' => 'X']);

        $this->assertNotNull($error, 'the {name, value} guard needs no metadata and must not depend on the block being locatable');
        $this->assertStringContainsString('storage shape', $error['error']);
    }

    /**
     * Validator whose "default" template offers two block types that each nest a type
     * named "item" with a different field set, the shape the issue was reported for.
     */
    private function validatorWithDuplicateItemTypes(): BlockDataValidator
    {
        $trustBar = new FormMetadata();
        $trustBar->setKey('trust_bar');
        $trustBar->addItem($this->blockField('items', $this->blockType('item', ['value', 'label'])));

        $featureCards = new FormMetadata();
        $featureCards->setKey('feature_cards');
        $featureCards->addItem($this->field('headline'));
        $featureCards->addItem($this->blockField('items', $this->blockType('item', ['eyebrow', 'headline', 'text'])));

        $template = new FormMetadata();
        $template->setKey('default');
        $template->addItem($this->blockField('content', $trustBar, $featureCards));

        $typed = new TypedFormMetadata();
        $typed->addForm('default', $template);

        $provider = new ArrayMetadataProvider();
        $provider->set('page', $typed);

        return new BlockDataValidator($provider, new MetadataLocaleResolver(new TokenStorage(), 'en'));
    }

    /**
     * Validator whose "default" template references the global block "timeline_process",
     * whose fields, including the nested "stage" type, only exist in the separate
     * global block metadata.
     */
    private function validatorWithGlobalTimelineBlock(): BlockDataValidator
    {
        $globalBlockTag = new TagMetadata();
        $globalBlockTag->setName('sulu.global_block');
        $globalBlockTag->setAttributes(['global_block' => 'timeline_process']);

        $reference = new FormMetadata();
        $reference->setKey('timeline_process');
        $reference->addTag($globalBlockTag);

        $template = new FormMetadata();
        $template->setKey('default');
        $template->addItem($this->blockField('content', $reference));

        $typed = new TypedFormMetadata();
        $typed->addForm('default', $template);

        $globalBlock = new FormMetadata();
        $globalBlock->setKey('timeline_process');
        $globalBlock->addItem($this->blockField('stages', $this->blockType('stage', ['title'])));

        $globalBlocks = new TypedFormMetadata();
        $globalBlocks->addForm('timeline_process', $globalBlock);

        $provider = new ArrayMetadataProvider();
        $provider->set('page', $typed);
        $provider->set('block', $globalBlocks);

        return new BlockDataValidator($provider, new MetadataLocaleResolver(new TokenStorage(), 'en'));
    }

    private function field(string $name): FieldMetadata
    {
        $field = new FieldMetadata($name);
        $field->setType('text_line');

        return $field;
    }

    private function blockField(string $name, FormMetadata ...$types): FieldMetadata
    {
        $field = new FieldMetadata($name);
        $field->setType('block');
        foreach ($types as $type) {
            $field->addType($type);
        }

        return $field;
    }

    /**
     * @param list<string> $fieldNames
     */
    private function blockType(string $key, array $fieldNames): FormMetadata
    {
        $type = new FormMetadata();
        $type->setKey($key);
        foreach ($fieldNames as $fieldName) {
            $type->addItem($this->field($fieldName));
        }

        return $type;
    }

    /**
     * Validator whose "default" template exposes an "image" block with a REQUIRED
     * "image" field and an optional "caption", nested also inside a "section" block.
     */
    private function validatorWithRequiredImageBlock(): BlockDataValidator
    {
        $imageField = new FieldMetadata('image');
        $imageField->setType('media_selection');
        $imageField->setRequired(true);
        $captionField = new FieldMetadata('caption');
        $captionField->setType('text_line');

        $imageBlock = new FormMetadata();
        $imageBlock->setKey('image');
        $imageBlock->addItem($imageField);
        $imageBlock->addItem($captionField);

        $nestedBlocksField = new FieldMetadata('blocks');
        $nestedBlocksField->setType('block');
        $nestedBlocksField->addType($imageBlock);

        $sectionBlock = new FormMetadata();
        $sectionBlock->setKey('section');
        $sectionBlock->addItem($nestedBlocksField);

        $blocksField = new FieldMetadata('blocks');
        $blocksField->setType('block');
        $blocksField->addType($imageBlock);
        $blocksField->addType($sectionBlock);

        $template = new FormMetadata();
        $template->setKey('default');
        $template->addItem($blocksField);

        $typed = new TypedFormMetadata();
        $typed->addForm('default', $template);

        $provider = new ArrayMetadataProvider();
        $provider->set('page', $typed);

        return new BlockDataValidator($provider, new MetadataLocaleResolver(new TokenStorage(), 'en'));
    }
}
