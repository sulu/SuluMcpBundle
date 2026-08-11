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
use Sulu\Bundle\SecurityBundle\Entity\User;
use Sulu\Component\Media\SystemCollections\SystemCollectionManagerInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\Infrastructure\Sulu\AdminLink\MediaAdminLinkProvider;
use Sulu\Mcp\Infrastructure\Symfony\Routing\AdminLinkGenerator;
use Sulu\Mcp\Tests\Application\TestBundle\Admin\TestViewRegistry;
use Sulu\Mcp\UserInterface\Mcp\Tool\Media\MediaUpdateTool;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

#[CoversClass(MediaUpdateTool::class)]
final class MediaUpdateToolTest extends TestCase
{
    private MediaManagerInterface&MockObject $mediaManager;
    private TokenStorageInterface&MockObject $tokenStorage;
    private ToolPermissionCheckerInterface&MockObject $permissionChecker;
    private MediaUpdateTool $tool;

    protected function setUp(): void
    {
        $this->mediaManager = $this->createMock(MediaManagerInterface::class);
        $this->tokenStorage = $this->createMock(TokenStorageInterface::class);
        $this->permissionChecker = $this->createMock(ToolPermissionCheckerInterface::class);

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturn('https://example.com/admin/');
        $adminLinkGenerator = new AdminLinkGenerator($router, [new MediaAdminLinkProvider(new TestViewRegistry())]);

        $this->tool = new MediaUpdateTool($this->mediaManager, $this->tokenStorage, $adminLinkGenerator, $this->permissionChecker);
    }

    private function authenticateAsUser(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $this->tokenStorage->method('getToken')->willReturn($token);
    }

    /**
     * @param non-empty-string|null $typeKey
     */
    private function loadedMedia(int $collectionId, ?string $typeKey = null): Media&MockObject
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

    public function testUpdateMediaSuccessfully(): void
    {
        $this->authenticateAsUser();

        $this->mediaManager->method('getById')->willReturn($this->loadedMedia(5));

        $media = $this->createMock(Media::class);
        $media->method('getId')->willReturn(42);
        $media->method('getTitle')->willReturn('Updated Title');

        $this->mediaManager
            ->expects($this->once())
            ->method('save')
            ->with(
                null,
                $this->callback(fn (array $data): bool => 42 === $data['id']
                    && 'en' === $data['locale']
                    && 'Updated Title' === $data['title']),
                1,
            )
            ->willReturn($media);

        $result = $this->tool->updateMedia(42, 'en', 'Updated Title');

        $this->assertTrue($result['success']);
        $this->assertSame(42, $result['id']);
        $this->assertSame('Updated Title', $result['title']);
        $this->assertSame('https://example.com/admin/#/media/en/42', $result['admin_url']);
    }

    public function testUpdateMediaReturnsErrorWhenNoUser(): void
    {
        $this->tokenStorage->method('getToken')->willReturn(null);

        $result = $this->tool->updateMedia(42, 'en', 'Title');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('authenticated', $result['error']);
        $this->assertTrue(\array_key_exists('hint', $result));
        $this->assertIsString($result['hint']);
        $this->assertNotEmpty($result['hint']);
    }

    public function testUpdateMediaPassesOnlyProvidedFields(): void
    {
        $this->authenticateAsUser();

        $this->mediaManager->method('getById')->willReturn($this->loadedMedia(5));

        $media = $this->createMock(Media::class);
        $media->method('getId')->willReturn(42);
        $media->method('getTitle')->willReturn('Original');

        $this->mediaManager
            ->expects($this->once())
            ->method('save')
            ->with(
                null,
                $this->callback(fn (array $data): bool => 42 === $data['id']
                    && isset($data['copyright'])
                    && !\array_key_exists('title', $data)
                    && !\array_key_exists('description', $data)),
                1,
            )
            ->willReturn($media);

        $this->tool->updateMedia(42, 'en', null, null, '(c) 2026');
    }

    public function testUpdateMediaReturnsHintOnSaveFailure(): void
    {
        $this->authenticateAsUser();

        $this->mediaManager->method('getById')->willReturn($this->loadedMedia(5));
        $this->mediaManager->method('save')->willThrowException(new \RuntimeException('Save failed'));

        $result = $this->tool->updateMedia(42, 'en', 'Title');

        $this->assertArrayHasKey('error', $result);
        $this->assertTrue(\array_key_exists('hint', $result));
        $this->assertIsString($result['hint']);
        $this->assertNotEmpty($result['hint']);
    }

    public function testUpdateMediaChecksCollectionPermission(): void
    {
        $this->authenticateAsUser();

        $this->mediaManager->method('getById')->willReturn($this->loadedMedia(9));

        $media = $this->createMock(Media::class);
        $media->method('getId')->willReturn(42);
        $media->method('getTitle')->willReturn('Title');
        $this->mediaManager->method('save')->willReturn($media);

        $this->permissionChecker
            ->expects($this->once())
            ->method('check')
            ->with('sulu.media.collections', PermissionTypes::EDIT, 'en', Collection::class, 9);

        $this->tool->updateMedia(42, 'en', 'Title');
    }

    public function testUpdateMediaAlsoChecksSystemCollectionPermission(): void
    {
        $this->authenticateAsUser();

        $this->mediaManager->method('getById')->willReturn($this->loadedMedia(1, SystemCollectionManagerInterface::COLLECTION_TYPE));

        $media = $this->createMock(Media::class);
        $media->method('getId')->willReturn(42);
        $media->method('getTitle')->willReturn('Title');
        $this->mediaManager->method('save')->willReturn($media);

        $calls = [];
        $this->permissionChecker
            ->expects($this->exactly(2))
            ->method('check')
            ->willReturnCallback(function (string $context, string $permission) use (&$calls): void {
                $calls[] = [$context, $permission];
            });

        $this->tool->updateMedia(42, 'en', 'Title');

        $this->assertSame(
            [
                ['sulu.media.system_collections', PermissionTypes::VIEW],
                ['sulu.media.collections', PermissionTypes::EDIT],
            ],
            $calls,
        );
    }

    public function testUpdateMediaThrowsToolCallExceptionWhenSystemCollectionViewDenied(): void
    {
        $this->authenticateAsUser();

        $this->mediaManager->method('getById')->willReturn($this->loadedMedia(1, SystemCollectionManagerInterface::COLLECTION_TYPE));

        $this->permissionChecker
            ->method('check')
            ->willThrowException(new PermissionDeniedException('sulu.media.system_collections', PermissionTypes::VIEW, 'en'));

        $this->mediaManager->expects($this->never())->method('save');

        $this->expectException(ToolCallException::class);

        $this->tool->updateMedia(42, 'en', 'Title');
    }

    public function testUpdateMediaThrowsToolCallExceptionWhenPermissionDenied(): void
    {
        $this->authenticateAsUser();

        $this->mediaManager->method('getById')->willReturn($this->loadedMedia(9));

        $this->permissionChecker
            ->method('check')
            ->willThrowException(new PermissionDeniedException('sulu.media.collections', PermissionTypes::EDIT, 'en'));

        $this->mediaManager->expects($this->never())->method('save');

        $this->expectException(ToolCallException::class);

        $this->tool->updateMedia(42, 'en', 'Title');
    }

    public function testUpdateMediaMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(MediaUpdateTool::class, 'updateMedia');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes, 'updateMedia() method must have exactly one #[McpTool] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_media_update', $instance->name);
    }
}
