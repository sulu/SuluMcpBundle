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

namespace Sulu\Mcp\Tests\Unit\Application\Metadata;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sulu\Mcp\Application\Metadata\FieldValueExampleProvider;

#[CoversClass(FieldValueExampleProvider::class)]
final class FieldValueExampleProviderTest extends TestCase
{
    private FieldValueExampleProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new FieldValueExampleProvider();
    }

    public function testTextEditorExampleIsHtmlAndExplainsSuluLink(): void
    {
        $info = $this->provider->describe('text_editor');

        $this->assertNotNull($info);
        $this->assertStringContainsString('<p>', (string) $info['example']);
        $this->assertStringContainsString('<sulu-link', (string) $info['example']);
        $this->assertNotNull($info['hint']);
        $this->assertStringContainsString('sulu-link', $info['hint']);
        $this->assertStringContainsString('provider', $info['hint']);
    }

    public function testTextLineIsRawString(): void
    {
        $info = $this->provider->describe('text_line');

        $this->assertNotNull($info);
        $this->assertIsString($info['example']);
        $this->assertNotSame('', $info['example']);
    }

    public function testTextAreaIsRawString(): void
    {
        $info = $this->provider->describe('text_area');

        $this->assertNotNull($info);
        $this->assertIsString($info['example']);
    }

    public function testNumberIsInteger(): void
    {
        $info = $this->provider->describe('number');

        $this->assertNotNull($info);
        $this->assertIsInt($info['example']);
    }

    public function testCheckboxIsBoolean(): void
    {
        $info = $this->provider->describe('checkbox');

        $this->assertNotNull($info);
        $this->assertIsBool($info['example']);
    }

    public function testEmailAndUrlExamplesLookValid(): void
    {
        $this->assertStringContainsString('@', (string) $this->provider->describe('email')['example']);
        $this->assertStringStartsWith('http', (string) $this->provider->describe('url')['example']);
    }

    public function testComplexSelectionTypesAreNotCoveredYet(): void
    {
        // Deliberately out of scope for the "keep it simple" scalar pass.
        $this->assertNull($this->provider->describe('media_selection'));
        $this->assertNull($this->provider->describe('smart_content'));
        $this->assertNull($this->provider->describe('single_select'));
    }

    public function testUnknownCustomTypeReturnsNull(): void
    {
        $this->assertNull($this->provider->describe('my_project_custom_type'));
    }
}
