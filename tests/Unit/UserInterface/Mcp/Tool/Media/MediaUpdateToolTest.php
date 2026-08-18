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
use Sulu\Mcp\Infrastructure\Sulu\AdminLink\MediaAdminLinkProvider;
use Sulu\Mcp\Infrastructure\Symfony\Routing\AdminLinkGenerator;
use Sulu\Mcp\Tests\Application\TestBundle\Admin\TestViewRegistry;
use Sulu\Mcp\Tests\Unit\Fixture\FakeToolPermissionChecker;
use Sulu\Mcp\Tests\Unit\Fixture\TestUser;
use Sulu\Mcp\UserInterface\Mcp\Tool\Media\MediaUpdateTool;
use Symfony\Component\Routing\Loader\ClosureLoader;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Router;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

#[CoversClass(MediaUpdateTool::class)]
final class MediaUpdateToolTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<MediaManagerInterface> */
    private ObjectProphecy $mediaManager;

    private TokenStorageInterface $tokenStorage;
    private FakeToolPermissionChecker $permissionChecker;
    private MediaUpdateTool $tool;

    protected function setUp(): void
    {
        $this->mediaManager = $this->prophesize(MediaManagerInterface::class);
        $this->tokenStorage = new TokenStorage();
        $this->permissionChecker = FakeToolPermissionChecker::grantingAll();

        $adminLinkGenerator = new AdminLinkGenerator($this->router(), [new MediaAdminLinkProvider(new TestViewRegistry())]);

        $this->tool = new MediaUpdateTool($this->mediaManager->reveal(), $this->tokenStorage, $adminLinkGenerator, $this->permissionChecker);
    }

    private function router(): RouterInterface
    {
        $routes = new RouteCollection();
        $routes->add('sulu_admin', new Route('/admin/'));

        return new Router(
            new ClosureLoader(),
            static fn () => $routes,
            [],
            new RequestContext(host: 'example.com', scheme: 'https'),
        );
    }

    private function authenticateAsUser(): void
    {
        $this->tokenStorage->setToken(new UsernamePasswordToken(new TestUser(1), 'admin'));
    }

    /**
     * The collection graph (Collection/CollectionType/the wrapped media entity) is real
     * where the public API allows it; Collection has no public id setter (Doctrine sets
     * it on persist), so it stays a Prophecy double. The Media API wrapper itself would
     * need a full File/FileVersion/FileVersionMeta graph to answer getTitle()/getId() for
     * real, so it also stays a Prophecy double.
     *
     * @param non-empty-string|null $typeKey
     *
     * @return ObjectProphecy<Media>
     */
    private function loadedMedia(int $collectionId, ?string $typeKey = null): ObjectProphecy
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

        return $media;
    }

    public function testUpdateMediaSuccessfully(): void
    {
        $this->authenticateAsUser();

        $this->mediaManager->getById(Argument::cetera())->willReturn($this->loadedMedia(5)->reveal());

        $media = $this->prophesize(Media::class);
        $media->getId()->willReturn(42);
        $media->getTitle()->willReturn('Updated Title');

        $this->mediaManager
            ->save(
                null,
                Argument::that(fn (array $data): bool => 42 === $data['id']
                    && 'en' === $data['locale']
                    && 'Updated Title' === $data['title']),
                1,
            )
            ->shouldBeCalledOnce()
            ->willReturn($media->reveal());

        $result = $this->tool->updateMedia(42, 'en', 'Updated Title');

        $this->assertTrue($result['success']);
        $this->assertSame(42, $result['id']);
        $this->assertSame('Updated Title', $result['title']);
        $this->assertSame('https://example.com/admin/#/media/en/42', $result['admin_url']);
    }

    public function testUpdateMediaReturnsErrorWhenNoUser(): void
    {
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

        $this->mediaManager->getById(Argument::cetera())->willReturn($this->loadedMedia(5)->reveal());

        $media = $this->prophesize(Media::class);
        $media->getId()->willReturn(42);
        $media->getTitle()->willReturn('Original');

        $this->mediaManager
            ->save(
                null,
                Argument::that(fn (array $data): bool => 42 === $data['id']
                    && isset($data['copyright'])
                    && !\array_key_exists('title', $data)
                    && !\array_key_exists('description', $data)),
                1,
            )
            ->shouldBeCalledOnce()
            ->willReturn($media->reveal());

        $this->tool->updateMedia(42, 'en', null, null, '(c) 2026');
    }

    public function testUpdateMediaReturnsHintOnSaveFailure(): void
    {
        $this->authenticateAsUser();

        $this->mediaManager->getById(Argument::cetera())->willReturn($this->loadedMedia(5)->reveal());
        $this->mediaManager->save(Argument::cetera())->willThrow(new \RuntimeException('Save failed'));

        $result = $this->tool->updateMedia(42, 'en', 'Title');

        $this->assertArrayHasKey('error', $result);
        $this->assertTrue(\array_key_exists('hint', $result));
        $this->assertIsString($result['hint']);
        $this->assertNotEmpty($result['hint']);
    }

    public function testUpdateMediaChecksCollectionPermission(): void
    {
        $this->authenticateAsUser();

        $this->mediaManager->getById(Argument::cetera())->willReturn($this->loadedMedia(9)->reveal());

        $media = $this->prophesize(Media::class);
        $media->getId()->willReturn(42);
        $media->getTitle()->willReturn('Title');
        $this->mediaManager->save(Argument::cetera())->willReturn($media->reveal());

        $this->tool->updateMedia(42, 'en', 'Title');

        self::assertSame([[
            'context' => 'sulu.media.collections',
            'permissions' => [PermissionTypes::EDIT],
            'locale' => 'en',
            'objectType' => Collection::class,
            'objectId' => 9,
        ]], $this->permissionChecker->calls());
    }

    public function testUpdateMediaAlsoChecksSystemCollectionPermission(): void
    {
        $this->authenticateAsUser();

        $this->mediaManager->getById(Argument::cetera())->willReturn(
            $this->loadedMedia(1, SystemCollectionManagerInterface::COLLECTION_TYPE)->reveal(),
        );

        $media = $this->prophesize(Media::class);
        $media->getId()->willReturn(42);
        $media->getTitle()->willReturn('Title');
        $this->mediaManager->save(Argument::cetera())->willReturn($media->reveal());

        $this->tool->updateMedia(42, 'en', 'Title');

        $this->assertSame(
            [
                ['sulu.media.system_collections', PermissionTypes::VIEW],
                ['sulu.media.collections', PermissionTypes::EDIT],
            ],
            $this->permissionChecker->checkedPairs(),
        );
    }

    public function testUpdateMediaThrowsToolCallExceptionWhenSystemCollectionViewDenied(): void
    {
        $this->authenticateAsUser();

        $this->mediaManager->getById(Argument::cetera())->willReturn(
            $this->loadedMedia(1, SystemCollectionManagerInterface::COLLECTION_TYPE)->reveal(),
        );

        $this->permissionChecker->denyAll();

        $this->mediaManager->save(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(ToolCallException::class);

        $this->tool->updateMedia(42, 'en', 'Title');
    }

    public function testUpdateMediaThrowsToolCallExceptionWhenPermissionDenied(): void
    {
        $this->authenticateAsUser();

        $this->mediaManager->getById(Argument::cetera())->willReturn($this->loadedMedia(9)->reveal());

        $this->permissionChecker->denyAll();

        $this->mediaManager->save(Argument::cetera())->shouldNotBeCalled();

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
