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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool;

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
use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceCollection;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Component\Webspace\Webspace;
use Sulu\Mcp\Application\Security\ToolPermissionChecker;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Tests\Unit\Fixture\TestUser;
use Sulu\Mcp\UserInterface\Mcp\Tool\ContentSearchTool;

#[CoversClass(ContentSearchTool::class)]
final class ContentSearchToolTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<EngineInterface> */
    private ObjectProphecy $engine;

    /** @var ObjectProphecy<SearcherInterface> */
    private ObjectProphecy $searcher;

    private ContentSearchTool $tool;

    protected function setUp(): void
    {
        $this->engine = $this->prophesize(EngineInterface::class);
        $this->searcher = $this->prophesize(SearcherInterface::class);
        // Grants EDIT on 'example' so existing happy-path tests are unaffected by the webspace filter.
        $this->tool = new ContentSearchTool($this->engine->reveal(), $this->webspaceResolver(['example']));
    }

    /**
     * Real WebspacePermissionResolver (final) over a mocked WebspaceManagerInterface
     * and a real ToolPermissionChecker driven by a mocked SecurityCheckerInterface.
     *
     * @param list<string> $grantedWebspaceKeys webspace keys on which EDIT is granted
     */
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

        $result = $this->tool->search('hello', 'en', null, 'article');

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

        $this->tool->search('hello', 'en', null, 'page');
    }

    public function testUnknownTypeIsPassedVerbatim(): void
    {
        $builder = $this->createSearchBuilder();

        $this->engine->createSearchBuilder('website')->willReturn($builder);

        $this->searcher
            ->search(Argument::that(function(Search $search): bool {
                foreach ($search->filters as $filter) {
                    if ($filter instanceof EqualCondition
                        && 'resourceKey' === $filter->field
                        && 'custom_type' === $filter->value
                    ) {
                        return true;
                    }
                }

                return false;
            }))
            ->shouldBeCalledOnce()
            ->willReturn($this->createEmptyResult());

        $this->tool->search('hello', 'en', null, 'custom_type');
    }

    public function testSearchEngineExceptionReturnsStructuredError(): void
    {
        $this->engine
            ->createSearchBuilder(Argument::cetera())
            ->willThrow(new \RuntimeException('Search engine unavailable'));

        $result = $this->tool->search('hello', 'en');

        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('hint', $result);
        $this->assertStringContainsString('Content search failed', $result['error']);
        $this->assertStringContainsString('Search engine unavailable', $result['error']);
        $this->assertArrayNotHasKey('results', $result);
    }

    public function testSearchMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(ContentSearchTool::class, 'search');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes, 'search() method must have exactly one #[McpTool] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_content_search', $instance->name);
    }

    public function testNullTypeAppliesNoResourceKeyFilter(): void
    {
        $builder = $this->createSearchBuilder();

        $this->engine->createSearchBuilder(Argument::cetera())->willReturn($builder);

        $this->searcher
            ->search(Argument::that(function(Search $search): bool {
                foreach ($search->filters as $filter) {
                    if ($filter instanceof EqualCondition && 'resourceKey' === $filter->field) {
                        return false;
                    }
                }

                return true;
            }))
            ->shouldBeCalledOnce()
            ->willReturn($this->createEmptyResult());

        $this->tool->search('hello', 'en');
    }

    public function testSearchReturnsEmptyResultsWhenNoWebspaceIsPermitted(): void
    {
        $tool = new ContentSearchTool($this->engine->reveal(), $this->webspaceResolver([]));

        $this->engine->createSearchBuilder(Argument::cetera())->shouldNotBeCalled();

        $result = $tool->search('hello', 'en');

        $this->assertSame(
            ['items' => [], 'total' => 0, 'hint' => 'No webspaces are readable with your permissions.'],
            $result,
        );
    }

    public function testSearchReturnsEmptyResultsWhenRequestedWebspaceIsNotPermitted(): void
    {
        $tool = new ContentSearchTool($this->engine->reveal(), $this->webspaceResolver(['example']));

        $this->engine->createSearchBuilder(Argument::cetera())->shouldNotBeCalled();

        $result = $tool->search('hello', 'en', 'other');

        $this->assertSame(
            ['items' => [], 'total' => 0, 'hint' => 'Webspace "other" is not readable with your permissions.'],
            $result,
        );
    }

    public function testSearchFiltersByPermittedWebspaces(): void
    {
        $builder = $this->createSearchBuilder();

        $tool = new ContentSearchTool($this->engine->reveal(), $this->webspaceResolver(['example', 'blog']));

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

        $tool->search('hello', 'en');
    }

    public function testSearchIntersectsRequestedWebspaceWithPermittedSet(): void
    {
        $builder = $this->createSearchBuilder();

        $tool = new ContentSearchTool($this->engine->reveal(), $this->webspaceResolver(['example', 'blog']));

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

        $tool->search('hello', 'en', 'example');
    }
}
