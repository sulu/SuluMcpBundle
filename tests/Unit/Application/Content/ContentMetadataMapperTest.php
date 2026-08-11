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
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Mcp\Application\Content\ContentMetadataMapper;

#[CoversClass(ContentMetadataMapper::class)]
final class ContentMetadataMapperTest extends TestCase
{
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

        $provider = $this->createMock(MetadataProviderInterface::class);
        $provider->method('getMetadata')->willReturnCallback(
            fn (string $key) => match ($key) {
                'content_seo_metadata' => $seo,
                'content_excerpt_metadata' => $excerpt,
                'content_excerpt_taxonomies' => $tax,
                default => $this->form([]),
            },
        );

        return $provider;
    }

    /** @param array<string,string> $fields name=>type */
    private function form(array $fields): FormMetadata
    {
        $items = [];
        foreach ($fields as $name => $type) {
            $field = $this->createMock(FieldMetadata::class);
            $field->method('getName')->willReturn($name);
            $field->method('getType')->willReturn($type);
            $items[$name] = $field;
        }
        $form = $this->createMock(FormMetadata::class);
        $form->method('getItems')->willReturn($items);

        return $form;
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
        $provider = $this->createMock(MetadataProviderInterface::class);
        $provider->method('getMetadata')->willReturnCallback(
            fn (string $key) => 'content_seo_metadata' === $key
                ? $this->form(['seo/title' => 'text_line', 'seo/ogTitle' => 'text_line'])
                : $this->form([]),
        );
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

    public function testApplySeoPlacesTopLevelColumnWhenMetadataUnavailable(): void
    {
        $provider = $this->createMock(MetadataProviderInterface::class);
        $provider->method('getMetadata')->willThrowException(new \RuntimeException('no metadata'));
        $mapper = new ContentMetadataMapper($provider);

        $data = $mapper->applySeo([], ['title' => 'T', 'seoNoIndex' => true], 'en');

        $this->assertArrayNotHasKey('error', $data);
        $this->assertSame(['title' => 'T'], $data['seo']);
        $this->assertTrue($data['seoNoIndex']);
    }
}
