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
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\MediaBundle\Api\Media;
use Sulu\Bundle\MediaBundle\Entity\Collection;
use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;
use Sulu\Component\Media\SystemCollections\SystemCollectionManagerInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\UserInterface\Mcp\Tool\Media\MediaListTool;

#[CoversClass(MediaListTool::class)]
final class MediaListToolTest extends TestCase
{
    private MediaManagerInterface&MockObject $mediaManager;
    private ToolPermissionCheckerInterface&MockObject $permissionChecker;
    private SystemCollectionManagerInterface&MockObject $systemCollectionManager;
    private MediaListTool $tool;

    protected function setUp(): void
    {
        $this->mediaManager = $this->createMock(MediaManagerInterface::class);
        $this->permissionChecker = $this->createMock(ToolPermissionCheckerInterface::class);
        $this->systemCollectionManager = $this->createMock(SystemCollectionManagerInterface::class);
        $this->tool = new MediaListTool($this->mediaManager, $this->permissionChecker, $this->systemCollectionManager);
    }

    public function testListMediaReturnsFormattedResults(): void
    {
        $media1 = $this->createMock(Media::class);
        $media1->method('getId')->willReturn(1);
        $media1->method('getTitle')->willReturn('Photo 1');
        $media1->method('getMimeType')->willReturn('image/jpeg');
        $media1->method('getSize')->willReturn(12345);
        $media1->method('getUrl')->willReturn('/media/1/photo1.jpg');
        $media1->method('getCollection')->willReturn(3);

        $media2 = $this->createMock(Media::class);
        $media2->method('getId')->willReturn(2);
        $media2->method('getTitle')->willReturn('Document');
        $media2->method('getMimeType')->willReturn('application/pdf');
        $media2->method('getSize')->willReturn(67890);
        $media2->method('getUrl')->willReturn('/media/2/document.pdf');
        $media2->method('getCollection')->willReturn(3);

        $this->mediaManager->method('get')->willReturn([$media1, $media2]);
        $this->mediaManager->method('getCount')->willReturn(10);
        $this->permissionChecker->method('has')->willReturn(true);

        $result = $this->tool->listMedia('en');

        $this->assertCount(2, $result['media']);
        $this->assertSame(10, $result['total']);
        $this->assertSame(20, $result['limit']);
        $this->assertSame(1, $result['page']);
        $this->assertSame(1, $result['media'][0]['id']);
        $this->assertSame('Photo 1', $result['media'][0]['title']);
        $this->assertSame('image/jpeg', $result['media'][0]['mimeType']);
    }

    public function testListMediaPassesFilters(): void
    {
        $this->mediaManager
            ->expects($this->once())
            ->method('get')
            ->with(
                'de',
                $this->callback(fn (array $filter): bool => 5 === $filter['collection']
                    && 'test' === $filter['search']
                    && ['image'] === $filter['types']),
                10,
                20,
            )
            ->willReturn([]);
        $this->mediaManager->method('getCount')->willReturn(0);

        $this->tool->listMedia('de', 5, 'test', ['image'], 3, 10);
    }

    /**
     * Without system_collections VIEW, the exclusion filter is pushed into the query,
     * so excluded media stay out of `total` too, not just the results list.
     */
    public function testListMediaExcludesSystemCollectionsInTheQueryWhenNotPermitted(): void
    {
        $this->mediaManager
            ->expects($this->once())
            ->method('get')
            ->with(
                'en',
                ['systemCollections' => false],
                20,
                0,
            )
            ->willReturn([]);
        $this->mediaManager->method('getCount')->willReturn(0);

        $this->tool->listMedia('en');

        $this->addToAssertionCount(1);
    }

    public function testListMediaDoesNotExcludeSystemCollectionsWhenPermitted(): void
    {
        $permissionChecker = $this->createMock(ToolPermissionCheckerInterface::class);
        $permissionChecker->method('has')->willReturn(true);

        $mediaManager = $this->createMock(MediaManagerInterface::class);
        $mediaManager
            ->expects($this->once())
            ->method('get')
            ->with('en', [], 20, 0)
            ->willReturn([]);
        $mediaManager->method('getCount')->willReturn(0);

        $tool = new MediaListTool($mediaManager, $permissionChecker, $this->systemCollectionManager);

        $tool->listMedia('en');

        $this->addToAssertionCount(1);
    }

    public function testListMediaWithCollectionIdChecksObjectPermission(): void
    {
        $this->mediaManager->method('get')->willReturn([]);
        $this->mediaManager->method('getCount')->willReturn(0);

        $this->permissionChecker
            ->expects($this->once())
            ->method('check')
            ->with('sulu.media.collections', PermissionTypes::VIEW, 'en', Collection::class, 5);

        $this->tool->listMedia('en', 5);
    }

    public function testListMediaWithoutCollectionIdSkipsObjectPermissionCheck(): void
    {
        $this->mediaManager->method('get')->willReturn([]);
        $this->mediaManager->method('getCount')->willReturn(0);

        $this->permissionChecker->expects($this->never())->method('check');

        $this->tool->listMedia('en');
    }

    public function testListMediaWithoutCollectionIdFiltersRowsByCollectionPermission(): void
    {
        $mediaInAllowedCollection = $this->createMock(Media::class);
        $mediaInAllowedCollection->method('getId')->willReturn(1);
        $mediaInAllowedCollection->method('getTitle')->willReturn('Allowed');
        $mediaInAllowedCollection->method('getMimeType')->willReturn('image/jpeg');
        $mediaInAllowedCollection->method('getSize')->willReturn(111);
        $mediaInAllowedCollection->method('getUrl')->willReturn('/media/1/allowed.jpg');
        $mediaInAllowedCollection->method('getCollection')->willReturn(3);

        $mediaInDeniedCollection = $this->createMock(Media::class);
        $mediaInDeniedCollection->method('getId')->willReturn(2);
        $mediaInDeniedCollection->method('getTitle')->willReturn('Denied');
        $mediaInDeniedCollection->method('getMimeType')->willReturn('application/pdf');
        $mediaInDeniedCollection->method('getSize')->willReturn(222);
        $mediaInDeniedCollection->method('getUrl')->willReturn('/media/2/denied.pdf');
        $mediaInDeniedCollection->method('getCollection')->willReturn(7);

        $this->mediaManager->method('get')->willReturn([$mediaInAllowedCollection, $mediaInDeniedCollection]);
        $this->mediaManager->method('getCount')->willReturn(2);

        // Caller has system_collections VIEW here, so this test isolates the
        // per-collection EDIT filtering from the system-collection gate below.
        $this->permissionChecker
            ->method('has')
            ->willReturnCallback(static function (string $context, string $permission, ?string $locale = null, ?string $objectType = null, mixed $objectId = null): bool {
                if ('sulu.media.system_collections' === $context) {
                    return true;
                }

                return 3 === $objectId;
            });

        $result = $this->tool->listMedia('en');

        $this->assertCount(1, $result['media']);
        $this->assertSame(1, $result['media'][0]['id']);
        $this->assertSame('Allowed', $result['media'][0]['title']);
    }

    public function testListMediaWithoutCollectionIdExcludesSystemCollectionMediaWhenSystemViewDenied(): void
    {
        $mediaInNormalCollection = $this->createMock(Media::class);
        $mediaInNormalCollection->method('getId')->willReturn(1);
        $mediaInNormalCollection->method('getTitle')->willReturn('Normal');
        $mediaInNormalCollection->method('getMimeType')->willReturn('image/jpeg');
        $mediaInNormalCollection->method('getSize')->willReturn(111);
        $mediaInNormalCollection->method('getUrl')->willReturn('/media/1/normal.jpg');
        $mediaInNormalCollection->method('getCollection')->willReturn(3);

        $mediaInSystemCollection = $this->createMock(Media::class);
        $mediaInSystemCollection->method('getId')->willReturn(2);
        $mediaInSystemCollection->method('getTitle')->willReturn('System');
        $mediaInSystemCollection->method('getMimeType')->willReturn('image/png');
        $mediaInSystemCollection->method('getSize')->willReturn(222);
        $mediaInSystemCollection->method('getUrl')->willReturn('/media/2/system.png');
        $mediaInSystemCollection->method('getCollection')->willReturn(99);

        $this->mediaManager->method('get')->willReturn([$mediaInNormalCollection, $mediaInSystemCollection]);
        $this->mediaManager->method('getCount')->willReturn(2);

        // Caller has collection EDIT on both collections, but NOT system_collections VIEW.
        $this->permissionChecker
            ->method('has')
            ->willReturnCallback(static fn (string $context): bool => 'sulu.media.system_collections' !== $context);

        $this->systemCollectionManager
            ->method('isSystemCollection')
            ->willReturnCallback(static fn (int $id): bool => 99 === $id);

        $result = $this->tool->listMedia('en');

        $this->assertCount(1, $result['media']);
        $this->assertSame(1, $result['media'][0]['id']);
        $this->assertSame('Normal', $result['media'][0]['title']);
    }

    public function testListMediaWithSystemCollectionIdThrowsToolCallExceptionWhenSystemViewDenied(): void
    {
        $this->mediaManager->expects($this->never())->method('get');

        $this->permissionChecker->method('has')->willReturn(false);
        $this->systemCollectionManager->method('isSystemCollection')->with(1)->willReturn(true);

        $this->expectException(ToolCallException::class);

        $this->tool->listMedia('en', 1);
    }

    public function testListMediaThrowsToolCallExceptionWhenPermissionDenied(): void
    {
        $this->permissionChecker
            ->method('check')
            ->willThrowException(new PermissionDeniedException('sulu.media.collections', PermissionTypes::VIEW, 'en'));

        $this->mediaManager->expects($this->never())->method('get');

        $this->expectException(ToolCallException::class);

        $this->tool->listMedia('en', 5);
    }

    public function testListMediaMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(MediaListTool::class, 'listMedia');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes, 'listMedia() method must have exactly one #[McpTool] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_media_list', $instance->name);
        $this->assertStringContainsString('tag-based filtering is not supported', $instance->description);
    }

    public function testTypesParameterHasSchemaAttribute(): void
    {
        $reflection = new \ReflectionMethod(MediaListTool::class, 'listMedia');
        $parameter = $reflection->getParameters()[3];
        $this->assertSame('types', $parameter->getName());

        $attributes = $parameter->getAttributes(Schema::class);
        $this->assertCount(1, $attributes);

        $schema = $attributes[0]->newInstance();
        $this->assertSame('array', $schema->type);
        $this->assertSame(['type' => 'string'], $schema->items);
    }
}
