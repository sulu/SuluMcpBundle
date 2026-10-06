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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\Content;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Article\Application\Message\RemoveArticleMessage;
use Sulu\Article\Domain\Model\Article;
use Sulu\Article\Domain\Model\ArticleDimensionContent;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormGroup;
use Sulu\Bundle\SecurityBundle\System\SystemStoreInterface;
use Sulu\Component\Security\Authorization\AccessControl\AccessControlRepositoryInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Application\Security\ContentSecurityContextResolver;
use Sulu\Mcp\Application\Security\PageDescendantPermissionChecker;
use Sulu\Mcp\Infrastructure\Sulu\Security\SnippetSecurityContextResolver;
use Sulu\Mcp\Tests\Application\TestBundle\Metadata\TestGroupProvider;
use Sulu\Mcp\Tests\Unit\Fixture\ContentTypes;
use Sulu\Mcp\Tests\Unit\Fixture\FakeToolPermissionChecker;
use Sulu\Mcp\Tests\Unit\Fixture\TestUser;
use Sulu\Mcp\UserInterface\Mcp\Tool\Content\ContentDeleteTool;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Page\Application\Message\RemovePageMessage;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Sulu\Snippet\Application\Message\RemoveSnippetMessage;
use Sulu\Snippet\Domain\Model\Snippet;
use Sulu\Snippet\Domain\Model\SnippetDimensionContent;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[CoversClass(ContentDeleteTool::class)]
final class ContentDeleteToolTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<MessageBusInterface> */
    private ObjectProphecy $messageBus;

    /** @var ObjectProphecy<PageRepositoryInterface> */
    private ObjectProphecy $pageRepository;

    /** @var ObjectProphecy<ArticleRepositoryInterface> */
    private ObjectProphecy $articleRepository;

    /** @var ObjectProphecy<SnippetRepositoryInterface> */
    private ObjectProphecy $snippetRepository;

    private FakeToolPermissionChecker $permissionChecker;

    /** @var ObjectProphecy<AccessControlRepositoryInterface> */
    private ObjectProphecy $accessControlRepository;

    /** @var ObjectProphecy<Security> */
    private ObjectProphecy $security;

    private ContentDeleteTool $tool;

    protected function setUp(): void
    {
        $this->messageBus = $this->prophesize(MessageBusInterface::class);
        $this->pageRepository = $this->prophesize(PageRepositoryInterface::class);
        $this->articleRepository = $this->prophesize(ArticleRepositoryInterface::class);
        $this->snippetRepository = $this->prophesize(SnippetRepositoryInterface::class);
        $this->permissionChecker = FakeToolPermissionChecker::grantingAll();
        $this->accessControlRepository = $this->prophesize(AccessControlRepositoryInterface::class);
        $this->security = $this->prophesize(Security::class);
        $groupProvider = new TestGroupProvider([]);
        $systemStore = $this->prophesize(SystemStoreInterface::class);
        $systemStore->getSystem(Argument::cetera())->willReturn('Sulu');

        $pageDescendantPermissionChecker = new PageDescendantPermissionChecker(
            $this->pageRepository->reveal(),
            $this->accessControlRepository->reveal(),
            $systemStore->reveal(),
            $this->security->reveal(),
            [PermissionTypes::DELETE => 8],
        );

        $this->tool = new ContentDeleteTool(
            $this->messageBus->reveal(),
            ContentTypes::resolver($this->pageRepository->reveal(), $this->articleRepository->reveal(), $this->snippetRepository->reveal(), $groupProvider, $pageDescendantPermissionChecker),
            $this->permissionChecker,
            ContentTypes::securityResolver($groupProvider),
        );
    }

    public function testDeletePageDispatchesRemovePageMessageWithFlushStamp(): void
    {
        $this->setupEntity('pages');

        $this->security->getUser(Argument::cetera())->willReturn(new TestUser());
        $this->pageRepository->findDescendantIdsById(Argument::cetera())->willReturn([]);

        $captured = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use (&$captured) {
                $captured = $args[0];

                return $args[0]->with(new HandledStamp(null, 'handler'));
            });

        $result = $this->tool->deleteContent('pages', 'uuid-1', 'en', true);

        $this->assertInstanceOf(Envelope::class, $captured);
        $this->assertInstanceOf(RemovePageMessage::class, $captured->getMessage());
        $this->assertArrayHasKey(EnableFlushStamp::class, $captured->all());
        $this->assertSame(['success' => true, 'resourceKey' => 'pages', 'uuid' => 'uuid-1', 'deleted' => true], $result);
    }

    public function testDeleteSnippetDispatchesRemoveSnippetMessage(): void
    {
        $this->setupEntity('snippets');

        $captured = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use (&$captured) {
                $captured = $args[0];

                return $args[0]->with(new HandledStamp(null, 'handler'));
            });

        $result = $this->tool->deleteContent('snippets', 'uuid-2', 'en');

        $this->assertInstanceOf(Envelope::class, $captured);
        $this->assertInstanceOf(RemoveSnippetMessage::class, $captured->getMessage());
        $this->assertTrue($result['deleted']);
    }

    public function testDeleteArticleDispatchesRemoveArticleMessage(): void
    {
        $this->setupEntity('articles');

        $captured = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use (&$captured) {
                $captured = $args[0];

                return $args[0]->with(new HandledStamp(null, 'handler'));
            });

        $result = $this->tool->deleteContent('articles', 'uuid-3', 'en');

        $this->assertInstanceOf(Envelope::class, $captured);
        $this->assertInstanceOf(RemoveArticleMessage::class, $captured->getMessage());
        $this->assertTrue($result['deleted']);
    }

    public function testUnsupportedTypeReturnsErrorWithoutDispatch(): void
    {
        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->deleteContent('contact', 'uuid-1', 'en');

        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('success', $result);
    }

    public function testEntityNotFoundReturnsErrorWithoutDispatch(): void
    {
        $this->articleRepository->getOneBy(Argument::cetera())->willThrow(new \RuntimeException('not found'));
        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->deleteContent('articles', 'missing-uuid', 'en');

        $this->assertArrayHasKey('error', $result);
    }

    public function testErrorOnException(): void
    {
        $this->setupEntity('articles');
        $this->messageBus->dispatch(Argument::cetera())->willThrow(new \RuntimeException('boom'));

        $result = $this->tool->deleteContent('articles', 'uuid-1', 'en');

        $this->assertStringContainsString('boom', $result['error']);
    }

    public function testMethodHasMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionMethod(ContentDeleteTool::class, 'deleteContent'))->getAttributes(McpTool::class);
        $this->assertCount(1, $attributes);
        $this->assertSame('sulu_content_delete', $attributes[0]->newInstance()->name);
    }

    public function testDeleteContentThrowsToolCallExceptionWhenPermissionDenied(): void
    {
        $this->setupEntity('pages');

        $this->permissionChecker->denyAll();

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(ToolCallException::class);

        $this->tool->deleteContent('pages', 'uuid-1', 'en');
    }

    public function testDeletePageThrowsToolCallExceptionWhenDescendantPermissionDenied(): void
    {
        $this->setupEntity('pages');

        $this->security->getUser(Argument::cetera())->willReturn(new TestUser());
        $this->pageRepository->findDescendantIdsById(Argument::cetera())->willReturn(['child-1', 'child-2']);
        // Only child-1 is granted DELETE — child-2 is missing, so the gate must trip.
        $this->accessControlRepository->findIdsWithGrantedPermissions(Argument::cetera())->willReturn(['child-1']);

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(ToolCallException::class);

        $this->tool->deleteContent('pages', 'uuid-1', 'en', true);
    }

    public function testDeleteContentPassesConcretePageClassAsObjectTypeForBothChecks(): void
    {
        // Regression guard: Sulu stores per-page ACLs under the concrete Page class
        // (getSecuredClass()), not PageInterface — the interface matches no ACL row and
        // falls back to the webspace grant, for both the EDIT and DELETE check.
        $this->setupEntity('pages');

        $this->security->getUser(Argument::cetera())->willReturn(new TestUser());
        $this->pageRepository->findDescendantIdsById(Argument::cetera())->willReturn([]);
        $this->messageBus->dispatch(Argument::cetera())
            ->will(fn (array $args) => $args[0]->with(new HandledStamp(null, 'handler')));

        $result = $this->tool->deleteContent('pages', 'uuid-1', 'en');

        $this->assertTrue($result['deleted']);
        $this->assertSame(
            [
                [[PermissionTypes::EDIT, PermissionTypes::DELETE], Page::class, 'uuid-1'],
            ],
            \array_map(static fn (array $c): array => [$c['permissions'], $c['objectType'], $c['objectId']], $this->permissionChecker->calls()),
        );
    }

    public function testDeletePageDispatchesWhenAllDescendantsGranted(): void
    {
        $this->setupEntity('pages');

        $this->security->getUser(Argument::cetera())->willReturn(new TestUser());
        $this->pageRepository->findDescendantIdsById(Argument::cetera())->willReturn(['child-1', 'child-2']);
        $this->accessControlRepository->findIdsWithGrantedPermissions(Argument::cetera())->willReturn(['child-1', 'child-2']);

        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(fn (array $args) => $args[0]->with(new HandledStamp(null, 'handler')));

        $result = $this->tool->deleteContent('pages', 'uuid-1', 'en', true);

        $this->assertTrue($result['deleted']);
    }

    public function testDeleteSnippetOfAGroupTheRoleHolds(): void
    {
        $this->useTwoSnippetGroups();
        $this->setupSnippetWithTemplate('promo');
        $this->permissionChecker->grantingNoneExcept()->grantContext('sulu.snippet.snippets_marketing');

        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(fn (array $args) => $args[0]->with(new HandledStamp(null, 'handler')));

        $result = $this->tool->deleteContent('snippets', 'uuid-1', 'en');

        $this->assertTrue($result['deleted']);
    }

    public function testDeleteSnippetIsDeniedWithoutItsGroup(): void
    {
        $this->useTwoSnippetGroups();
        $this->setupSnippetWithTemplate('promo');
        $this->permissionChecker->grantingNoneExcept()->grantContext('sulu.snippet.snippets');

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('security context "sulu.snippet.snippets_marketing"');

        $this->tool->deleteContent('snippets', 'uuid-1', 'en');
    }

    public function testDeleteSnippetIsDeniedWithoutTheGroupOfAnotherLocale(): void
    {
        // the reported case: "de" is in the marketing group the role holds, "en" in the default group it does not
        $this->useTwoSnippetGroups();
        $this->setupSnippetWithLocales(['en' => 'default', 'de' => 'promo']);
        $this->permissionChecker->grantingNoneExcept()->grantContext('sulu.snippet.snippets_marketing');

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('security context "sulu.snippet.snippets"');

        $this->tool->deleteContent('snippets', 'uuid-1', 'de');
    }

    public function testDeleteSnippetChecksTheGroupOfEveryLocale(): void
    {
        $this->useTwoSnippetGroups();
        $this->setupSnippetWithLocales(['en' => 'default', 'de' => 'promo']);
        $this->permissionChecker->grantingNoneExcept()
            ->grantContext('sulu.snippet.snippets')
            ->grantContext('sulu.snippet.snippets_marketing');

        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(fn (array $args) => $args[0]->with(new HandledStamp(null, 'handler')));

        $result = $this->tool->deleteContent('snippets', 'uuid-1', 'de');

        $this->assertTrue($result['deleted']);
        $this->assertEqualsCanonicalizing(
            [['sulu.snippet.snippets_marketing', 'de'], ['sulu.snippet.snippets', 'en']],
            \array_map(static fn (array $call): array => [$call['context'], $call['locale']], $this->permissionChecker->calls()),
        );
    }

    /**
     * Rebuilds the tool over a two-group install: `default` (template "default") and
     * `marketing` (template "promo").
     */
    private function useTwoSnippetGroups(): void
    {
        $contentTypeResolver = ContentTypes::snippetResolver($this->snippetRepository->reveal(), new SnippetSecurityContextResolver(new TestGroupProvider([
            (new FormGroup('default', 'Default'))->withTemplate('default'),
            (new FormGroup('marketing', 'Marketing'))->withTemplate('promo'),
        ]), true));

        $this->tool = new ContentDeleteTool(
            $this->messageBus->reveal(),
            $contentTypeResolver,
            $this->permissionChecker,
            new ContentSecurityContextResolver($contentTypeResolver),
        );
    }

    private function setupSnippetWithTemplate(string $templateKey): void
    {
        $snippet = new Snippet('uuid-1');
        $dimensionContent = new SnippetDimensionContent($snippet);
        $dimensionContent->setLocale('en');
        $dimensionContent->setTemplateKey($templateKey);
        $snippet->addDimensionContent($dimensionContent);

        $this->snippetRepository->getOneBy(Argument::cetera())->willReturn($snippet);
    }

    /**
     * @param array<string, string> $templateKeys template key per locale
     */
    private function setupSnippetWithLocales(array $templateKeys): void
    {
        $snippet = new Snippet('uuid-1');
        $unlocalized = new SnippetDimensionContent($snippet);
        $snippet->addDimensionContent($unlocalized);

        foreach ($templateKeys as $locale => $templateKey) {
            $unlocalized->addAvailableLocale($locale);
            $dimensionContent = new SnippetDimensionContent($snippet);
            $dimensionContent->setLocale($locale);
            $dimensionContent->setTemplateKey($templateKey);
            $snippet->addDimensionContent($dimensionContent);
        }

        $this->snippetRepository->getOneBy(Argument::cetera())->willReturn($snippet);
    }

    private function setupEntity(string $type): void
    {
        $entity = match ($type) {
            'articles' => new Article('uuid-1'),
            'snippets' => new Snippet('uuid-1'),
            default => (new Page('uuid-1'))->setWebspaceKey('example'),
        };

        match ($type) {
            'articles' => $this->articleRepository->getOneBy(Argument::cetera())->willReturn($entity),
            'snippets' => $this->snippetRepository->getOneBy(Argument::cetera())->willReturn($entity),
            default => $this->pageRepository->getOneBy(Argument::cetera())->willReturn($entity),
        };

        if ('articles' === $type) {
            $dimensionContent = new ArticleDimensionContent($entity);
            $dimensionContent->setTemplateKey('default');
            $entity->addDimensionContent($dimensionContent);
        }
    }
}
