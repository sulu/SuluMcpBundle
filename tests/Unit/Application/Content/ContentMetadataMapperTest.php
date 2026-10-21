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
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Content\Domain\Model\ExcerptInterface;
use Sulu\Content\Domain\Model\SeoInterface;
use Sulu\Content\Domain\Model\TaxonomyInterface;
use Sulu\Mcp\Application\Content\ContentMetadataMapper;
use Sulu\Mcp\Application\Metadata\ExtensionFormKeys;
use Sulu\Mcp\Tests\Unit\Fixture\ArrayMetadataProvider;
use Sulu\Mcp\Tests\Unit\Fixture\CoreExtensionFormKeys;

#[CoversClass(ContentMetadataMapper::class)]
final class ContentMetadataMapperTest extends TestCase
{
    use ProphecyTrait;

    private function provider(): MetadataProviderInterface
    {
        $seo = $this->form([
            'seo/title' => 'text_line',
            'seo/description' => 'text_area',
            'seo/keywords' => 'text_line',
            'seo/canonicalUrl' => 'text_line',
            'seoNoIndex' => 'checkbox',
            'seoNoFollow' => 'checkbox',
            'seoHideInSitemap' => 'checkbox',
        ]);
        $excerpt = $this->form([
            'excerpt/title' => 'text_line',
            'excerpt/more' => 'text_line',
            'excerpt/description' => 'text_editor',
            'excerpt/icon' => 'single_media_selection',
            'excerpt/image' => 'single_media_selection',
        ]);
        $tax = $this->form([
            'excerptCategories' => 'category_selection',
            'excerptTags' => 'tag_selection',
        ]);

        $provider = new ArrayMetadataProvider();
        $provider->set('content_seo_metadata', $seo);
        $provider->set('content_excerpt_metadata', $excerpt);
        $provider->set('content_excerpt_taxonomies', $tax);
        $provider->setDefault($this->form([]));

        return $provider;
    }

    /** @param array<string,string> $fields name=>type */
    private function form(array $fields): FormMetadata
    {
        $items = [];
        foreach ($fields as $name => $type) {
            $field = $this->prophesize(FieldMetadata::class);
            $field->getName(Argument::cetera())->willReturn($name);
            $field->getType(Argument::cetera())->willReturn($type);
            $items[$name] = $field->reveal();
        }
        $form = $this->prophesize(FormMetadata::class);
        $form->getFlatFieldMetadata(Argument::cetera())->willReturn($items);

        return $form->reveal();
    }

    public function testApplySeoNestsAndLiftsKnownFields(): void
    {
        $mapper = new ContentMetadataMapper($this->provider(), CoreExtensionFormKeys::create());

        $data = $mapper->applySeo([], ['title' => 'T', 'canonicalUrl' => 'https://x', 'seoNoIndex' => true], 'en');

        $this->assertSame(['title' => 'T', 'canonicalUrl' => 'https://x'], $data['seo']);
        $this->assertTrue($data['seoNoIndex']);
    }

    public function testApplySeoPassesCustomFieldThrough(): void
    {
        // Project added `seo/ogTitle` to its SEO form — must flow through with no code change.
        $provider = new ArrayMetadataProvider();
        $provider->set('content_seo_metadata', $this->form(['seo/title' => 'text_line', 'seo/ogTitle' => 'text_line']));
        $provider->setDefault($this->form([]));
        $mapper = new ContentMetadataMapper($provider, CoreExtensionFormKeys::create());

        $data = $mapper->applySeo([], ['ogTitle' => 'Hello'], 'en');

        $this->assertSame(['ogTitle' => 'Hello'], $data['seo']);
    }

    public function testApplySeoRejectsUnknownField(): void
    {
        $mapper = new ContentMetadataMapper($this->provider(), CoreExtensionFormKeys::create());

        $data = $mapper->applySeo([], ['bogusField' => 'x'], 'en');

        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('bogusField', $data['error']);
    }

    public function testApplyExcerptNestsMediaAndLiftsTaxonomies(): void
    {
        $mapper = new ContentMetadataMapper($this->provider(), CoreExtensionFormKeys::create());

        $data = $mapper->applyExcerpt([], [
            'title' => 'Teaser',
            'image' => ['id' => 5],
            'excerptCategories' => [1, 2],
        ], 'en');

        $this->assertSame(['title' => 'Teaser', 'image' => ['id' => 5]], $data['excerpt']);
        $this->assertSame([1, 2], $data['excerptCategories']);
    }

    public function testNullInputsAreNoOps(): void
    {
        $mapper = new ContentMetadataMapper($this->provider(), CoreExtensionFormKeys::create());
        $this->assertSame(['x' => 1], $mapper->applySeo(['x' => 1], null, 'en'));
        $this->assertSame(['x' => 1], $mapper->applyExcerpt(['x' => 1], null, 'en'));
    }

    public function testApplySeoAcceptsFieldNestedInsideSection(): void
    {
        // The section itself is flattened away by Sulu's own
        // FormMetadata::getFlatFieldMetadata(); it never reaches this class.
        $ogTitleField = $this->prophesize(FieldMetadata::class);
        $ogTitleField->getName(Argument::cetera())->willReturn('seo/ogTitle');
        $ogTitleField->getType(Argument::cetera())->willReturn('text_line');

        $seoForm = $this->prophesize(FormMetadata::class);
        $seoForm->getFlatFieldMetadata(Argument::cetera())->willReturn(['seo/ogTitle' => $ogTitleField->reveal()]);

        $provider = new ArrayMetadataProvider();
        $provider->set('content_seo_metadata', $seoForm->reveal());
        $provider->setDefault($this->form([]));

        $mapper = new ContentMetadataMapper($provider, CoreExtensionFormKeys::create());

        $data = $mapper->applySeo([], ['ogTitle' => 'Hello'], 'en');

        $this->assertArrayNotHasKey('error', $data);
        $this->assertSame(['ogTitle' => 'Hello'], $data['seo']);
    }

    public function testApplySeoPlacesTopLevelColumnWhenMetadataUnavailable(): void
    {
        $provider = new ArrayMetadataProvider();
        $mapper = new ContentMetadataMapper($provider, CoreExtensionFormKeys::create());

        $data = $mapper->applySeo([], ['title' => 'T', 'seoNoIndex' => true], 'en');

        $this->assertArrayNotHasKey('error', $data);
        $this->assertSame(['title' => 'T'], $data['seo']);
        $this->assertTrue($data['seoNoIndex']);
    }

    public function testApplyExcerptAcceptsFieldsOfProjectExcerptForm(): void
    {
        // A project form tagged `sulu_content.content_excerpt_form` adds `excerpt/media`.
        $provider = $this->providerWithProjectExcerptForms();
        $mapper = new ContentMetadataMapper($provider, $this->formKeys());

        $data = $mapper->applyExcerpt([], ['media' => ['ids' => [7]], 'excerptTags' => ['a']], 'en');

        $this->assertArrayNotHasKey('error', $data);
        $this->assertSame(['media' => ['ids' => [7]]], $data['excerpt']);
        $this->assertSame(['a'], $data['excerptTags']);
    }

    public function testApplyExcerptStillRejectsUnknownFieldWithProjectExcerptForm(): void
    {
        $provider = $this->providerWithProjectExcerptForms();
        $mapper = new ContentMetadataMapper($provider, $this->formKeys());

        $data = $mapper->applyExcerpt([], ['bogusField' => 'x'], 'en');

        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('bogusField', $data['error']);
        $this->assertStringContainsString('media', $data['hint']);
        $this->assertSame(1, \substr_count($data['hint'], 'excerptCategories'));
    }

    public function testApplyExcerptIgnoresFormsOutsideTheRegistry(): void
    {
        // Only the forms registered as excerpt forms are read.
        $provider = $this->providerWithProjectExcerptForms();
        $mapper = new ContentMetadataMapper($provider, CoreExtensionFormKeys::create());

        $data = $mapper->applyExcerpt([], ['media' => ['ids' => [7]]], 'en');

        $this->assertArrayHasKey('error', $data);
    }

    public function testApplyExcerptRejectsFieldsStrippedOnRead(): void
    {
        // `excerptSegment` / `excerptAudienceTargetGroups` are registered by Sulu itself, but stripped
        // from read responses, so they are not offered for write either.
        $provider = $this->providerWithProjectExcerptForms();
        $mapper = new ContentMetadataMapper($provider, $this->formKeys());

        $data = $mapper->applyExcerpt([], ['excerptSegment' => 'x'], 'en');

        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('excerptSegment', $data['error']);
        $this->assertStringNotContainsString('excerptSegment', $data['hint']);
        $this->assertStringNotContainsString('excerptAudienceTargetGroups', $data['hint']);
    }

    private function providerWithProjectExcerptForms(): ArrayMetadataProvider
    {
        $provider = $this->provider();
        \assert($provider instanceof ArrayMetadataProvider);
        $provider->set('app_excerpt', $this->form(['excerpt/media' => 'media_selection']));
        $provider->set('app_excerpt_taxonomies', $this->form(['excerptCategories' => 'category_selection']));
        $provider->set('page_excerpt_segments', $this->form(['excerptSegment' => 'segment_select']));
        $provider->set('audience_targeting_excerpt', $this->form(['excerptAudienceTargetGroups' => 'target_group_selection']));

        return $provider;
    }

    private function formKeys(): ExtensionFormKeys
    {
        return new ExtensionFormKeys(
            [
                'content_excerpt_metadata' => ['instanceOf' => ExcerptInterface::class, 'priority' => 100],
                'app_excerpt' => ['instanceOf' => ExcerptInterface::class, 'priority' => 90],
                'content_excerpt_taxonomies' => ['instanceOf' => TaxonomyInterface::class, 'priority' => 50],
                // A project form repeating the taxonomy fields.
                'app_excerpt_taxonomies' => ['instanceOf' => TaxonomyInterface::class, 'priority' => 40],
                'page_excerpt_segments' => ['instanceOf' => TaxonomyInterface::class, 'priority' => 25],
                'audience_targeting_excerpt' => ['instanceOf' => TaxonomyInterface::class, 'priority' => 10],
            ],
            ['content_seo_metadata' => ['instanceOf' => SeoInterface::class, 'priority' => 100]],
        );
    }
}
