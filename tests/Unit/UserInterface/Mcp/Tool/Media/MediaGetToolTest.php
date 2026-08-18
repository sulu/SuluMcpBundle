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
use Sulu\Component\Media\SystemCollections\SystemCollectionManagerInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Tests\Unit\Fixture\FakeToolPermissionChecker;
use Sulu\Mcp\UserInterface\Mcp\Tool\Media\MediaGetTool;

#[CoversClass(MediaGetTool::class)]
final class MediaGetToolTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<MediaManagerInterface> */
    private ObjectProphecy $mediaManager;

    private FakeToolPermissionChecker $permissionChecker;
    private MediaGetTool $tool;

    protected function setUp(): void
    {
        $this->mediaManager = $this->prophesize(MediaManagerInterface::class);
        $this->permissionChecker = FakeToolPermissionChecker::grantingAll();
        $this->tool = new MediaGetTool($this->mediaManager->reveal(), $this->permissionChecker);
    }

    /**
     * The collection graph (Collection/CollectionType/the wrapped media entity) is real
     * where the public API allows it; Collection has no public id setter (Doctrine sets
     * it on persist), so it stays a Prophecy double. The Media API wrapper itself would
     * need a full File/FileVersion/FileVersionMeta graph to answer getTitle()/getSize()/
     * etc. for real, so it also stays a Prophecy double.
     *
     * @param non-empty-string|null $typeKey
     *
     * @return ObjectProphecy<Media>
     */
    private function mediaWithCollection(int $collectionId, ?string $typeKey = null): ObjectProphecy
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

        // getMedia() always reads these to build its return array, even in tests that
        // only care about the permission side effects; give them harmless defaults.
        $media->getId()->willReturn(0);
        $media->getTitle()->willReturn(null);
        $media->getDescription()->willReturn(null);
        $media->getCopyright()->willReturn(null);
        $media->getMimeType()->willReturn(null);
        $media->getSize()->willReturn(0);
        $media->getUrl()->willReturn('');
        $media->getFormats()->willReturn([]);

        return $media;
    }

    public function testGetMediaReturnsFullDetails(): void
    {
        $media = $this->mediaWithCollection(5);
        $media->getId()->willReturn(42);
        $media->getTitle()->willReturn('Hero Image');
        $media->getDescription()->willReturn('A beautiful hero image');
        $media->getCopyright()->willReturn('(c) 2026 Example');
        $media->getMimeType()->willReturn('image/png');
        $media->getSize()->willReturn(54321);
        $media->getUrl()->willReturn('/media/42/hero.png');
        $media->getFormats()->willReturn([
            'sulu-100x100' => '/media/42/hero.png?v=1-0&inline=1',
            'sulu-400x400' => '/media/42/hero.png?v=1-0',
        ]);

        $this->mediaManager->getById(Argument::cetera())->willReturn($media->reveal());

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
        $this->mediaManager->getById(Argument::cetera())->willThrow(new \RuntimeException('Not found'));

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
        $this->mediaManager->getById(Argument::cetera())->willReturn($media->reveal());

        $this->tool->getMedia(42, 'en');

        self::assertSame([[
            'context' => 'sulu.media.collections',
            'permissions' => [PermissionTypes::VIEW],
            'locale' => 'en',
            'objectType' => Collection::class,
            'objectId' => 7,
        ]], $this->permissionChecker->calls());
    }

    public function testGetMediaAlsoChecksSystemCollectionPermission(): void
    {
        $media = $this->mediaWithCollection(1, SystemCollectionManagerInterface::COLLECTION_TYPE);
        $this->mediaManager->getById(Argument::cetera())->willReturn($media->reveal());

        $this->tool->getMedia(42, 'en');

        $this->assertSame(
            [
                ['sulu.media.system_collections', PermissionTypes::VIEW],
                ['sulu.media.collections', PermissionTypes::VIEW],
            ],
            $this->permissionChecker->checkedPairs(),
        );
    }

    public function testGetMediaThrowsToolCallExceptionWhenPermissionDenied(): void
    {
        $media = $this->mediaWithCollection(7);
        $this->mediaManager->getById(Argument::cetera())->willReturn($media->reveal());

        $this->permissionChecker->denyAll();

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
