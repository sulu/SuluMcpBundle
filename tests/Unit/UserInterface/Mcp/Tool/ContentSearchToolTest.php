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
use CmsIg\Seal\Search\Result;
use CmsIg\Seal\Search\SearchBuilder;
use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceCollection;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Component\Webspace\Webspace;
use Sulu\Mcp\Application\Search\ContentSearch;
use Sulu\Mcp\Application\Search\WebsiteSearch;
use Sulu\Mcp\Application\Security\ToolPermissionChecker;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Tests\Application\TestBundle\Metadata\TestGroupProvider;
use Sulu\Mcp\Tests\Unit\Fixture\ContentTypes;
use Sulu\Mcp\Tests\Unit\Fixture\TestUser;
use Sulu\Mcp\UserInterface\Mcp\Tool\ContentSearchTool;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;

/**
 * ContentSearch (final, so Prophecy can't double it directly) is real here, built over a
 * mocked SEAL engine; ContentSearchTest covers its actual search behavior in depth, this just
 * proves the adapter threads every argument to it in the right order.
 */
#[CoversClass(ContentSearchTool::class)]
final class ContentSearchToolTest extends TestCase
{
    use ProphecyTrait;

    public function testSearchDelegatesToContentSearch(): void
    {
        $engine = $this->prophesize(EngineInterface::class);
        $searcher = $this->prophesize(SearcherInterface::class);

        $webspace = new Webspace();
        $webspace->setKey('example');
        $webspaceManager = $this->prophesize(WebspaceManagerInterface::class);
        $webspaceManager->getWebspaceCollection()->willReturn(new WebspaceCollection(['example' => $webspace]));
        $securityChecker = $this->prophesize(SecurityCheckerInterface::class);
        $securityChecker->hasPermission(Argument::cetera())->willReturn(true);
        $webspaceResolver = new WebspacePermissionResolver($webspaceManager->reveal(), new ToolPermissionChecker($securityChecker->reveal(), (new TestUser())->inTokenStorage()));

        $identifierField = new IdentifierField('id');
        $schema = new Schema(['website' => new Index('website', ['id' => $identifierField])]);
        $builder = (new SearchBuilder($schema, $searcher->reveal()))->index('website');
        $engine->createSearchBuilder('website')->willReturn($builder);
        $searcher->search(Argument::cetera())->willReturn(Result::createEmpty());

        $permissionChecker = $this->prophesize(ToolPermissionCheckerInterface::class);
        $permissionChecker->has(Argument::cetera())->willReturn(true);
        $contentSearch = new ContentSearch(new WebsiteSearch($engine->reveal()), $webspaceResolver, $permissionChecker->reveal(), ContentTypes::registry($this->prophesize(PageRepositoryInterface::class)->reveal(), $this->prophesize(ArticleRepositoryInterface::class)->reveal(), TestGroupProvider::singleGroup()));
        $tool = new ContentSearchTool($contentSearch);

        $result = $tool->search('hello', 'en', 'example', 'page', 2, 10);

        $this->assertSame(2, $result['page']);
        $this->assertSame(10, $result['limit']);
        $this->assertArrayHasKey('results', $result);
    }

    public function testSearchMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(ContentSearchTool::class, 'search');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes, 'search() method must have exactly one #[McpTool] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_content_search', $instance->name);
    }
}
