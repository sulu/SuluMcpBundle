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

namespace Sulu\Mcp\Tests\Unit\Application\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Bundle\MediaBundle\Api\Media;
use Sulu\Bundle\MediaBundle\Media\FormatCache\FormatCacheInterface;
use Sulu\Bundle\MediaBundle\Media\ImageConverter\ImageConverterInterface;
use Sulu\Mcp\Application\Media\MediaFormatUris;

#[CoversClass(MediaFormatUris::class)]
final class MediaFormatUrisTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<FormatCacheInterface> */
    private ObjectProphecy $formatCache;

    /** @var ObjectProphecy<ImageConverterInterface> */
    private ObjectProphecy $imageConverter;

    private MediaFormatUris $formatUris;

    protected function setUp(): void
    {
        $this->formatCache = $this->prophesize(FormatCacheInterface::class);
        $this->imageConverter = $this->prophesize(ImageConverterInterface::class);
        $this->formatUris = new MediaFormatUris($this->formatCache->reveal(), $this->imageConverter->reveal());
    }

    public function testDescribesTheUriWithFormatAndExtensionPlaceholders(): void
    {
        $this->imageConverter->getSupportedOutputImageFormats('image/jpeg')->willReturn(['jpg', 'webp', 'avif']);
        $this->formatCache->getMediaUrl(7, 'hero.extension', '{format}', 2, 1)
            ->willReturn('/uploads/media/{format}/01/7-hero.extension?v=2-1');

        $result = $this->formatUris->describe($this->media(7, 'hero.jpeg', 'image/jpeg', 2, 1));

        $this->assertSame('/uploads/media/{format}/01/7-hero.{extension}?v=2-1', $result['formatUri']);
        $this->assertSame('jpg', $result['formatPreferredExtension']);
        $this->assertSame(['jpg', 'webp', 'avif'], $result['formatExtensions']);
    }

    public function testDescribesNothingForAFileThatIsNoImage(): void
    {
        $this->imageConverter->getSupportedOutputImageFormats('application/pdf')->willReturn([]);
        $this->formatCache->getMediaUrl(Argument::cetera())->shouldNotBeCalled();

        $this->assertSame([], $this->formatUris->describe($this->media(8, 'report.pdf', 'application/pdf', 1, 0)));
    }

    private function media(int $id, string $name, string $mimeType, int $version, int $subVersion): Media
    {
        $media = $this->prophesize(Media::class);
        $media->getId()->willReturn($id);
        $media->getName()->willReturn($name);
        $media->getMimeType()->willReturn($mimeType);
        $media->getVersion()->willReturn($version);
        $media->getSubVersion()->willReturn($subVersion);

        return $media->reveal();
    }
}
