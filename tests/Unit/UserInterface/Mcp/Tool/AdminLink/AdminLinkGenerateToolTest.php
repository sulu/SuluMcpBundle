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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\AdminLink;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Bundle\AdminBundle\Admin\View\ResourceViewUrlGeneratorInterface;
use Sulu\Bundle\AdminBundle\Exception\ResourceViewNotFoundException;
use Sulu\Bundle\AdminBundle\Exception\ViewNotFoundException;
use Sulu\Bundle\AdminBundle\Exception\ViewParameterNotFoundException;
use Sulu\Bundle\MediaBundle\Api\Media;
use Sulu\Bundle\MediaBundle\Entity\Collection;
use Sulu\Bundle\MediaBundle\Entity\CollectionType;
use Sulu\Bundle\MediaBundle\Entity\Media as MediaEntity;
use Sulu\Bundle\MediaBundle\Media\Exception\MediaNotFoundException;
use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;
use Sulu\Component\Media\SystemCollections\SystemCollectionManagerInterface;
use Sulu\Mcp\Application\AdminLink\AdminLinkResourceResolverInterface;
use Sulu\Mcp\Application\AdminLink\AdminLinkTarget;
use Sulu\Mcp\Application\Content\ContentTypeExtensionRegistry;
use Sulu\Mcp\Tests\Unit\Fixture\FakeContentTypeExtension;
use Sulu\Mcp\Tests\Unit\Fixture\FakeToolPermissionChecker;
use Sulu\Mcp\UserInterface\Mcp\Tool\AdminLink\AdminLinkGenerateTool;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[CoversClass(AdminLinkGenerateTool::class)]
final class AdminLinkGenerateToolTest extends TestCase
{
    use ProphecyTrait;

    private const RESOURCES = [
        'pages' => [
            'views' => ['detail' => 'sulu_page.page_edit_form'],
            'security_context' => 'sulu.webspaces.#webspace#',
            'security_class' => 'Sulu\\Page\\Domain\\Model\\Page',
        ],
        'tags' => ['views' => ['detail' => 'sulu_tag.edit_form']],
        'articles' => [
            'views' => ['detail' => 'sulu_article.article.edit_tabs_{group}'],
            'security_context' => 'sulu.article.articles',
        ],
        'media' => [
            'views' => ['detail' => 'sulu_media.form'],
            'security_context' => 'sulu.media.collections',
            'security_class' => Collection::class,
        ],
        'widgets' => ['views' => ['detail' => 'app.widget_edit']],
        'pages_versions' => [],
    ];

    /** @var ObjectProphecy<ResourceViewUrlGeneratorInterface> */
    private ObjectProphecy $generator;

    /** @var ObjectProphecy<MediaManagerInterface> */
    private ObjectProphecy $mediaManager;

    private FakeToolPermissionChecker $permissionChecker;

    protected function setUp(): void
    {
        if (!\interface_exists(ResourceViewUrlGeneratorInterface::class)) {
            self::markTestSkipped('Needs sulu/sulu 3.1 or later.');
        }

        $this->generator = $this->prophesize(ResourceViewUrlGeneratorInterface::class);
        $this->mediaManager = $this->prophesize(MediaManagerInterface::class);
        $this->permissionChecker = FakeToolPermissionChecker::grantingAll();
    }

    public function testReturnsTheAdminUrlOfTheDetailView(): void
    {
        $this->generator
            ->generate('tags', 'detail', ['id' => '7', 'locale' => 'de'], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('https://example.org/admin/#/de/tags/7');

        $result = $this->tool()->generateAdminLink('tags', '7', 'de');

        self::assertSame('https://example.org/admin/#/de/tags/7', $result['admin_url']);
        self::assertTrue($result['success']);
    }

    public function testPassesTheWebspaceAndChecksViewOnTheObjectOfAPage(): void
    {
        $this->generator
            ->generate('pages', 'detail', ['id' => 'abc', 'locale' => 'en', 'webspace' => 'sulu'], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('https://example.org/admin/#/sulu/pages/en/abc/details');

        $result = $this->tool()->generateAdminLink('pages', 'abc', 'en', 'sulu');

        self::assertSame('https://example.org/admin/#/sulu/pages/en/abc/details', $result['admin_url']);
        $call = $this->permissionChecker->calls()[0];
        self::assertSame('sulu.webspaces.sulu', $call['context']);
        self::assertSame(['view'], $call['permissions']);
        self::assertSame('abc', $call['objectId']);
    }

    public function testAPageWithoutWebspaceIsRefusedBeforeAnyPermissionCheck(): void
    {
        $result = $this->tool()->generateAdminLink('pages', 'abc', 'en');

        self::assertStringContainsString('"webspace"', $result['error']);
        self::assertSame([], $this->permissionChecker->calls());
        $this->generator->generate(Argument::cetera())->shouldNotHaveBeenCalled();
    }

    public function testUnknownResourceKeyListsTheKeysWithAView(): void
    {
        $result = $this->tool()->generateAdminLink('nope', '1', 'en');

        self::assertSame('No admin view exists for resource key "nope".', $result['error']);
        self::assertSame('Use one of: articles, media, pages, tags, widgets.', $result['hint']);
    }

    public function testResourceKeyThatTheGeneratorRejectsGivesTheSameError(): void
    {
        $this->generator->generate('tags', 'detail', Argument::cetera())->willThrow(new ResourceViewNotFoundException('tags', 'detail'));

        $result = $this->tool()->generateAdminLink('tags', '1', 'en');

        self::assertStringContainsString('No admin view exists', $result['error']);
    }

    public function testViewThatIsNotAvailableGivesAnError(): void
    {
        $this->generator->generate('tags', 'detail', Argument::cetera())->willThrow(new ViewNotFoundException('sulu_tag.edit_form'));

        $result = $this->tool()->generateAdminLink('tags', '1', 'en');

        self::assertStringContainsString('is not available', $result['error']);
    }

    public function testMissingViewParameterIsNamed(): void
    {
        $this->generator->generate('tags', 'detail', Argument::cetera())->willThrow(new ViewParameterNotFoundException('group', 'view'));

        $result = $this->tool()->generateAdminLink('tags', '1', 'en');

        self::assertStringContainsString('"group"', $result['error']);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function emptyArguments(): iterable
    {
        yield 'locale' => ['tags', '1', ''];
        yield 'resource id' => ['tags', '', 'en'];
        yield 'resource key' => ['', '1', 'en'];
    }

    #[DataProvider('emptyArguments')]
    public function testEmptyArgumentsAreRefused(string $resourceKey, string $resourceId, string $locale): void
    {
        $result = $this->tool()->generateAdminLink($resourceKey, $resourceId, $locale);

        self::assertStringContainsString('required', $result['error']);
        $this->generator->generate(Argument::cetera())->shouldNotHaveBeenCalled();
    }

    public function testMissingViewPermissionOnADeclaredContextIsDenied(): void
    {
        $this->permissionChecker->denyContext('sulu.webspaces.sulu');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('sulu.webspaces.sulu');

        try {
            $this->tool()->generateAdminLink('pages', 'abc', 'en', 'sulu');
        } finally {
            $this->generator->generate(Argument::cetera())->shouldNotHaveBeenCalled();
        }
    }

    public function testMissingViewPermissionOnAContentTypeExtensionIsDenied(): void
    {
        $this->permissionChecker->denyContext('sulu.widget.widgets');

        $this->expectException(ToolCallException::class);

        $this->tool()->generateAdminLink('widgets', '1', 'en');
    }

    public function testViewPermissionOnAContentTypeExtensionLetsTheLinkThrough(): void
    {
        $this->generator->generate('widgets', 'detail', Argument::cetera())->willReturn('https://example.org/admin/#/w');

        $result = $this->tool()->generateAdminLink('widgets', '1', 'en');

        self::assertSame('https://example.org/admin/#/w', $result['admin_url']);
    }

    public function testResolverPointsAVariantUuidAtItsParent(): void
    {
        $this->generator
            ->generate('tags', 'detail', ['id' => 'parent', 'locale' => 'en'], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('https://example.org/admin/#/en/tags/parent');

        $result = $this->tool([self::resolver('tags', 'variant', new AdminLinkTarget('tags', 'parent', '/variants'))])
            ->generateAdminLink('tags', 'variant', 'en');

        self::assertSame('https://example.org/admin/#/en/tags/parent/variants', $result['admin_url']);
        self::assertSame('parent', $result['resourceId']);
        self::assertStringContainsString('"variant"', $result['note']);
    }

    public function testResourceTheResolverIgnoresIsLinkedAsGiven(): void
    {
        $this->generator
            ->generate('tags', 'detail', ['id' => 'plain', 'locale' => 'en'], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('https://example.org/admin/#/en/tags/plain');

        $result = $this->tool([self::resolver('tags', 'variant', new AdminLinkTarget('tags', 'parent'))])
            ->generateAdminLink('tags', 'plain', 'en');

        self::assertSame('https://example.org/admin/#/en/tags/plain', $result['admin_url']);
        self::assertArrayNotHasKey('note', $result);
    }

    public function testUnknownEntityIsLinkedAsGivenBecauseTheToolDoesNotCheckExistence(): void
    {
        $this->generator
            ->generate('tags', 'detail', ['id' => 'missing', 'locale' => 'en'], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('https://example.org/admin/#/en/tags/missing');

        $result = $this->tool([self::resolver('tags', 'variant', null)])->generateAdminLink('tags', 'missing', 'en');

        self::assertSame('https://example.org/admin/#/en/tags/missing', $result['admin_url']);
    }

    public function testViewPermissionIsCheckedOnTheResolvedTarget(): void
    {
        $this->permissionChecker->denyContext('sulu.webspaces.sulu');

        $this->expectException(ToolCallException::class);

        $this->tool([self::resolver('widgets', 'variant', new AdminLinkTarget('pages', 'parent'))])
            ->generateAdminLink('widgets', 'variant', 'en', 'sulu');
    }

    public function testArticleIsCheckedAgainstTheGroupOfTheEntityNotTheBaseContext(): void
    {
        $this->permissionChecker = FakeToolPermissionChecker::grantingAll()->grantContext('sulu.article.articles_news');
        $this->generator->generate('articles', 'detail', Argument::cetera())->willReturn('https://example.org/admin/#/articles/a');

        $result = $this->tool([], $this->articles('sulu.article.articles_news'))->generateAdminLink('articles', 'a', 'en');

        self::assertTrue($result['success']);
        self::assertSame('sulu.article.articles_news', $this->permissionChecker->calls()[0]['context']);
    }

    public function testUserWithTheBaseGroupOnlyGetsNoLinkForAnotherGroup(): void
    {
        $this->permissionChecker = FakeToolPermissionChecker::grantingAll()->grantContext('sulu.article.articles');

        try {
            $this->tool([], $this->articles('sulu.article.articles_news'))->generateAdminLink('articles', 'a', 'en');
            self::fail('Expected a permission error.');
        } catch (ToolCallException) {
            $this->generator->generate(Argument::cetera())->shouldNotHaveBeenCalled();
        }
    }

    public function testMissingArticleIsAnErrorBecauseItsGroupIsUnknown(): void
    {
        $extensions = new ContentTypeExtensionRegistry([new FakeContentTypeExtension('article', 'articles', 'sulu.article.articles')]);

        $result = $this->tool([], $extensions)->generateAdminLink('articles', 'gone', 'en');

        self::assertStringContainsString('was not found', $result['error']);
        $this->generator->generate(Argument::cetera())->shouldNotHaveBeenCalled();
    }

    public function testMediaIsCheckedAgainstTheCollectionOfTheMediaNotTheMediaId(): void
    {
        $this->mediaManager->getById(77, 'en')->willReturn($this->mediaIn(5));
        $this->generator->generate('media', 'detail', Argument::cetera())->willReturn('https://example.org/admin/#/media/77');

        $result = $this->tool()->generateAdminLink('media', '77', 'en');

        self::assertTrue($result['success']);
        $call = $this->permissionChecker->calls()[0];
        self::assertSame('sulu.media.collections', $call['context']);
        self::assertSame(Collection::class, $call['objectType']);
        self::assertSame(5, $call['objectId']);
    }

    public function testMediaInASystemCollectionNeedsTheSystemCollectionPermission(): void
    {
        $this->permissionChecker->denyContext('sulu.media.system_collections');
        $this->mediaManager->getById(77, 'en')->willReturn($this->mediaIn(5, SystemCollectionManagerInterface::COLLECTION_TYPE));

        $this->expectException(ToolCallException::class);

        $this->tool()->generateAdminLink('media', '77', 'en');
    }

    public function testMediaWithoutViewOnItsCollectionIsDenied(): void
    {
        $this->permissionChecker->grantWhen(static fn (string $context, string $permission, ?string $locale, ?string $type, mixed $id): bool => 6 === $id);
        $this->mediaManager->getById(77, 'en')->willReturn($this->mediaIn(5));

        $this->expectException(ToolCallException::class);

        $this->tool()->generateAdminLink('media', '77', 'en');
    }

    public function testMissingMediaIsAnError(): void
    {
        $this->mediaManager->getById(78, 'en')->willThrow(new MediaNotFoundException(78));

        $result = $this->tool()->generateAdminLink('media', '78', 'en');

        self::assertSame('Media not found: 78', $result['error']);
    }

    public function testNonNumericMediaIdIsAnError(): void
    {
        $result = $this->tool()->generateAdminLink('media', 'abc', 'en');

        self::assertStringContainsString('not a number', $result['error']);
        $this->mediaManager->getById(Argument::cetera())->shouldNotHaveBeenCalled();
    }

    public function testResolverDoesNotRunWhenTheRequestedResourceIsDenied(): void
    {
        $this->permissionChecker->denyContext('sulu.widget.widgets');
        $resolver = new class() implements AdminLinkResourceResolverInterface {
            public int $calls = 0;

            public function resolve(string $resourceKey, string $resourceId, string $locale): ?AdminLinkTarget
            {
                ++$this->calls;

                return new AdminLinkTarget('tags', 'parent');
            }
        };

        try {
            $this->tool([$resolver])->generateAdminLink('widgets', 'variant', 'en');
            self::fail('Expected a permission error.');
        } catch (ToolCallException) {
            self::assertSame(0, $resolver->calls);
        }
    }

    public function testResolverDoesNotRunForAnUnknownRequestedKey(): void
    {
        $resolver = new class() implements AdminLinkResourceResolverInterface {
            public int $calls = 0;

            public function resolve(string $resourceKey, string $resourceId, string $locale): ?AdminLinkTarget
            {
                ++$this->calls;

                return new AdminLinkTarget('tags', 'parent');
            }
        };

        $result = $this->tool([$resolver])->generateAdminLink('nope', '1', 'en');

        self::assertStringContainsString('No admin view exists', $result['error']);
        self::assertSame(0, $resolver->calls);
    }

    public function testBothTheRequestedAndTheResolvedResourceAreAuthorized(): void
    {
        $this->generator->generate('pages', 'detail', Argument::cetera())->willReturn('https://example.org/admin/#/p');

        $this->tool([self::resolver('widgets', 'variant', new AdminLinkTarget('pages', 'parent'))])
            ->generateAdminLink('widgets', 'variant', 'en', 'sulu');

        $contexts = \array_column($this->permissionChecker->calls(), 'context');
        self::assertSame(['sulu.widget.widgets', 'sulu.webspaces.sulu'], $contexts);
    }

    public function testToolIsReadOnlyAndNamed(): void
    {
        $attribute = (new \ReflectionMethod(AdminLinkGenerateTool::class, 'generateAdminLink'))
            ->getAttributes(McpTool::class)[0]->newInstance();

        self::assertSame('sulu_admin_link_generate', $attribute->name);
        self::assertTrue($attribute->annotations?->readOnlyHint);
        self::assertStringContainsString('Never build an admin URL yourself', (string) $attribute->description);
    }

    private function articles(string $groupContext): ContentTypeExtensionRegistry
    {
        return new ContentTypeExtensionRegistry([new FakeContentTypeExtension('article', 'articles', $groupContext, new \stdClass())]);
    }

    /**
     * @return Media
     */
    private function mediaIn(int $collectionId, ?string $typeKey = null): object
    {
        $type = new CollectionType();
        $type->setKey($typeKey);

        /** @var ObjectProphecy<Collection> $collection */
        $collection = $this->prophesize(Collection::class);
        $collection->getId()->willReturn($collectionId);
        $collection->getType()->willReturn($type);

        $entity = new MediaEntity();
        $entity->setCollection($collection->reveal());

        /** @var ObjectProphecy<Media> $media */
        $media = $this->prophesize(Media::class);
        $media->getEntity()->willReturn($entity);

        return $media->reveal();
    }

    private static function resolver(string $key, string $id, ?AdminLinkTarget $target): AdminLinkResourceResolverInterface
    {
        return new class($key, $id, $target) implements AdminLinkResourceResolverInterface {
            public function __construct(private string $key, private string $id, private ?AdminLinkTarget $target)
            {
            }

            public function resolve(string $resourceKey, string $resourceId, string $locale): ?AdminLinkTarget
            {
                return $this->key === $resourceKey && $this->id === $resourceId ? $this->target : null;
            }
        };
    }

    /**
     * @param list<AdminLinkResourceResolverInterface> $resolvers
     */
    private function tool(array $resolvers = [], ?ContentTypeExtensionRegistry $extensions = null): AdminLinkGenerateTool
    {
        return new AdminLinkGenerateTool(
            $this->generator->reveal(),
            $this->permissionChecker,
            $extensions ?? new ContentTypeExtensionRegistry([new FakeContentTypeExtension(draft: new \stdClass())]),
            $this->mediaManager->reveal(),
            self::RESOURCES,
            $resolvers,
        );
    }
}
