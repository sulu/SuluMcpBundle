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
use Sulu\Mcp\Application\Content\BlockDataNormalizerTrait;
use Sulu\Mcp\Tests\Unit\Fixture\FixedBlockIdGenerator;

#[CoversClass(BlockDataNormalizerTrait::class)]
final class BlockDataNormalizerTraitTest extends TestCase
{
    use BlockDataNormalizerTrait;

    public function testAssignBlockIdsGeneratesIdWhenMissing(): void
    {
        $generator = new FixedBlockIdGenerator('gen-1');

        $result = $this->assignBlockIds(['type' => 'text', 'content' => 'hi'], $generator);

        self::assertSame('gen-1', $result['_id']);
    }

    public function testAssignBlockIdsPreservesExistingId(): void
    {
        $generator = new FixedBlockIdGenerator('id-1');

        $result = $this->assignBlockIds(['type' => 'text', '_id' => 'existing'], $generator);

        self::assertSame('existing', $result['_id']);
        self::assertSame(0, $generator->calls());
    }

    public function testAssignBlockIdsSkipsArraysWithoutStringTypeKey(): void
    {
        $generator = new FixedBlockIdGenerator();

        $result = $this->assignBlockIds(['title' => 'not a block'], $generator);

        self::assertArrayNotHasKey('_id', $result);
        self::assertSame(0, $generator->calls());
    }

    public function testAssignBlockIdsRecursesIntoNestedListValues(): void
    {
        $generator = new FixedBlockIdGenerator();
        $generator = FixedBlockIdGenerator::returning('id-section', 'id-text', 'id-image');

        $block = [
            'type' => 'section',
            'blocks' => [
                ['type' => 'text'],
                ['type' => 'image'],
            ],
        ];

        $result = $this->assignBlockIds($block, $generator);

        self::assertSame('id-section', $result['_id']);
        self::assertSame('id-text', $result['blocks'][0]['_id']);
        self::assertSame('id-image', $result['blocks'][1]['_id']);
    }

    public function testAssignBlockIdsLeavesNonListArrayValuesUntouched(): void
    {
        $generator = new FixedBlockIdGenerator('id-1');

        $result = $this->assignBlockIds(['type' => 'text', 'settings' => ['color' => 'red']], $generator);

        self::assertSame('id-1', $result['_id']);
        self::assertSame(['color' => 'red'], $result['settings']);
    }

    public function testNormalizeBlockDataExtractsSingleElementList(): void
    {
        $result = $this->normalizeBlockData([['content' => 'hi']]);

        self::assertSame(['content' => 'hi'], $result);
    }

    public function testNormalizeBlockDataPassesThroughFlatObject(): void
    {
        $result = $this->normalizeBlockData(['content' => 'hi']);

        self::assertSame(['content' => 'hi'], $result);
    }

    public function testNormalizeBlockOrderKeepsIntegers(): void
    {
        self::assertSame([0, 1, 2], $this->normalizeBlockOrder([0, 1, 2]));
    }

    public function testNormalizeBlockOrderCastsNumericStringsToIntegers(): void
    {
        self::assertSame([0, 1, 2], $this->normalizeBlockOrder(['0', '1', '2']));
    }

    public function testNormalizeBlockOrderReturnsNullForNonNumericValue(): void
    {
        self::assertNull($this->normalizeBlockOrder([0, 'abc', 2]));
    }

    public function testNormalizeBlockOrderReturnsNullForNonIndexString(): void
    {
        self::assertNull($this->normalizeBlockOrder(['1.5']));
    }

    public function testNormalizeContentPassesThroughFlatObject(): void
    {
        self::assertSame(['article' => 'x'], self::normalizeContent(['article' => 'x']));
    }

    public function testNormalizeContentExtractsListOfNameValuePairs(): void
    {
        $content = [
            ['name' => 'article', 'value' => 'x'],
            ['name' => 'title', 'value' => 'y'],
        ];

        self::assertSame(['article' => 'x', 'title' => 'y'], self::normalizeContent($content));
    }

    public function testNormalizeContentMergesListOfPlainDicts(): void
    {
        $content = [
            ['article' => 'x'],
            ['title' => 'y'],
        ];

        self::assertSame(['article' => 'x', 'title' => 'y'], self::normalizeContent($content));
    }

    public function testNormalizeContentReturnsEmptyArrayForEmptyInput(): void
    {
        self::assertSame([], self::normalizeContent([]));
    }

    public function testNormalizeContentSkipsNonArrayListItems(): void
    {
        self::assertSame([], self::normalizeContent([1, 2, 3]));
    }
}
