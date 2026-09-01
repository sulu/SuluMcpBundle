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
use Sulu\Mcp\Application\Media\MediaFileNamer;

#[CoversClass(MediaFileNamer::class)]
final class MediaFileNamerTest extends TestCase
{
    #[DataProvider('provideUrls')]
    public function testTheNameIsTakenFromTheUrlPath(string $url, string $expected): void
    {
        self::assertSame($expected, (new MediaFileNamer())->fromUrl($url, 'image/gif'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideUrls(): iterable
    {
        yield 'plain' => ['https://example.com/photo.gif', 'photo.gif'];
        yield 'query ignored' => ['https://example.com/photo.gif?v=2-6', 'photo.gif'];
        yield 'percent encoded' => ['https://example.com/Official%20Seal.gif', 'Official Seal.gif'];
        yield 'encoded traversal' => ['https://example.com/x/%2E%2E%2Fescaped.gif', 'escaped.gif'];
        yield 'no extension' => ['https://example.com/media/hero', 'hero.gif'];
        yield 'no path' => ['https://example.com', 'image.gif'];
    }

    #[DataProvider('provideUntrustedNames')]
    public function testACallerSuppliedNameGetsTheSameTreatment(string $given, string $expected): void
    {
        self::assertSame(
            $expected,
            (new MediaFileNamer())->normalize($given, 'image/gif'),
            'The override is model-supplied too, so it cannot be held to a weaker rule than the URL.',
        );
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

    public function testAnExtensionThatContradictsTheBytesIsReplaced(): void
    {
        self::assertSame(
            'photo.gif',
            (new MediaFileNamer())->normalize('photo.php', 'image/gif'),
            'The extension has to follow what the bytes actually are, or an executable extension reaches the storage directory.',
        );
    }

    public function testAnAlternativeExtensionForTheSameTypeIsKept(): void
    {
        self::assertSame(
            'photo.jpg',
            (new MediaFileNamer())->normalize('photo.jpg', 'image/jpeg'),
            'jpg and jpeg both name the type, so renaming one to the other would churn file names for nothing.',
        );
    }

    public function testAnUnknownMimeTypeLeavesTheNameAlone(): void
    {
        self::assertSame('photo.weird', (new MediaFileNamer())->normalize('photo.weird', 'application/x-not-a-real-type'));
    }
}
