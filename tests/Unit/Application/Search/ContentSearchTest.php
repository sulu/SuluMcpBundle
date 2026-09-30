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

namespace Sulu\Mcp\Tests\Unit\Application\Search;

use CmsIg\Seal\Adapter\SearcherInterface;
use CmsIg\Seal\EngineInterface;
use CmsIg\Seal\Schema\Field\IdentifierField;
use CmsIg\Seal\Schema\Index;
use CmsIg\Seal\Schema\Schema;
use CmsIg\Seal\Search\Condition\EqualCondition;
use CmsIg\Seal\Search\Condition\InCondition;
use CmsIg\Seal\Search\Result;
use CmsIg\Seal\Search\Search;
use CmsIg\Seal\Search\SearchBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceCollection;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Component\Webspace\Webspace;
use Sulu\Mcp\Application\Content\ContentTypeExtensionRegistry;
use Sulu\Mcp\Application\Search\ContentSearch;
use Sulu\Mcp\Application\Search\WebsiteSearch;
use Sulu\Mcp\Application\Security\ToolPermissionChecker;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Domain\Content\ContentTypeExtensionInterface;
use Sulu\Mcp\Tests\Application\TestBundle\Metadata\TestGroupProvider;
use Sulu\Mcp\Tests\Unit\Fixture\ContentTypes;
use Sulu\Mcp\Tests\Unit\Fixture\FakeContentTypeExtension;
use Sulu\Mcp\Tests\Unit\Fixture\TestUser;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;

#[CoversClass(ContentSearch::class)]
final class ContentSearchTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<EngineInterface> */
    private ObjectProphecy $engine;

    /** @var ObjectProphecy<SearcherInterface> */
    private ObjectProphecy $searcher;

    /** @var ObjectProphecy<ToolPermissionCheckerInterface> */
    private ObjectProphecy $permissionChecker;

    private ContentSearch $contentSearch;

    protected function setUp(): void
    {
        $this->engine = $this->prophesize(EngineInterface::class);
        $this->searcher = $this->prophesize(SearcherInterface::class);
        $this->permissionChecker = $this->prophesize(ToolPermissionCheckerInterface::class);
        $this->permissionChecker->has('sulu.article.articles', PermissionTypes::VIEW, 'en')->willReturn(true);
        $this->contentSearch = new ContentSearch(new WebsiteSearch($this->engine->reveal()), $this->webspaceResolver(['example']), $this->permissionChecker->reveal(), $this->registry());
    }

    /**
     * Real WebspacePermissionResolver (final) over a mocked WebspaceManagerInterface
     * and a real ToolPermissionChecker driven by a mocked SecurityCheckerInterface.
     */
    /**
     * @param list<ContentTypeExtensionInterface> $extensions
     */
    private function registry(array $extensions = []): ContentTypeExtensionRegistry
    {
        return ContentTypes::registry(
            $this->prophesize(PageRepositoryInterface::class)->reveal(),
            $this->prophesize(ArticleRepositoryInterface::class)->reveal(),
            TestGroupProvider::singleGroup(),
            null,
            $extensions,
        );
    }

    private function webspaceResolver(array $grantedWebspaceKeys): WebspacePermissionResolver
    {
        $webspaces = [];
        foreach ($grantedWebspaceKeys as $key) {
            $webspace = new Webspace();
            $webspace->setKey($key);
            $webspaces[$key] = $webspace;
        }

        $webspaceManager = $this->prophesize(WebspaceManagerInterface::class);
        $webspaceManager->getWebspaceCollection()->willReturn(new WebspaceCollection($webspaces));

        $securityChecker = $this->prophesize(SecurityCheckerInterface::class);
        $securityChecker->hasPermission(Argument::cetera())->willReturn(true);

        $tokenStorage = (new TestUser())->inTokenStorage();

        return new WebspacePermissionResolver($webspaceManager->reveal(), new ToolPermissionChecker($securityChecker->reveal(), $tokenStorage));
    }

    private function createSearchBuilder(): SearchBuilder
    {
        $identifierField = new IdentifierField('id');
        $index = new Index('website', ['id' => $identifierField]);
        $schema = new Schema(['website' => $index]);

        return (new SearchBuilder($schema, $this->searcher->reveal()))->index('website');
    }

    private function createEmptyResult(): Result
    {
        return Result::createEmpty();
    }

    public function testTypeArticleIsMappedToPluralResourceKey(): void
    {
        $builder = $this->createSearchBuilder();

        $this->engine->createSearchBuilder('website')->willReturn($builder);

        $this->searcher
            ->search(Argument::that(function(Search $search): bool {
                foreach ($search->filters as $filter) {
                    if ($filter instanceof EqualCondition
                        && 'resourceKey' === $filter->field
                        && 'articles' === $filter->value
                    ) {
                        return true;
                    }
                }

                return false;
            }))
            ->shouldBeCalledOnce()
            ->willReturn($this->createEmptyResult());

        $result = $this->contentSearch->search('hello', 'en', null, 'articles');

        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('total', $result);
    }

    public function testTypePageIsMappedToPluralResourceKey(): void
    {
        $builder = $this->createSearchBuilder();

        $this->engine->createSearchBuilder('website')->willReturn($builder);

        $this->searcher
            ->search(Argument::that(function(Search $search): bool {
                foreach ($search->filters as $filter) {
                    if ($filter instanceof EqualCondition
                        && 'resourceKey' === $filter->field
                        && 'pages' === $filter->value
                    ) {
                        return true;
                    }
                }

                return false;
            }))
            ->shouldBeCalledOnce()
            ->willReturn($this->createEmptyResult());

        $this->contentSearch->search('hello', 'en', null, 'pages');
    }

    public function testUnknownTypeIsRejected(): void
    {
        $this->engine->createSearchBuilder(Argument::cetera())->shouldNotBeCalled();

        $result = $this->contentSearch->search('hello', 'en', null, 'custom_type');

        $this->assertSame(
            [
                'error' => 'Unsupported content type "custom_type".',
                'hint' => 'Supported: pages, articles.',
            ],
            $result,
        );
    }

    public function testSearchEngineExceptionReturnsStructuredError(): void
    {
        $this->engine
            ->createSearchBuilder(Argument::cetera())
            ->willThrow(new \RuntimeException('Search engine unavailable'));

        $result = $this->contentSearch->search('hello', 'en');

        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('hint', $result);
        $this->assertStringContainsString('Content search failed', $result['error']);
        $this->assertStringContainsString('Search engine unavailable', $result['error']);
        $this->assertArrayNotHasKey('results', $result);
    }

    public function testNullTypeAppliesTheBuiltinResourceKeyWhitelist(): void
    {
        $builder = $this->createSearchBuilder();

        $this->engine->createSearchBuilder(Argument::cetera())->willReturn($builder);

        $this->searcher
            ->search(Argument::that(function(Search $search): bool {
                foreach ($search->filters as $filter) {
                    if ($filter instanceof InCondition
                        && 'resourceKey' === $filter->field
                        && ['pages', 'articles'] === $filter->values
                    ) {
                        return true;
                    }
                }

                return false;
            }))
            ->shouldBeCalledOnce()
            ->willReturn($this->createEmptyResult());

        $this->contentSearch->search('hello', 'en');
    }

    public function testSearchReturnsEmptyResultsWhenNoWebspaceIsPermitted(): void
    {
        $contentSearch = new ContentSearch(new WebsiteSearch($this->engine->reveal()), $this->webspaceResolver([]), $this->permissionChecker->reveal(), $this->registry());

        $this->engine->createSearchBuilder(Argument::cetera())->shouldNotBeCalled();

        $result = $contentSearch->search('hello', 'en');

        $this->assertSame(
            ['results' => [], 'total' => 0, 'hint' => 'No webspaces are readable with your permissions.'],
            $result,
        );
    }

    public function testSearchReturnsEmptyResultsWhenRequestedWebspaceIsNotPermitted(): void
    {
        $contentSearch = new ContentSearch(new WebsiteSearch($this->engine->reveal()), $this->webspaceResolver(['example']), $this->permissionChecker->reveal(), $this->registry());

        $this->engine->createSearchBuilder(Argument::cetera())->shouldNotBeCalled();

        $result = $contentSearch->search('hello', 'en', 'other');

        $this->assertSame(
            ['results' => [], 'total' => 0, 'hint' => 'Webspace "other" is not readable with your permissions.'],
            $result,
        );
    }

    public function testSearchFiltersByPermittedWebspaces(): void
    {
        $builder = $this->createSearchBuilder();

        $contentSearch = new ContentSearch(new WebsiteSearch($this->engine->reveal()), $this->webspaceResolver(['example', 'blog']), $this->permissionChecker->reveal(), $this->registry());

        $this->engine->createSearchBuilder('website')->willReturn($builder);

        $this->searcher
            ->search(Argument::that(function(Search $search): bool {
                foreach ($search->filters as $filter) {
                    if ($filter instanceof InCondition
                        && 'webspaces' === $filter->field
                        && ['example', 'blog'] === $filter->values
                    ) {
                        return true;
                    }
                }

                return false;
            }))
            ->shouldBeCalledOnce()
            ->willReturn($this->createEmptyResult());

        $contentSearch->search('hello', 'en');
    }

    public function testSearchIntersectsRequestedWebspaceWithPermittedSet(): void
    {
        $builder = $this->createSearchBuilder();

        $contentSearch = new ContentSearch(new WebsiteSearch($this->engine->reveal()), $this->webspaceResolver(['example', 'blog']), $this->permissionChecker->reveal(), $this->registry());

        $this->engine->createSearchBuilder('website')->willReturn($builder);

        $this->searcher
            ->search(Argument::that(function(Search $search): bool {
                foreach ($search->filters as $filter) {
                    if ($filter instanceof InCondition
                        && 'webspaces' === $filter->field
                        && ['example'] === $filter->values
                    ) {
                        return true;
                    }
                }

                return false;
            }))
            ->shouldBeCalledOnce()
            ->willReturn($this->createEmptyResult());

        $contentSearch->search('hello', 'en', 'example');
    }

    public function testUntypedSearchExcludesARegisteredExtensionWithoutItsPermission(): void
    {
        $builder = $this->createSearchBuilder();

        $this->engine->createSearchBuilder('website')->willReturn($builder);
        $this->permissionChecker->has('sulu.widget.widgets', PermissionTypes::VIEW, 'en')->willReturn(false);

        $contentSearch = new ContentSearch(new WebsiteSearch($this->engine->reveal()), $this->webspaceResolver(['example']), $this->permissionChecker->reveal(), $this->registry([new FakeContentTypeExtension()]));

        $this->searcher
            ->search(Argument::that(function(Search $search): bool {
                foreach ($search->filters as $filter) {
                    if ($filter instanceof InCondition
                        && 'resourceKey' === $filter->field
                        && ['pages', 'articles'] === $filter->values
                    ) {
                        return true;
                    }
                }

                return false;
            }))
            ->shouldBeCalledOnce()
            ->willReturn($this->createEmptyResult());

        $contentSearch->search('hello', 'en');
    }

    public function testUntypedSearchIncludesARegisteredExtensionWithItsPermission(): void
    {
        $builder = $this->createSearchBuilder();

        $this->engine->createSearchBuilder('website')->willReturn($builder);
        $this->permissionChecker->has('sulu.widget.widgets', PermissionTypes::VIEW, 'en')->willReturn(true);

        $contentSearch = new ContentSearch(new WebsiteSearch($this->engine->reveal()), $this->webspaceResolver(['example']), $this->permissionChecker->reveal(), $this->registry([new FakeContentTypeExtension()]));

        $this->searcher
            ->search(Argument::that(function(Search $search): bool {
                foreach ($search->filters as $filter) {
                    if ($filter instanceof InCondition
                        && 'resourceKey' === $filter->field
                        && ['pages', 'articles', 'widgets'] === $filter->values
                    ) {
                        return true;
                    }
                }

                return false;
            }))
            ->shouldBeCalledOnce()
            ->willReturn($this->createEmptyResult());

        $contentSearch->search('hello', 'en');
    }

    public function testTypeForAnUnregisteredResourceKeyIsRejected(): void
    {
        $this->engine->createSearchBuilder(Argument::cetera())->shouldNotBeCalled();

        $result = $this->contentSearch->search('hello', 'en', null, 'widgets');

        $this->assertSame(
            [
                'error' => 'Unsupported content type "widgets".',
                'hint' => 'Supported: pages, articles.',
            ],
            $result,
        );
    }

    public function testTypeForSnippetsIsRejectedBecauseTheyAreNotSearchable(): void
    {
        $this->engine->createSearchBuilder(Argument::cetera())->shouldNotBeCalled();

        $result = $this->contentSearch->search('hello', 'en', null, 'snippets');

        $this->assertSame(
            [
                'error' => 'Unsupported content type "snippets".',
                'hint' => 'Supported: pages, articles.',
            ],
            $result,
        );
    }

    public function testTypeForARegisteredExtensionIsDeniedWithoutItsPermission(): void
    {
        $this->permissionChecker->has('sulu.widget.widgets', PermissionTypes::VIEW, 'en')->willReturn(false);

        $contentSearch = new ContentSearch(new WebsiteSearch($this->engine->reveal()), $this->webspaceResolver(['example']), $this->permissionChecker->reveal(), $this->registry([new FakeContentTypeExtension()]));

        $this->engine->createSearchBuilder(Argument::cetera())->shouldNotBeCalled();

        $result = $contentSearch->search('hello', 'en', null, 'widgets');

        $this->assertSame(
            [
                'error' => 'Permission denied: no accessible security context grants the required permissions.',
                'hint' => 'Requires VIEW on "sulu.widget.widgets".',
            ],
            $result,
        );
    }

    public function testTypeForARegisteredExtensionSearchesWithItsPermission(): void
    {
        $builder = $this->createSearchBuilder();

        $this->engine->createSearchBuilder('website')->willReturn($builder);
        $this->permissionChecker->has('sulu.widget.widgets', PermissionTypes::VIEW, 'en')->willReturn(true);

        $contentSearch = new ContentSearch(new WebsiteSearch($this->engine->reveal()), $this->webspaceResolver(['example']), $this->permissionChecker->reveal(), $this->registry([new FakeContentTypeExtension()]));

        $this->searcher
            ->search(Argument::that(function(Search $search): bool {
                foreach ($search->filters as $filter) {
                    if ($filter instanceof EqualCondition
                        && 'resourceKey' === $filter->field
                        && 'widgets' === $filter->value
                    ) {
                        return true;
                    }
                }

                return false;
            }))
            ->shouldBeCalledOnce()
            ->willReturn($this->createEmptyResult());

        $contentSearch->search('hello', 'en', null, 'widgets');
    }

    public function testTypeArticleIsDeniedWithoutArticlePermission(): void
    {
        $this->permissionChecker->has('sulu.article.articles', PermissionTypes::VIEW, 'en')->willReturn(false);

        $this->engine->createSearchBuilder(Argument::cetera())->shouldNotBeCalled();

        $result = $this->contentSearch->search('hello', 'en', null, 'articles');

        $this->assertSame(
            [
                'error' => 'Permission denied: no accessible security context grants the required permissions.',
                'hint' => 'Requires VIEW on "sulu.article.articles".',
            ],
            $result,
        );
    }

    public function testUntypedSearchExcludesArticlesWithoutArticlePermission(): void
    {
        $builder = $this->createSearchBuilder();

        $this->engine->createSearchBuilder('website')->willReturn($builder);
        $this->permissionChecker->has('sulu.article.articles', PermissionTypes::VIEW, 'en')->willReturn(false);

        $this->searcher
            ->search(Argument::that(function(Search $search): bool {
                foreach ($search->filters as $filter) {
                    if ($filter instanceof InCondition
                        && 'resourceKey' === $filter->field
                        && ['pages'] === $filter->values
                    ) {
                        return true;
                    }
                }

                return false;
            }))
            ->shouldBeCalledOnce()
            ->willReturn($this->createEmptyResult());

        $this->contentSearch->search('hello', 'en');
    }
}
