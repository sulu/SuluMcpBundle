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
use Sulu\Bundle\MediaBundle\Media\Exception\MediaNotFoundException;
use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;
use Sulu\Component\Media\SystemCollections\SystemCollectionManagerInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Application\Media\MediaDownloader;
use Sulu\Mcp\Application\Media\MediaSourceUrlResolver;
use Sulu\Mcp\Infrastructure\Sulu\AdminLink\MediaAdminLinkProvider;
use Sulu\Mcp\Infrastructure\Symfony\Routing\AdminLinkGenerator;
use Sulu\Mcp\Tests\Application\TestBundle\Admin\TestViewRegistry;
use Sulu\Mcp\Tests\Unit\Fixture\FakeToolPermissionChecker;
use Sulu\Mcp\Tests\Unit\Fixture\TestUser;
use Sulu\Mcp\UserInterface\Mcp\Tool\Media\MediaUploadTool;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Loader\ClosureLoader;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Router;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[CoversClass(MediaUploadTool::class)]
final class MediaUploadToolTest extends TestCase
{
    use ProphecyTrait;

    private const LOCAL_SERVER = 'https://sulu.example.com';
    private const COLLECTION_ID = 7;

    /** @var ObjectProphecy<MediaManagerInterface> */
    private ObjectProphecy $mediaManager;

    /** @var ObjectProphecy<SystemCollectionManagerInterface> */
    private ObjectProphecy $systemCollectionManager;

    private TokenStorageInterface $tokenStorage;
    private FakeToolPermissionChecker $permissionChecker;

    /** @var list<string> */
    private array $requestedUrls = [];

    /** @var array<string, mixed>|null */
    private ?array $savedData = null;

    private ?string $savedFilePath = null;
    private ?string $savedClientName = null;

    protected function setUp(): void
    {
        $this->mediaManager = $this->prophesize(MediaManagerInterface::class);
        $this->systemCollectionManager = $this->prophesize(SystemCollectionManagerInterface::class);
        $this->systemCollectionManager->isSystemCollection(Argument::cetera())->willReturn(false);
        $this->tokenStorage = new TokenStorage();
        $this->permissionChecker = FakeToolPermissionChecker::grantingAll();
    }

    public function testUploadsAPlainImageUrlIntoTheCollection(): void
    {
        $this->authenticateAsUser();
        $this->expectSaveReturning(101, 'Photo');

        $result = $this->tool()->uploadMedia('https://example.com/photo.gif', self::COLLECTION_ID, 'en');

        self::assertTrue($result['success']);
        self::assertSame(101, $result['id']);
        self::assertSame('direct', $result['resolved_from']);
        self::assertFalse($result['existing']);
        self::assertSame('https://example.com/admin/#/media/en/101', $result['admin_url']);
        self::assertSame(self::COLLECTION_ID, $this->savedData['collection'] ?? null);
        self::assertSame('photo.gif', $this->savedClientName);
    }

    public function testAFormatUrlIsRewrittenSoTheOriginalIsFetched(): void
    {
        $this->authenticateAsUser();
        $this->expectSaveReturning(102, 'Seal');

        $result = $this->tool()->uploadMedia(
            'https://sulu.io/uploads/media/800x/00/230-seal.gif?v=2-6',
            self::COLLECTION_ID,
            'en',
        );

        self::assertSame('format_url', $result['resolved_from']);
        self::assertSame(
            ['https://sulu.io/media/230/download/seal.gif'],
            $this->requestedUrls,
            'Downloading the format URL would import a resized derivative and lose the source resolution.',
        );
    }

    public function testAFormatUrlThatDoesNotResolveFallsBackToTheUrlAsGiven(): void
    {
        $this->authenticateAsUser();
        $this->expectSaveReturning(103, 'Seal');

        $given = 'https://not-sulu.example.com/uploads/media/800x/00/230-seal.gif';

        $client = $this->clientRespondingTo([
            'https://not-sulu.example.com/media/230/download/seal.gif' => new MockResponse('nope', ['http_code' => 404]),
        ]);

        $result = $this->tool($client)->uploadMedia($given, self::COLLECTION_ID, 'en');

        self::assertTrue(
            $result['success'],
            'The rewrite is a guess about a remote\'s routing, so a site that only looks like Sulu must still import.',
        );
        self::assertSame(
            ['https://not-sulu.example.com/media/230/download/seal.gif', $given],
            $this->requestedUrls,
        );
    }

    public function testAnOversizedOriginalIsNotQuietlyReplacedByTheThumbnail(): void
    {
        $this->authenticateAsUser();
        $this->mediaManager->save(Argument::cetera())->shouldNotBeCalled();

        $given = 'https://sulu.io/uploads/media/800x/00/230-seal.gif?v=2-6';

        $client = $this->clientRespondingTo([
            'https://sulu.io/media/230/download/seal.gif' => new MockResponse(\str_repeat('x', 4096)),
        ]);

        $result = $this->tool($client, maxFileSize: 64)->uploadMedia($given, self::COLLECTION_ID, 'en');

        self::assertStringContainsString('larger than the configured limit', $result['error']);
        self::assertSame(
            ['https://sulu.io/media/230/download/seal.gif'],
            $this->requestedUrls,
            'Falling back here would import the resized derivative the rewrite exists to avoid, and would hide the limit that refused the original.',
        );
    }

    public function testTheUploadStillSucceedsWhenTheProvenanceSaveFails(): void
    {
        $this->authenticateAsUser();

        $uploaded = $this->prophesize(Media::class);
        $uploaded->getId()->willReturn(120);
        $uploaded->getTitle()->willReturn('photo');
        $uploaded->getUrl()->willReturn('/media/120/download/photo.gif?v=1');
        $uploaded->getMimeType()->willReturn('image/gif');
        $uploaded->getSize()->willReturn(43);
        $uploaded->getFormats()->willReturn([]);
        $uploaded->getProperties()->willReturn([]);

        $this->mediaManager->save(Argument::type(UploadedFile::class), Argument::cetera())
            ->willReturn($uploaded->reveal());
        $this->mediaManager->save(null, Argument::cetera(), Argument::cetera())
            ->willThrow(new \RuntimeException('write conflict'));

        $result = $this->tool()->uploadMedia(
            'https://example.com/photo.gif',
            self::COLLECTION_ID,
            'en',
            sourceUrl: 'https://example.com/article',
        );

        self::assertTrue(
            $result['success'],
            'The media exists by then, so reporting failure would invite a retry that imports the file twice.',
        );
        self::assertSame(120, $result['id']);
        self::assertStringContainsString('source URL could not be recorded', $result['warning']);
    }

    public function testAUrlPointingAtThisInstanceReturnsTheExistingMediaWithoutDownloading(): void
    {
        $this->authenticateAsUser();

        $existing = $this->prophesize(Media::class);
        $existing->getId()->willReturn(230);
        $existing->getTitle()->willReturn('Seal');
        $existing->getUrl()->willReturn('/media/230/download/seal.gif?v=1');
        $existing->getMimeType()->willReturn('image/gif');
        $existing->getSize()->willReturn(43);
        $existing->getFormats()->willReturn([]);
        $existing->getEntity()->willReturn($this->mediaEntityInCollection(3));

        $this->mediaManager->getById(230, 'en')->willReturn($existing->reveal())->shouldBeCalledOnce();
        $this->mediaManager->save(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool()->uploadMedia(
            self::LOCAL_SERVER . '/uploads/media/800x/00/230-seal.gif?v=1-0',
            self::COLLECTION_ID,
            'en',
        );

        self::assertSame(230, $result['id']);
        self::assertTrue($result['existing']);
        self::assertSame('local_media', $result['resolved_from']);
        self::assertSame([], $this->requestedUrls, 'Re-importing an image the instance already owns is never what was meant.');
    }

    public function testTheExistingMediaPathStillChecksViewOnItsOwnCollection(): void
    {
        $this->authenticateAsUser();

        $existing = $this->prophesize(Media::class);
        $existing->getId()->willReturn(230);
        $existing->getTitle()->willReturn('Seal');
        $existing->getUrl()->willReturn('/media/230/download/seal.gif');
        $existing->getMimeType()->willReturn('image/gif');
        $existing->getSize()->willReturn(43);
        $existing->getFormats()->willReturn([]);
        $existing->getEntity()->willReturn($this->mediaEntityInCollection(3));

        $this->mediaManager->getById(230, 'en')->willReturn($existing->reveal());

        $this->tool()->uploadMedia(self::LOCAL_SERVER . '/media/230/download/seal.gif', self::COLLECTION_ID, 'en');

        self::assertSame([
            ['context' => 'sulu.media.collections', 'permissions' => [PermissionTypes::ADD], 'locale' => 'en', 'objectType' => Collection::class, 'objectId' => self::COLLECTION_ID],
            ['context' => 'sulu.media.collections', 'permissions' => [PermissionTypes::VIEW], 'locale' => 'en', 'objectType' => Collection::class, 'objectId' => 3],
        ], $this->permissionChecker->calls(), 'Returning media from a collection the user cannot see would leak it.');
    }

    public function testNoTitleIsSentSoMediaManagerDerivesTheSameOneTheAdminUiWould(): void
    {
        $this->authenticateAsUser();
        $this->expectSaveReturning(104, 'photo');

        $this->tool()->uploadMedia('https://example.com/photo.gif', self::COLLECTION_ID, 'en');

        self::assertArrayNotHasKey(
            'title',
            (array) $this->savedData,
            'MediaManager::getTitleFromUpload() already strips the extension; repeating that rule here would let the two drift apart.',
        );
    }

    public function testTheMetadataGatheredFromTheSourcePageIsPersisted(): void
    {
        $this->authenticateAsUser();
        $this->expectSaveReturning(105, 'Sunset');

        $this->tool()->uploadMedia(
            'https://example.com/photo.gif',
            self::COLLECTION_ID,
            'en',
            title: 'Sunset',
            description: 'The sun setting over the bay',
            copyright: '(c) 2026 Example',
            credits: 'Photo: A. Example',
            origin: 'human_created',
        );

        self::assertSame('Sunset', $this->savedData['title'] ?? null);
        self::assertSame('The sun setting over the bay', $this->savedData['description'] ?? null);
        self::assertSame('(c) 2026 Example', $this->savedData['copyright'] ?? null);
        self::assertSame('Photo: A. Example', $this->savedData['credits'] ?? null);
        self::assertSame('human_created', $this->savedData['origin'] ?? null);
    }

    public function testTheSourcePageIsMergedIntoThePropertiesTheUploadItselfProduced(): void
    {
        $this->authenticateAsUser();

        $uploaded = $this->prophesize(Media::class);
        $uploaded->getId()->willReturn(111);
        $uploaded->getProperties()->willReturn(['exifCameraModel' => 'Example X1']);

        $this->mediaManager->save(Argument::type(UploadedFile::class), Argument::cetera())
            ->willReturn($uploaded->reveal());

        $stored = $this->describableMedia(111, 'photo');
        $this->mediaManager
            ->save(null, [
                'id' => 111,
                'locale' => 'en',
                'properties' => ['exifCameraModel' => 'Example X1', 'sourceUrl' => 'https://example.com/article'],
            ], 1)
            ->shouldBeCalledOnce()
            ->willReturn($stored);

        $result = $this->tool()->uploadMedia(
            'https://example.com/photo.gif',
            self::COLLECTION_ID,
            'en',
            sourceUrl: 'https://example.com/article',
        );

        self::assertTrue(
            $result['success'],
            'MediaManager replaces `properties` with what it extracted from the file, so provenance has to be merged in afterwards or it is lost.',
        );
    }

    public function testNoSecondSaveHappensWithoutASourceUrl(): void
    {
        $this->authenticateAsUser();
        $this->expectSaveReturning(112, 'photo');

        $this->mediaManager->save(null, Argument::cetera(), Argument::cetera())->shouldNotBeCalled();

        $this->tool()->uploadMedia('https://example.com/photo.gif', self::COLLECTION_ID, 'en');
    }

    public function testFieldsThatWereNotGatheredAreNotSent(): void
    {
        $this->authenticateAsUser();
        $this->expectSaveReturning(106, 'photo');

        $this->tool()->uploadMedia('https://example.com/photo.gif', self::COLLECTION_ID, 'en');

        self::assertArrayNotHasKey('copyright', (array) $this->savedData);
        self::assertArrayNotHasKey('credits', (array) $this->savedData);
        self::assertArrayNotHasKey('origin', (array) $this->savedData);
        self::assertArrayNotHasKey('properties', (array) $this->savedData);
    }

    public function testAnExplicitFileNameOverridesTheOneDerivedFromTheUrl(): void
    {
        $this->authenticateAsUser();
        $this->expectSaveReturning(107, 'hero');

        $this->tool()->uploadMedia('https://example.com/photo.gif', self::COLLECTION_ID, 'en', fileName: 'hero.gif');

        self::assertSame('hero.gif', $this->savedClientName);
    }

    public function testAnExplicitFileNameIsHeldToTheSameRulesAsOneTakenFromTheUrl(): void
    {
        $this->authenticateAsUser();
        $this->expectSaveReturning(113, 'evil');

        $this->tool()->uploadMedia(
            'https://example.com/photo.gif',
            self::COLLECTION_ID,
            'en',
            fileName: '../../evil.php',
        );

        self::assertSame(
            'evil.gif',
            $this->savedClientName,
            'The override is model-supplied too, so skipping the guard would be the one way back to a ".php" in the storage directory.',
        );
    }

    public function testAStaleLocalMediaUrlSaysWhatActuallyHappened(): void
    {
        $this->authenticateAsUser();

        $this->mediaManager->getById(230, 'en')->willThrow(new MediaNotFoundException(230));
        $this->mediaManager->save(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool()->uploadMedia(
            self::LOCAL_SERVER . '/media/230/download/seal.gif',
            self::COLLECTION_ID,
            'en',
        );

        self::assertStringContainsString('no longer exists', $result['error']);
        self::assertStringNotContainsString(
            'collection id',
            $result['hint'],
            'The URL names this instance, so blaming the target collection sends the assistant after the wrong thing.',
        );
    }

    public function testAnUnsupportedOriginIsRejected(): void
    {
        $this->authenticateAsUser();
        $this->mediaManager->save(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool()->uploadMedia('https://example.com/photo.gif', self::COLLECTION_ID, 'en', origin: 'made_up');

        self::assertStringContainsString('made_up', $result['error']);
        self::assertStringContainsString('ai_generated', $result['hint']);
    }

    public function testUploadRequiresAnAuthenticatedSuluUser(): void
    {
        $this->mediaManager->save(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool()->uploadMedia('https://example.com/photo.gif', self::COLLECTION_ID, 'en');

        self::assertStringContainsString('authenticated', $result['error']);
        self::assertNotEmpty($result['hint']);
    }

    public function testAddOnTheTargetCollectionIsCheckedObjectScoped(): void
    {
        $this->authenticateAsUser();
        $this->expectSaveReturning(108, 'photo');

        $this->tool()->uploadMedia('https://example.com/photo.gif', self::COLLECTION_ID, 'en');

        self::assertSame([[
            'context' => 'sulu.media.collections',
            'permissions' => [PermissionTypes::ADD],
            'locale' => 'en',
            'objectType' => Collection::class,
            'objectId' => self::COLLECTION_ID,
        ]], $this->permissionChecker->calls());
    }

    public function testASystemCollectionTargetAlsoRequiresTheSystemCollectionContext(): void
    {
        $this->authenticateAsUser();
        $this->systemCollectionManager->isSystemCollection(self::COLLECTION_ID)->willReturn(true);
        $this->expectSaveReturning(109, 'photo');

        $this->tool()->uploadMedia('https://example.com/photo.gif', self::COLLECTION_ID, 'en');

        self::assertSame([
            ['sulu.media.system_collections', PermissionTypes::VIEW],
            ['sulu.media.collections', PermissionTypes::ADD],
        ], $this->permissionChecker->checkedPairs());
    }

    public function testADeniedCollectionThrowsBeforeAnythingIsFetched(): void
    {
        $this->authenticateAsUser();
        $this->permissionChecker->denyAll();
        $this->mediaManager->save(Argument::cetera())->shouldNotBeCalled();

        try {
            $this->tool()->uploadMedia('https://example.com/photo.gif', self::COLLECTION_ID, 'en');
            self::fail('Expected a ToolCallException.');
        } catch (ToolCallException) {
            self::assertSame([], $this->requestedUrls, 'A denied upload must not make the server fetch the URL anyway.');
        }
    }

    public function testADownloadFailureIsReportedWithAHint(): void
    {
        $this->authenticateAsUser();
        $this->mediaManager->save(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool()->uploadMedia('http://127.0.0.1/photo.gif', self::COLLECTION_ID, 'en');

        self::assertStringContainsString('private or reserved address', $result['error']);
        self::assertNotEmpty($result['hint']);
    }

    public function testTheTemporaryFileIsGoneOnceTheUploadReturns(): void
    {
        $this->authenticateAsUser();
        $this->expectSaveReturning(110, 'photo');

        $this->tool()->uploadMedia('https://example.com/photo.gif', self::COLLECTION_ID, 'en');

        self::assertNotNull($this->savedFilePath);
        self::assertFileDoesNotExist($this->savedFilePath, 'Every path through the upload has to clean up after itself.');
    }

    public function testUploadMediaMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(MediaUploadTool::class, 'uploadMedia');
        $attributes = $reflection->getAttributes(McpTool::class);

        self::assertCount(1, $attributes, 'uploadMedia() must have exactly one #[McpTool] attribute');
        self::assertSame('sulu_media_upload', $attributes[0]->newInstance()->name);
    }

    private function tool(?HttpClientInterface $client = null, int $maxFileSize = 1048576): MediaUploadTool
    {
        $client ??= $this->clientRespondingTo([]);

        return new MediaUploadTool(
            $this->mediaManager->reveal(),
            new MediaSourceUrlResolver(self::LOCAL_SERVER),
            new MediaDownloader($client, $maxFileSize),
            $this->systemCollectionManager->reveal(),
            $this->tokenStorage,
            new AdminLinkGenerator($this->router(), [new MediaAdminLinkProvider(new TestViewRegistry())]),
            $this->permissionChecker,
        );
    }

    /**
     * @param array<string, MockResponse> $responses keyed by URL; anything else gets the image
     */
    private function clientRespondingTo(array $responses): MockHttpClient
    {
        return new MockHttpClient(function(string $method, string $url) use ($responses): MockResponse {
            $this->requestedUrls[] = $url;

            return $responses[$url] ?? new MockResponse(self::gif());
        });
    }

    private function describableMedia(int $id, string $title): Media
    {
        $media = $this->prophesize(Media::class);
        $media->getId()->willReturn($id);
        $media->getTitle()->willReturn($title);
        $media->getUrl()->willReturn(\sprintf('/media/%d/download/photo.gif?v=1', $id));
        $media->getMimeType()->willReturn('image/gif');
        $media->getSize()->willReturn(43);
        $media->getFormats()->willReturn([]);

        return $media->reveal();
    }

    private function expectSaveReturning(int $id, string $title): void
    {
        $media = $this->describableMedia($id, $title);

        $this->mediaManager
            ->save(
                Argument::that(function(mixed $file): bool {
                    self::assertInstanceOf(UploadedFile::class, $file);
                    $this->savedFilePath = $file->getPathname();
                    $this->savedClientName = $file->getClientOriginalName();

                    self::assertFileExists($file->getPathname(), 'MediaManager needs the bytes to still be there when it is called.');

                    return true;
                }),
                Argument::that(function(mixed $data): bool {
                    self::assertIsArray($data);
                    $this->savedData = $data;

                    return true;
                }),
                1,
            )
            ->shouldBeCalledOnce()
            ->willReturn($media);
    }

    private function mediaEntityInCollection(int $collectionId): MediaEntity
    {
        $collectionType = new CollectionType();
        $collectionType->setKey('collection.default');

        /** @var ObjectProphecy<Collection> $collection */
        $collection = $this->prophesize(Collection::class);
        $collection->getId()->willReturn($collectionId);
        $collection->getType()->willReturn($collectionType);

        $entity = new MediaEntity();
        $entity->setCollection($collection->reveal());

        return $entity;
    }

    private function authenticateAsUser(): void
    {
        $this->tokenStorage->setToken(new UsernamePasswordToken(new TestUser(1), 'admin'));
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

    private static function gif(): string
    {
        $gif = \base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true);
        \assert(\is_string($gif));

        return $gif;
    }
}
