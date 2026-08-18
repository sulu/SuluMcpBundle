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
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Bundle\MediaBundle\Api\Media;
use Sulu\Bundle\MediaBundle\Entity\Collection;
use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;
use Sulu\Component\Media\SystemCollections\SystemCollectionManagerInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Tests\Unit\Fixture\FakeToolPermissionChecker;
use Sulu\Mcp\UserInterface\Mcp\Tool\Media\MediaListTool;

#[CoversClass(MediaListTool::class)]
final class MediaListToolTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<MediaManagerInterface> */
    private ObjectProphecy $mediaManager;

    private FakeToolPermissionChecker $permissionChecker;

    /** @var ObjectProphecy<SystemCollectionManagerInterface> */
    private ObjectProphecy $systemCollectionManager;

    private MediaListTool $tool;

    protected function setUp(): void
    {
        $this->mediaManager = $this->prophesize(MediaManagerInterface::class);
        $this->permissionChecker = FakeToolPermissionChecker::grantingAll();
        $this->systemCollectionManager = $this->prophesize(SystemCollectionManagerInterface::class);
        $this->tool = new MediaListTool(
            $this->mediaManager->reveal(),
            $this->permissionChecker,
            $this->systemCollectionManager->reveal(),
        );
    }

    public function testListMediaReturnsFormattedResults(): void
    {
        $media1 = $this->prophesize(Media::class);
        $media1->getId()->willReturn(1);
        $media1->getTitle()->willReturn('Photo 1');
        $media1->getMimeType()->willReturn('image/jpeg');
        $media1->getSize()->willReturn(12345);
        $media1->getUrl()->willReturn('/media/1/photo1.jpg');
        $media1->getCollection()->willReturn(3);

        $media2 = $this->prophesize(Media::class);
        $media2->getId()->willReturn(2);
        $media2->getTitle()->willReturn('Document');
        $media2->getMimeType()->willReturn('application/pdf');
        $media2->getSize()->willReturn(67890);
        $media2->getUrl()->willReturn('/media/2/document.pdf');
        $media2->getCollection()->willReturn(3);

        $this->mediaManager->get(Argument::cetera())->willReturn([$media1->reveal(), $media2->reveal()]);
        $this->mediaManager->getCount()->willReturn(10);

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
            ->get(
                'de',
                Argument::that(fn (array $filter): bool => 5 === $filter['collection']
                    && 'test' === $filter['search']
                    && ['image'] === $filter['types']),
                10,
                20,
            )
            ->shouldBeCalledOnce()
            ->willReturn([]);
        $this->mediaManager->getCount()->willReturn(0);

        $this->tool->listMedia('de', 5, 'test', ['image'], 3, 10);
    }

    /**
     * Without system_collections VIEW, the exclusion filter is pushed into the query,
     * so excluded media stay out of `total` too, not just the results list.
     */
    public function testListMediaExcludesSystemCollectionsInTheQueryWhenNotPermitted(): void
    {
        $this->permissionChecker->denyContext('sulu.media.system_collections');

        $this->mediaManager
            ->get(
                'en',
                ['systemCollections' => false],
                20,
                0,
            )
            ->shouldBeCalledOnce()
            ->willReturn([]);
        $this->mediaManager->getCount()->willReturn(0);

        $this->tool->listMedia('en');

        $this->addToAssertionCount(1);
    }

    public function testListMediaDoesNotExcludeSystemCollectionsWhenPermitted(): void
    {
        $permissionChecker = FakeToolPermissionChecker::grantingAll();

        $mediaManager = $this->prophesize(MediaManagerInterface::class);
        $mediaManager
            ->get('en', [], 20, 0)
            ->shouldBeCalledOnce()
            ->willReturn([]);
        $mediaManager->getCount()->willReturn(0);

        $tool = new MediaListTool($mediaManager->reveal(), $permissionChecker, $this->systemCollectionManager->reveal());

        $tool->listMedia('en');

        $this->addToAssertionCount(1);
    }

    public function testListMediaWithCollectionIdChecksObjectPermission(): void
    {
        $this->mediaManager->get(Argument::cetera())->willReturn([]);
        $this->mediaManager->getCount()->willReturn(0);

        $this->tool->listMedia('en', 5);

        self::assertSame([[
            'context' => 'sulu.media.collections',
            'permissions' => [PermissionTypes::VIEW],
            'locale' => 'en',
            'objectType' => Collection::class,
            'objectId' => 5,
        ]], $this->permissionChecker->calls());
    }

    public function testListMediaWithoutCollectionIdSkipsObjectPermissionCheck(): void
    {
        $this->mediaManager->get(Argument::cetera())->willReturn([]);
        $this->mediaManager->getCount()->willReturn(0);

        $this->tool->listMedia('en');

        $this->assertSame([], $this->permissionChecker->calls());
    }

    public function testListMediaWithoutCollectionIdFiltersRowsByCollectionPermission(): void
    {
        $mediaInAllowedCollection = $this->prophesize(Media::class);
        $mediaInAllowedCollection->getId()->willReturn(1);
        $mediaInAllowedCollection->getTitle()->willReturn('Allowed');
        $mediaInAllowedCollection->getMimeType()->willReturn('image/jpeg');
        $mediaInAllowedCollection->getSize()->willReturn(111);
        $mediaInAllowedCollection->getUrl()->willReturn('/media/1/allowed.jpg');
        $mediaInAllowedCollection->getCollection()->willReturn(3);

        $mediaInDeniedCollection = $this->prophesize(Media::class);
        $mediaInDeniedCollection->getId()->willReturn(2);
        $mediaInDeniedCollection->getTitle()->willReturn('Denied');
        $mediaInDeniedCollection->getMimeType()->willReturn('application/pdf');
        $mediaInDeniedCollection->getSize()->willReturn(222);
        $mediaInDeniedCollection->getUrl()->willReturn('/media/2/denied.pdf');
        $mediaInDeniedCollection->getCollection()->willReturn(7);

        $this->mediaManager->get(Argument::cetera())->willReturn([
            $mediaInAllowedCollection->reveal(),
            $mediaInDeniedCollection->reveal(),
        ]);
        $this->mediaManager->getCount()->willReturn(2);

        // Caller has system_collections VIEW here, so this test isolates the
        // per-collection EDIT filtering from the system-collection gate below.
        $this->permissionChecker
            ->grantWhen(static fn (
                string $context,
                string $permission,
                ?string $locale,
                ?string $objectType,
                mixed $objectId,
            ): bool => 'sulu.media.system_collections' === $context || 3 === $objectId);

        $result = $this->tool->listMedia('en');

        $this->assertCount(1, $result['media']);
        $this->assertSame(1, $result['media'][0]['id']);
        $this->assertSame('Allowed', $result['media'][0]['title']);
    }

    public function testListMediaWithoutCollectionIdExcludesSystemCollectionMediaWhenSystemViewDenied(): void
    {
        $mediaInNormalCollection = $this->prophesize(Media::class);
        $mediaInNormalCollection->getId()->willReturn(1);
        $mediaInNormalCollection->getTitle()->willReturn('Normal');
        $mediaInNormalCollection->getMimeType()->willReturn('image/jpeg');
        $mediaInNormalCollection->getSize()->willReturn(111);
        $mediaInNormalCollection->getUrl()->willReturn('/media/1/normal.jpg');
        $mediaInNormalCollection->getCollection()->willReturn(3);

        $mediaInSystemCollection = $this->prophesize(Media::class);
        $mediaInSystemCollection->getId()->willReturn(2);
        $mediaInSystemCollection->getTitle()->willReturn('System');
        $mediaInSystemCollection->getMimeType()->willReturn('image/png');
        $mediaInSystemCollection->getSize()->willReturn(222);
        $mediaInSystemCollection->getUrl()->willReturn('/media/2/system.png');
        $mediaInSystemCollection->getCollection()->willReturn(99);

        $this->mediaManager->get(Argument::cetera())->willReturn([
            $mediaInNormalCollection->reveal(),
            $mediaInSystemCollection->reveal(),
        ]);
        $this->mediaManager->getCount()->willReturn(2);

        // Caller has collection EDIT on both collections, but NOT system_collections VIEW.
        $this->permissionChecker->denyContext('sulu.media.system_collections');

        $this->systemCollectionManager->isSystemCollection(3)->willReturn(false);
        $this->systemCollectionManager->isSystemCollection(99)->willReturn(true);

        $result = $this->tool->listMedia('en');

        $this->assertCount(1, $result['media']);
        $this->assertSame(1, $result['media'][0]['id']);
        $this->assertSame('Normal', $result['media'][0]['title']);
    }

    public function testListMediaWithSystemCollectionIdThrowsToolCallExceptionWhenSystemViewDenied(): void
    {
        $this->mediaManager->get(Argument::cetera())->shouldNotBeCalled();

        $this->permissionChecker->denyAll();
        $this->systemCollectionManager->isSystemCollection(1)->willReturn(true);

        $this->expectException(ToolCallException::class);

        $this->tool->listMedia('en', 1);
    }

    public function testListMediaThrowsToolCallExceptionWhenPermissionDenied(): void
    {
        $this->permissionChecker->denyAll();

        $this->mediaManager->get(Argument::cetera())->shouldNotBeCalled();

        // Not exercised by this test (the collection ACL check throws first), but the
        // subject calls it unconditionally once system_collections VIEW is denied.
        $this->systemCollectionManager->isSystemCollection(5)->willReturn(false);

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
