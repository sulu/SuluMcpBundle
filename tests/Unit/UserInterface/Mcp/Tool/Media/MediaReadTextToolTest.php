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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\Media;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Bundle\MediaBundle\Api\Media;
use Sulu\Bundle\MediaBundle\Entity\Collection;
use Sulu\Bundle\MediaBundle\Entity\CollectionType;
use Sulu\Bundle\MediaBundle\Entity\Media as MediaEntity;
use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;
use Sulu\Bundle\MediaBundle\Media\Storage\StorageInterface;
use Sulu\Component\Media\SystemCollections\SystemCollectionManagerInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Tests\Unit\Fixture\FakeToolPermissionChecker;
use Sulu\Mcp\UserInterface\Mcp\Tool\Media\MediaReadTextTool;

#[CoversClass(MediaReadTextTool::class)]
final class MediaReadTextToolTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<MediaManagerInterface> */
    private ObjectProphecy $mediaManager;

    /** @var ObjectProphecy<StorageInterface> */
    private ObjectProphecy $storage;

    private FakeToolPermissionChecker $permissionChecker;
    private MediaReadTextTool $tool;

    protected function setUp(): void
    {
        $this->mediaManager = $this->prophesize(MediaManagerInterface::class);
        $this->storage = $this->prophesize(StorageInterface::class);
        $this->permissionChecker = FakeToolPermissionChecker::grantingAll();
        $this->tool = new MediaReadTextTool(
            $this->mediaManager->reveal(),
            $this->storage->reveal(),
            $this->permissionChecker,
            1,
        );
    }

    /**
     * @param non-empty-string|null $typeKey
     */
    private function givenMedia(string $mimeType, string $content, int $collectionId = 5, ?string $typeKey = null, string $extension = ''): void
    {
        $collectionType = new CollectionType();
        $collectionType->setKey($typeKey);

        /** @var ObjectProphecy<Collection> $collection */
        $collection = $this->prophesize(Collection::class);
        $collection->getId()->willReturn($collectionId);
        $collection->getType()->willReturn($collectionType);

        $mediaEntity = new MediaEntity();
        $mediaEntity->setCollection($collection->reveal());

        /** @var ObjectProphecy<Media> $media */
        $media = $this->prophesize(Media::class);
        $media->getEntity()->willReturn($mediaEntity);
        $media->getTitle()->willReturn('Datasheet');
        $media->getMimeType()->willReturn($mimeType);
        $media->getExtension()->willReturn($extension);
        $media->getSize()->willReturn(\strlen($content));
        $media->getStorageOptions()->willReturn(['fileName' => 'file']);

        $stream = \fopen('php://memory', 'r+');
        \assert(false !== $stream);
        \fwrite($stream, $content);
        \rewind($stream);

        $this->storage->load(Argument::any())->willReturn($stream);
        $this->mediaManager->getById(Argument::cetera())->willReturn($media->reveal());
    }

    private function pdf(string $text): string
    {
        $stream = \sprintf('BT /F1 12 Tf 10 100 Td (%s) Tj ET', $text);
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            \sprintf("<< /Length %d >>\nstream\n%s\nendstream", \strlen($stream), $stream),
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = \strlen($pdf);
            $pdf .= \sprintf("%d 0 obj\n%s\nendobj\n", $index + 1, $object);
        }
        $xref = \strlen($pdf);
        $pdf .= \sprintf("xref\n0 %d\n0000000000 65535 f \n", \count($objects) + 1);
        foreach ($offsets as $offset) {
            $pdf .= \sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf . \sprintf("trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF", \count($objects) + 1, $xref);
    }

    public function testReadsPlainText(): void
    {
        $this->givenMedia('text/plain', 'Hello world');

        $result = $this->tool->readText(42, 'en');

        $this->assertSame('Hello world', $result['text']);
        $this->assertSame('text/plain', $result['mimeType']);
        $this->assertSame(11, $result['totalLength']);
        $this->assertFalse($result['truncated']);
        $this->assertNull($result['nextOffset']);
    }

    public function testReadsJsonAndCsvByMimeType(): void
    {
        $this->givenMedia('application/json', '{"a":1}');
        $this->assertSame('{"a":1}', $this->tool->readText(1)['text']);

        $this->givenMedia('text/csv', "a,b\n1,2");
        $this->assertSame("a,b\n1,2", $this->tool->readText(1)['text']);
    }

    public function testReadsMarkdownLabelledAsOctetStream(): void
    {
        $this->givenMedia('application/octet-stream', '# Title', 5, null, 'md');

        $this->assertSame('# Title', $this->tool->readText(1)['text']);
    }

    public function testPagesLongTextWithOffsetAndMaxChars(): void
    {
        $this->givenMedia('text/plain', 'abcdefghij');

        $first = $this->tool->readText(1, 'en', 0, 4);

        $this->assertSame('abcd', $first['text']);
        $this->assertTrue($first['truncated']);
        $this->assertSame(4, $first['nextOffset']);
        $this->assertSame(10, $first['totalLength']);

        $this->givenMedia('text/plain', 'abcdefghij');
        $last = $this->tool->readText(1, 'en', 8, 4);

        $this->assertSame('ij', $last['text']);
        $this->assertFalse($last['truncated']);
        $this->assertNull($last['nextOffset']);
    }

    public function testCountsMultiByteCharactersAcrossChunkBoundaries(): void
    {
        $content = \str_repeat('ä', 70000);
        $this->givenMedia('text/plain', $content);

        $result = $this->tool->readText(1, 'en', 65535, 3);

        $this->assertSame('äää', $result['text']);
        $this->assertSame(70000, $result['totalLength']);
    }

    public function testReadsPdfText(): void
    {
        $this->givenMedia('application/pdf', $this->pdf('Watering every week'));

        $result = $this->tool->readText(1, 'en');

        $this->assertSame('Watering every week', $result['text']);
        $this->assertSame('application/pdf', $result['mimeType']);
        $this->assertSame(19, $result['totalLength']);
    }

    public function testPagesPdfText(): void
    {
        $this->givenMedia('application/pdf', $this->pdf('Watering every week'));

        $result = $this->tool->readText(1, 'en', 9, 5);

        $this->assertSame('every', $result['text']);
        $this->assertTrue($result['truncated']);
        $this->assertSame(14, $result['nextOffset']);
    }

    public function testBrokenPdfReturnsErrorWithHint(): void
    {
        $this->givenMedia('application/pdf', 'not a pdf');

        $result = $this->tool->readText(1, 'en');

        $this->assertArrayHasKey('error', $result);
        $this->assertNotEmpty($result['hint']);
    }

    public function testImageReturnsErrorWithHint(): void
    {
        $this->givenMedia('image/png', 'x');

        $result = $this->tool->readText(1, 'en');

        $this->assertStringContainsString('image/png', $result['error']);
        $this->assertStringContainsString('sulu_media_get', $result['hint']);
    }

    public function testEmptyTextReturnsError(): void
    {
        $this->givenMedia('text/plain', '');

        $this->assertArrayHasKey('error', $this->tool->readText(1, 'en'));
    }

    public function testRefusesFileAboveSizeLimit(): void
    {
        $this->givenMedia('text/plain', \str_repeat('a', 1024 * 1024 + 1));

        $result = $this->tool->readText(1, 'en');

        $this->assertStringContainsString('too large', $result['error']);
        $this->storage->load(Argument::any())->shouldNotHaveBeenCalled();
    }

    public function testReturnsErrorForMissingMedia(): void
    {
        $this->mediaManager->getById(Argument::cetera())->willThrow(new \RuntimeException('Not found'));

        $result = $this->tool->readText(999, 'en');

        $this->assertStringContainsString('999', $result['error']);
        $this->assertStringContainsString('sulu_media_list', $result['hint']);
    }

    public function testChecksCollectionPermission(): void
    {
        $this->givenMedia('text/plain', 'x', 7);

        $this->tool->readText(42, 'en');

        self::assertSame([[
            'context' => 'sulu.media.collections',
            'permissions' => [PermissionTypes::VIEW],
            'locale' => 'en',
            'objectType' => Collection::class,
            'objectId' => 7,
        ]], $this->permissionChecker->calls());
    }

    public function testAlsoChecksSystemCollectionPermission(): void
    {
        $this->givenMedia('text/plain', 'x', 1, SystemCollectionManagerInterface::COLLECTION_TYPE);

        $this->tool->readText(42, 'en');

        $this->assertSame(
            [
                ['sulu.media.system_collections', PermissionTypes::VIEW],
                ['sulu.media.collections', PermissionTypes::VIEW],
            ],
            $this->permissionChecker->checkedPairs(),
        );
    }

    public function testThrowsToolCallExceptionAndReadsNothingWhenPermissionDenied(): void
    {
        $this->givenMedia('text/plain', 'secret');
        $this->permissionChecker->denyAll();

        try {
            $this->tool->readText(42, 'en');
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException) {
            $this->storage->load(Argument::any())->shouldNotHaveBeenCalled();
        }
    }

    public function testMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(MediaReadTextTool::class, 'readText');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes);
        $this->assertSame('sulu_media_read_text', $attributes[0]->newInstance()->name);
    }
}
