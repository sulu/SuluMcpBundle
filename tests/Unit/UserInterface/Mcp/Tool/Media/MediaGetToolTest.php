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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\MediaBundle\Api\Media;
use Sulu\Bundle\MediaBundle\Entity\Collection;
use Sulu\Bundle\MediaBundle\Entity\CollectionType;
use Sulu\Bundle\MediaBundle\Entity\Media as MediaEntity;
use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;
use Sulu\Component\Media\SystemCollections\SystemCollectionManagerInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\UserInterface\Mcp\Tool\Media\MediaGetTool;

#[CoversClass(MediaGetTool::class)]
final class MediaGetToolTest extends TestCase
{
    private MediaManagerInterface&MockObject $mediaManager;
    private ToolPermissionCheckerInterface&MockObject $permissionChecker;
    private MediaGetTool $tool;

    protected function setUp(): void
    {
        $this->mediaManager = $this->createMock(MediaManagerInterface::class);
        $this->permissionChecker = $this->createMock(ToolPermissionCheckerInterface::class);
        $this->tool = new MediaGetTool($this->mediaManager, $this->permissionChecker);
    }

    /**
     * @param non-empty-string|null $typeKey
     */
    private function mediaWithCollection(int $collectionId, ?string $typeKey = null): Media&MockObject
    {
        $collectionType = $this->createMock(CollectionType::class);
        $collectionType->method('getKey')->willReturn($typeKey);

        $collection = $this->createMock(Collection::class);
        $collection->method('getId')->willReturn($collectionId);
        $collection->method('getType')->willReturn($collectionType);

        $mediaEntity = $this->createMock(MediaEntity::class);
        $mediaEntity->method('getCollection')->willReturn($collection);

        $media = $this->createMock(Media::class);
        $media->method('getEntity')->willReturn($mediaEntity);

        return $media;
    }

    public function testGetMediaReturnsFullDetails(): void
    {
        $media = $this->mediaWithCollection(5);
        $media->method('getId')->willReturn(42);
        $media->method('getTitle')->willReturn('Hero Image');
        $media->method('getDescription')->willReturn('A beautiful hero image');
        $media->method('getCopyright')->willReturn('(c) 2026 Example');
        $media->method('getMimeType')->willReturn('image/png');
        $media->method('getSize')->willReturn(54321);
        $media->method('getUrl')->willReturn('/media/42/hero.png');
        $media->method('getFormats')->willReturn([
            'sulu-100x100' => '/media/42/hero.png?v=1-0&inline=1',
            'sulu-400x400' => '/media/42/hero.png?v=1-0',
        ]);

        $this->mediaManager->method('getById')->willReturn($media);

        $result = $this->tool->getMedia(42, 'en');

        $this->assertSame(42, $result['id']);
        $this->assertSame('Hero Image', $result['title']);
        $this->assertSame('A beautiful hero image', $result['description']);
        $this->assertSame('(c) 2026 Example', $result['copyright']);
        $this->assertSame('image/png', $result['mimeType']);
        $this->assertSame(54321, $result['size']);
        $this->assertSame('/media/42/hero.png', $result['url']);
        $this->assertCount(2, $result['formats']);
    }

    public function testGetMediaReturnsErrorForMissingMedia(): void
    {
        $this->mediaManager->method('getById')->willThrowException(new \RuntimeException('Not found'));

        $result = $this->tool->getMedia(999, 'en');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('999', $result['error']);
        $this->assertTrue(\array_key_exists('hint', $result));
        $this->assertIsString($result['hint']);
        $this->assertNotEmpty($result['hint']);
    }

    public function testGetMediaChecksCollectionPermission(): void
    {
        $media = $this->mediaWithCollection(7);
        $this->mediaManager->method('getById')->willReturn($media);

        $this->permissionChecker
            ->expects($this->once())
            ->method('check')
            ->with('sulu.media.collections', PermissionTypes::VIEW, 'en', Collection::class, 7);

        $this->tool->getMedia(42, 'en');
    }

    public function testGetMediaAlsoChecksSystemCollectionPermission(): void
    {
        $media = $this->mediaWithCollection(1, SystemCollectionManagerInterface::COLLECTION_TYPE);
        $this->mediaManager->method('getById')->willReturn($media);

        $calls = [];
        $this->permissionChecker
            ->expects($this->exactly(2))
            ->method('check')
            ->willReturnCallback(function (string $context, string $permission) use (&$calls): void {
                $calls[] = [$context, $permission];
            });

        $this->tool->getMedia(42, 'en');

        $this->assertSame(
            [
                ['sulu.media.system_collections', PermissionTypes::VIEW],
                ['sulu.media.collections', PermissionTypes::VIEW],
            ],
            $calls,
        );
    }

    public function testGetMediaThrowsToolCallExceptionWhenPermissionDenied(): void
    {
        $media = $this->mediaWithCollection(7);
        $this->mediaManager->method('getById')->willReturn($media);

        $this->permissionChecker
            ->method('check')
            ->willThrowException(new PermissionDeniedException('sulu.media.collections', PermissionTypes::VIEW, 'en'));

        $this->expectException(ToolCallException::class);

        $this->tool->getMedia(42, 'en');
    }

    public function testGetMediaMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(MediaGetTool::class, 'getMedia');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes, 'getMedia() method must have exactly one #[McpTool] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_media_get', $instance->name);
    }
}
