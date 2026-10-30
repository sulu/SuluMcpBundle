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
use Sulu\Mcp\Application\Content\ContentLocales;

#[CoversClass(ContentLocales::class)]
final class ContentLocalesTest extends TestCase
{
    public function testALocaleOfTheSiteIsAccepted(): void
    {
        self::assertNull((new ContentLocales(['en', 'de']))->refusal('de'));
    }

    public function testAnUnknownLocaleIsRefusedWithTheAllowedOnes(): void
    {
        $refusal = (new ContentLocales(['en', 'de']))->refusal('xx');

        self::assertSame('"xx" is not a locale of this site.', $refusal['error'] ?? null);
        self::assertSame('Use one of: en, de.', $refusal['hint'] ?? null);
    }

    public function testTheCaseMustMatch(): void
    {
        self::assertNotNull((new ContentLocales(['en']))->refusal('EN'));
    }

    public function testALongLocaleIsNotEchoedInFull(): void
    {
        $refusal = (new ContentLocales(['en']))->refusal(\str_repeat('x', 5000));

        self::assertLessThan(100, \strlen($refusal['error'] ?? ''));
    }
}
