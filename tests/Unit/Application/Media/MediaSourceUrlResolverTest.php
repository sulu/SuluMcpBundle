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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sulu\Mcp\Application\Media\MediaSource;
use Sulu\Mcp\Application\Media\MediaSourceUrlResolver;

#[CoversClass(MediaSourceUrlResolver::class)]
#[CoversClass(MediaSource::class)]
final class MediaSourceUrlResolverTest extends TestCase
{
    private const LOCAL_SERVER = 'https://sulu.example.com';

    public function testRemoteFormatUrlIsRewrittenToTheDownloadUrl(): void
    {
        $source = $this->resolver()->resolve('https://sulu.io/uploads/media/800x/00/230-photo.jpg?v=2-6');

        self::assertSame(MediaSource::KIND_FORMAT_URL, $source->kind);
        self::assertSame(
            'https://sulu.io/media/230/download/photo.jpg',
            $source->url,
            'A format URL names a resized derivative; downloading it instead of the original would lose the source resolution for good.',
        );
    }

    public function testTheEncodedFileNameSurvivesTheRewriteVerbatim(): void
    {
        $source = $this->resolver()->resolve('https://sulu.io/uploads/media/800x/00/230-Official%20Bundle%20Seal.svg?v=2-6');

        self::assertSame(
            'https://sulu.io/media/230/download/Official%20Bundle%20Seal.svg',
            $source->url,
            'The download route compares the decoded slug against the stored file name, so the encoding must be carried over untouched.',
        );
    }

    public function testARewrittenFormatUrlKeepsTheGivenUrlAsItsFallback(): void
    {
        $given = 'https://sulu.io/uploads/media/800x/00/230-photo.jpg?v=2-6';
        $source = $this->resolver()->resolve($given);

        self::assertSame($given, $source->fallbackUrl);
        self::assertTrue(
            $source->hasFallback(),
            'The route patterns come from this instance, so applying them to a remote host is a guess that has to be retryable.',
        );
    }

    public function testANonSuluUrlIsDownloadedAsGiven(): void
    {
        $source = $this->resolver()->resolve('https://example.com/images/photo.jpg');

        self::assertSame(MediaSource::KIND_DIRECT, $source->kind);
        self::assertSame('https://example.com/images/photo.jpg', $source->url);
        self::assertFalse($source->hasFallback());
    }

    public function testARemoteDownloadUrlIsAlreadyTheOriginalAndIsLeftAlone(): void
    {
        $source = $this->resolver()->resolve('https://sulu.io/media/230/download/photo.jpg?v=2');

        self::assertSame(MediaSource::KIND_DIRECT, $source->kind);
        self::assertSame('https://sulu.io/media/230/download/photo.jpg?v=2', $source->url);
    }

    #[DataProvider('provideLocalUrls')]
    public function testAUrlPointingAtThisInstanceResolvesToTheMediaItAlreadyOwns(string $url): void
    {
        $source = $this->resolver()->resolve($url);

        self::assertSame(MediaSource::KIND_LOCAL_MEDIA, $source->kind);
        self::assertSame(230, $source->localMediaId);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideLocalUrls(): iterable
    {
        yield 'format url' => ['https://sulu.example.com/uploads/media/800x/00/230-photo.jpg?v=2-6'];
        yield 'download url' => ['https://sulu.example.com/media/230/download/photo.jpg?v=2'];
        yield 'relative url as sulu_media_get returns it' => ['/media/230/download/photo.jpg?v=2'];
        yield 'relative format url' => ['/uploads/media/800x/00/230-photo.jpg'];
        yield 'host in a different case' => ['https://SULU.example.com/media/230/download/photo.jpg'];
    }

    public function testARelativeUrlThatNamesNoMediaStaysDirect(): void
    {
        $source = $this->resolver()->resolve('/images/photo.jpg');

        self::assertSame(MediaSource::KIND_DIRECT, $source->kind);
    }

    public function testAProtocolRelativeUrlWithAForeignHostIsNotLocal(): void
    {
        $source = $this->resolver()->resolve('//remote.example/media/230/download/photo.jpg');

        self::assertSame(
            MediaSource::KIND_DIRECT,
            $source->kind,
            'It carries a host, so treating it as relative would hand back this instance\'s media 230 instead of the remote image.',
        );
        self::assertNull($source->localMediaId);
    }

    public function testAProtocolRelativeUrlOnThisHostIsStillLocal(): void
    {
        $source = $this->resolver()->resolve('//sulu.example.com/media/230/download/photo.jpg');

        self::assertSame(MediaSource::KIND_LOCAL_MEDIA, $source->kind);
        self::assertSame(230, $source->localMediaId);
    }

    #[DataProvider('provideDefaultPortUrls')]
    public function testAPortTheSchemeImpliesDoesNotMakeADifferentInstance(string $url): void
    {
        $source = $this->resolver()->resolve($url);

        self::assertSame(
            MediaSource::KIND_LOCAL_MEDIA,
            $source->kind,
            'https://host:443 and https://host are the same origin, so spelling the port out must not import a duplicate.',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideDefaultPortUrls(): iterable
    {
        yield 'explicit 443' => ['https://sulu.example.com:443/media/230/download/photo.jpg'];
        yield 'explicit 443 on a format url' => ['https://sulu.example.com:443/uploads/media/800x/00/230-photo.jpg'];
    }

    public function testADifferentPortIsADifferentInstance(): void
    {
        $source = $this->resolver()->resolve('https://sulu.example.com:8443/media/230/download/photo.jpg');

        self::assertSame(
            MediaSource::KIND_DIRECT,
            $source->kind,
            'A media id only means something on the instance that issued it, so the port has to be part of the identity.',
        );
    }

    public function testTheConfiguredRoutePathsDriveTheRewrite(): void
    {
        $resolver = new MediaSourceUrlResolver(
            self::LOCAL_SERVER,
            '/assets/img/{slug}',
            '/files/{id}/original/{slug}',
        );

        $source = $resolver->resolve('https://sulu.io/assets/img/800x/00/230-photo.jpg');

        self::assertSame('https://sulu.io/files/230/original/photo.jpg', $source->url);
    }

    #[DataProvider('provideUnparseableUrls')]
    public function testInputThatIsNotAUsableUrlIsHandedOnUnchanged(string $url): void
    {
        $source = $this->resolver()->resolve($url);

        self::assertSame(
            MediaSource::KIND_DIRECT,
            $source->kind,
            'Rejecting a URL is the downloader\'s job; the resolver only decides what to point it at.',
        );
        self::assertSame($url, $source->url);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnparseableUrls(): iterable
    {
        yield 'no scheme or path' => ['not a url'];
        yield 'scheme only' => ['https://'];
        yield 'non-http scheme' => ['file:///etc/passwd'];
    }

    public function testAnEmptyUrlIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->resolver()->resolve('');
    }

    private function resolver(): MediaSourceUrlResolver
    {
        return new MediaSourceUrlResolver(self::LOCAL_SERVER);
    }
}
