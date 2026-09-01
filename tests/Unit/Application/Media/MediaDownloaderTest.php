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
use Sulu\Mcp\Application\Media\DownloadedFile;
use Sulu\Mcp\Application\Media\MediaDownloader;
use Sulu\Mcp\Domain\Exception\MediaDownloadException;
use Sulu\Mcp\Domain\Exception\MediaSourceUnreachableException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[CoversClass(MediaDownloader::class)]
#[CoversClass(DownloadedFile::class)]
final class MediaDownloaderTest extends TestCase
{
    private const TEMP_PREFIX = 'sulu-mcp-media-';

    /** @var list<string> */
    private array $downloadedPaths = [];

    /** @var list<string> */
    private array $requestedUrls = [];

    protected function tearDown(): void
    {
        foreach ($this->downloadedPaths as $path) {
            @\unlink($path);
        }

        $this->downloadedPaths = [];
    }

    public function testDownloadsAnImageIntoATemporaryFile(): void
    {
        $file = $this->download($this->respondingWith(self::gif()), 'https://example.com/photo.gif');

        self::assertFileExists($file->path);
        self::assertSame(self::gif(), \file_get_contents($file->path));
        self::assertSame('photo.gif', $file->fileName);
        self::assertSame('image/gif', $file->mimeType);
        self::assertSame(\strlen(self::gif()), $file->size);
    }

    public function testTheMimeTypeComesFromTheBytesNotTheContentTypeHeader(): void
    {
        $client = $this->respondingWith(self::gif(), ['response_headers' => ['content-type' => 'image/jpeg']]);

        $file = $this->download($client, 'https://example.com/photo.gif');

        self::assertSame(
            'image/gif',
            $file->mimeType,
            'A remote that misdeclares its Content-Type must not be able to pick the mime type Sulu stores.',
        );
    }

    public function testAResponseThatIsNotAnImageIsRejected(): void
    {
        $this->expectException(MediaDownloadException::class);
        $this->expectExceptionMessage('is not an image');

        $this->download($this->respondingWith('<?php echo "hi";'), 'https://example.com/photo.jpg');
    }

    public function testTheFileNameIsDerivedFromTheMimeTypeWhenTheUrlHasNoExtension(): void
    {
        $file = $this->download($this->respondingWith(self::gif()), 'https://example.com/media/hero');

        self::assertSame('hero.gif', $file->fileName);
    }

    public function testAFileNameThatContradictsTheBytesIsCorrected(): void
    {
        $file = $this->download($this->respondingWith(self::gif()), 'https://example.com/photo.php');

        self::assertSame(
            'photo.gif',
            $file->fileName,
            'The extension has to follow what the bytes actually are, or an executable extension reaches the storage directory.',
        );
    }

    public function testAPercentEncodedFileNameIsDecodedForStorage(): void
    {
        $file = $this->download($this->respondingWith(self::gif()), 'https://example.com/Official%20Seal.gif');

        self::assertSame('Official Seal.gif', $file->fileName);
    }

    public function testAFileNameCannotEscapeIntoAnotherDirectory(): void
    {
        $file = $this->download($this->respondingWith(self::gif()), 'https://example.com/x/%2E%2E%2Fescaped.gif');

        self::assertSame('escaped.gif', $file->fileName);
    }

    #[DataProvider('provideUntrustedNames')]
    public function testNormalizeFileNameAppliesTheSameRuleToACallerSuppliedName(string $given, string $expected): void
    {
        $downloader = new MediaDownloader(new MockHttpClient(), 1048576);

        self::assertSame($expected, $downloader->normalizeFileName($given, 'image/gif'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideUntrustedNames(): iterable
    {
        yield 'kept as is' => ['harbour.gif', 'harbour.gif'];
        yield 'traversal' => ['../../evil.php', 'evil.gif'];
        yield 'windows separators' => ['..\\..\\evil.gif', 'evil.gif'];
        yield 'leading dots' => ['...hidden.gif', 'hidden.gif'];
        yield 'null byte' => ["evil.gif\0.php", 'evil.gif.gif'];
        yield 'no extension' => ['hero', 'hero.gif'];
        yield 'nothing usable' => ['../', 'image.gif'];
    }

    public function testAResponseLargerThanTheLimitIsRejected(): void
    {
        $this->expectException(MediaDownloadException::class);
        $this->expectExceptionMessage('larger than the configured limit');

        $this->download($this->respondingWith(\str_repeat('x', 128)), 'https://example.com/photo.gif', maxFileSize: 32);
    }

    public function testTheLimitIsAppliedWhileStreamingRatherThanAfterTheFullBody(): void
    {
        $client = $this->respondingWith(\str_repeat('x', 128), ['response_headers' => ['content-length' => '128']]);

        $this->expectException(MediaDownloadException::class);
        $this->expectExceptionMessage('larger than the configured limit');

        $this->download($client, 'https://example.com/photo.gif', maxFileSize: 32);
    }

    public function testAnErrorStatusIsReported(): void
    {
        $client = new MockHttpClient(new MockResponse('nope', ['http_code' => 404]));

        $this->expectException(MediaDownloadException::class);
        $this->expectExceptionMessage('returned HTTP 404');

        $this->download($client, 'https://example.com/photo.gif');
    }

    public function testAnEmptyResponseIsReported(): void
    {
        $this->expectException(MediaDownloadException::class);
        $this->expectExceptionMessage('was empty');

        $this->download($this->respondingWith(''), 'https://example.com/photo.gif');
    }

    public function testATransportFailureIsReportedAsADownloadFailure(): void
    {
        $client = new MockHttpClient(static function(): MockResponse {
            throw new TransportException('IP "127.0.0.1" is blocked for host "internal".');
        });

        $this->expectException(MediaDownloadException::class);
        $this->expectExceptionMessage('is blocked for host');

        $this->download($client, 'https://internal.example.com/photo.gif');
    }

    #[DataProvider('provideRefusedUrls')]
    public function testAUrlTheServerMustNotFetchIsRefusedBeforeAnyRequest(string $url, string $expectedMessage): void
    {
        $client = new MockHttpClient(static function(): MockResponse {
            self::fail('The downloader must not issue a request for a URL it should refuse outright.');
        });

        $this->expectException(MediaDownloadException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->download($client, $url);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideRefusedUrls(): iterable
    {
        yield 'file scheme' => ['file:///etc/passwd', 'Only http and https URLs'];
        yield 'ftp scheme' => ['ftp://example.com/photo.gif', 'Only http and https URLs'];
        yield 'no host' => ['https:photo.gif', 'names no host'];
        yield 'unparseable' => ['https:///photo.gif', 'Only http and https URLs'];
        yield 'loopback' => ['http://127.0.0.1/photo.gif', 'private or reserved address'];
        yield 'ipv6 loopback' => ['http://[::1]/photo.gif', 'private or reserved address'];
        yield 'private range' => ['http://10.0.0.5/photo.gif', 'private or reserved address'];
        yield 'link local' => ['http://169.254.169.254/latest/meta-data', 'private or reserved address'];
    }

    public function testAHostOutsideTheAllowListIsRefused(): void
    {
        $client = new MockHttpClient(static function(): MockResponse {
            self::fail('A host outside the allow list must never be contacted.');
        });

        $this->expectException(MediaDownloadException::class);
        $this->expectExceptionMessage('allowed_hosts');

        $this->download($client, 'https://elsewhere.example/photo.gif', allowedHosts: ['cdn.example.com']);
    }

    public function testARedirectAwayFromTheAllowListIsRefused(): void
    {
        $client = $this->respondingTo([
            'https://cdn.example.com/photo.gif' => new MockResponse('', [
                'http_code' => 302,
                'response_headers' => ['location' => 'https://elsewhere.example/photo.gif'],
            ]),
        ]);

        try {
            $this->download($client, 'https://cdn.example.com/photo.gif', allowedHosts: ['cdn.example.com']);
            self::fail('Expected the redirect target to be refused.');
        } catch (MediaDownloadException $e) {
            self::assertStringContainsString('allowed_hosts', $e->getMessage());
        }

        self::assertSame(
            ['https://cdn.example.com/photo.gif'],
            $this->requestedUrls,
            'An allow-listed host that redirects elsewhere must not get the bytes fetched from the new host anyway.',
        );
    }

    public function testARedirectIntoThePrivateNetworkIsRefused(): void
    {
        $client = $this->respondingTo([
            'https://cdn.example.com/photo.gif' => new MockResponse('', [
                'http_code' => 302,
                'response_headers' => ['location' => 'http://169.254.169.254/latest/meta-data'],
            ]),
        ]);

        $this->expectException(MediaDownloadException::class);
        $this->expectExceptionMessage('private or reserved address');

        $this->download($client, 'https://cdn.example.com/photo.gif');
    }

    public function testARedirectToAnAllowedHostIsFollowed(): void
    {
        $client = $this->respondingTo([
            'https://cdn.example.com/photo.gif' => new MockResponse('', [
                'http_code' => 301,
                'response_headers' => ['location' => 'https://cdn.example.com/real/photo.gif'],
            ]),
        ]);

        $file = $this->download($client, 'https://cdn.example.com/photo.gif', allowedHosts: ['cdn.example.com']);

        self::assertSame('image/gif', $file->mimeType);
        self::assertSame(
            ['https://cdn.example.com/photo.gif', 'https://cdn.example.com/real/photo.gif'],
            $this->requestedUrls,
        );
    }

    #[DataProvider('provideRelativeLocations')]
    public function testARelativeLocationIsResolvedAgainstTheUrlItCameFrom(string $location, string $expected): void
    {
        $client = $this->respondingTo([
            'https://cdn.example.com/a/b/photo.gif' => new MockResponse('', [
                'http_code' => 302,
                'response_headers' => ['location' => $location],
            ]),
        ]);

        $this->download($client, 'https://cdn.example.com/a/b/photo.gif');

        self::assertSame($expected, $this->requestedUrls[1] ?? null);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideRelativeLocations(): iterable
    {
        yield 'root relative' => ['/real.gif', 'https://cdn.example.com/real.gif'];
        yield 'document relative' => ['real.gif', 'https://cdn.example.com/a/b/real.gif'];
        yield 'protocol relative' => ['//other.example/real.gif', 'https://other.example/real.gif'];
        yield 'absolute' => ['https://other.example/real.gif', 'https://other.example/real.gif'];
    }

    public function testARedirectLoopIsAbandoned(): void
    {
        $client = new MockHttpClient(function(string $method, string $url): MockResponse {
            $this->requestedUrls[] = $url;

            return new MockResponse('', [
                'http_code' => 302,
                'response_headers' => ['location' => 'https://cdn.example.com/next'],
            ]);
        });

        $this->expectException(MediaSourceUnreachableException::class);
        $this->expectExceptionMessage('exceeded 3 redirects');

        $this->download($client, 'https://cdn.example.com/photo.gif');
    }

    public function testARedirectWithoutALocationIsReported(): void
    {
        $client = $this->respondingTo([
            'https://cdn.example.com/photo.gif' => new MockResponse('', ['http_code' => 302]),
        ]);

        $this->expectException(MediaSourceUnreachableException::class);
        $this->expectExceptionMessage('without a Location');

        $this->download($client, 'https://cdn.example.com/photo.gif');
    }

    public function testAnErrorStatusIsUnreachableButARejectedFileIsNot(): void
    {
        $notFound = new MockHttpClient(new MockResponse('nope', ['http_code' => 404]));
        $tooLarge = $this->respondingWith(\str_repeat('x', 128));

        try {
            $this->download($notFound, 'https://example.com/photo.gif');
            self::fail('Expected a failure.');
        } catch (MediaDownloadException $e) {
            self::assertInstanceOf(
                MediaSourceUnreachableException::class,
                $e,
                'An error status means the address does not serve the file, which is the only case worth retrying elsewhere.',
            );
        }

        try {
            $this->download($tooLarge, 'https://example.com/photo.gif', maxFileSize: 32);
            self::fail('Expected a failure.');
        } catch (MediaDownloadException $e) {
            self::assertNotInstanceOf(
                MediaSourceUnreachableException::class,
                $e,
                'The address served a file and we refused it; retrying elsewhere would work around the limit.',
            );
        }
    }

    public function testAHostOnTheAllowListIsFetched(): void
    {
        $file = $this->download(
            $this->respondingWith(self::gif()),
            'https://CDN.example.com/photo.gif',
            allowedHosts: ['cdn.example.com'],
        );

        self::assertSame('image/gif', $file->mimeType);
    }

    public function testAFailedDownloadLeavesNoTemporaryFileBehind(): void
    {
        $before = $this->tempFileCount();

        try {
            $this->download($this->respondingWith('<?php echo "hi";'), 'https://example.com/photo.jpg');
        } catch (MediaDownloadException) {
            // asserted below
        }

        self::assertSame(
            $before,
            $this->tempFileCount(),
            'A rejected download must not leak its temporary file, or a failing assistant fills the disk.',
        );
    }

    /**
     * @param list<string> $allowedHosts
     */
    private function download(
        HttpClientInterface $client,
        string $url,
        int $maxFileSize = 1048576,
        array $allowedHosts = [],
    ): DownloadedFile {
        $file = (new MediaDownloader($client, $maxFileSize, $allowedHosts))->download($url);
        $this->downloadedPaths[] = $file->path;

        return $file;
    }

    /**
     * @param array<string, mixed> $info
     */
    private function respondingWith(string $body, array $info = []): MockHttpClient
    {
        return new MockHttpClient(new MockResponse($body, $info));
    }

    /**
     * @param array<string, MockResponse> $responses keyed by URL; anything else gets the image
     */
    private function respondingTo(array $responses): MockHttpClient
    {
        return new MockHttpClient(function(string $method, string $url) use ($responses): MockResponse {
            $this->requestedUrls[] = $url;

            return $responses[$url] ?? new MockResponse(self::gif());
        });
    }

    private function tempFileCount(): int
    {
        return \count(\glob(\sys_get_temp_dir() . '/' . self::TEMP_PREFIX . '*') ?: []);
    }

    /**
     * The smallest valid GIF, so finfo has real bytes to identify.
     */
    private static function gif(): string
    {
        $gif = \base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true);
        \assert(\is_string($gif));

        return $gif;
    }
}
