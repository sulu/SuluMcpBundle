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
use Sulu\Mcp\Application\Content\ContentMetadataMapper;
use Sulu\Mcp\Tests\Unit\Fixture\ArrayMetadataProvider;

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
        $mapper = new ContentMetadataMapper($this->provider());

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
        $mapper = new ContentMetadataMapper($provider);

        $data = $mapper->applySeo([], ['ogTitle' => 'Hello'], 'en');

        $this->assertSame(['ogTitle' => 'Hello'], $data['seo']);
    }

    public function testApplySeoRejectsUnknownField(): void
    {
        $mapper = new ContentMetadataMapper($this->provider());

        $data = $mapper->applySeo([], ['bogusField' => 'x'], 'en');

        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('bogusField', $data['error']);
    }

    public function testApplyExcerptNestsMediaAndLiftsTaxonomies(): void
    {
        $mapper = new ContentMetadataMapper($this->provider());

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
        $mapper = new ContentMetadataMapper($this->provider());
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

        $mapper = new ContentMetadataMapper($provider);

        $data = $mapper->applySeo([], ['ogTitle' => 'Hello'], 'en');

        $this->assertArrayNotHasKey('error', $data);
        $this->assertSame(['ogTitle' => 'Hello'], $data['seo']);
    }

    public function testApplySeoPlacesTopLevelColumnWhenMetadataUnavailable(): void
    {
        $provider = new ArrayMetadataProvider();
        $mapper = new ContentMetadataMapper($provider);

        $data = $mapper->applySeo([], ['title' => 'T', 'seoNoIndex' => true], 'en');

        $this->assertArrayNotHasKey('error', $data);
        $this->assertSame(['title' => 'T'], $data['seo']);
        $this->assertTrue($data['seoNoIndex']);
    }
}
