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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Mcp\Application\Content\BlockDataValidator;

#[CoversClass(BlockDataValidator::class)]
final class BlockDataValidatorTest extends TestCase
{
    private MetadataProviderInterface&MockObject $formMetadataProvider;
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

        $this->formMetadataProvider = $this->createMock(MetadataProviderInterface::class);
        $this->formMetadataProvider->method('getMetadata')
            ->willReturnCallback(fn (string $key) => 'page' === $key ? $typed : null);

        $this->validator = new BlockDataValidator($this->formMetadataProvider);
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

        $provider = $this->createMock(MetadataProviderInterface::class);
        $provider->method('getMetadata')
            ->willReturnCallback(fn (string $key) => 'snippet' === $key ? $typed : null);

        $validator = new BlockDataValidator($provider);

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

        $provider = $this->createMock(MetadataProviderInterface::class);
        $provider->method('getMetadata')
            ->willReturnCallback(fn (string $key) => 'snippet' === $key ? $typed : null);

        $validator = new BlockDataValidator($provider);

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

        $provider = $this->createMock(MetadataProviderInterface::class);
        $provider->method('getMetadata')
            ->willReturnCallback(fn (string $key) => 'snippet' === $key ? $typed : null);

        $validator = new BlockDataValidator($provider);

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

        $provider = $this->createMock(MetadataProviderInterface::class);
        $provider->method('getMetadata')
            ->willReturnCallback(fn (string $key) => 'page' === $key ? $typed : null);

        $validator = new BlockDataValidator($provider);

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

        $provider = $this->createMock(MetadataProviderInterface::class);
        $provider->method('getMetadata')
            ->willReturnCallback(fn (string $key) => 'page' === $key ? $typed : null);

        $validator = new BlockDataValidator($provider);

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

        $provider = $this->createMock(MetadataProviderInterface::class);
        $provider->method('getMetadata')
            ->willReturnCallback(fn (string $key) => 'page' === $key ? $typed : null);

        $validator = new BlockDataValidator($provider);

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

        $provider = $this->createMock(MetadataProviderInterface::class);
        $provider->method('getMetadata')
            ->willReturnCallback(fn (string $key) => 'page' === $key ? $typed : null);

        $validator = new BlockDataValidator($provider);

        $validContent = ['blocks' => [['type' => 'text', 'title' => 'Hello']]];
        $this->assertNull($validator->validateContentTree($validContent, 'page', 'default'));

        $invalidContent = ['blocks' => [['type' => 'text', 'bogus' => 'x']]];
        $error = $validator->validateContentTree($invalidContent, 'page', 'default');

        $this->assertNotNull($error, 'block field declared inside a template section must still be discovered and validated');
        $this->assertStringContainsString('bogus', $error['error']);
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

        $provider = $this->createMock(MetadataProviderInterface::class);
        $provider->method('getMetadata')
            ->willReturnCallback(fn (string $key) => 'page' === $key ? $typed : null);

        return new BlockDataValidator($provider);
    }
}
