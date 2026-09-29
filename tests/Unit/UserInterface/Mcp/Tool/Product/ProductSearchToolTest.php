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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\Product;

use CmsIg\Seal\Adapter\SearcherInterface;
use CmsIg\Seal\EngineInterface;
use CmsIg\Seal\Schema\Field\IdentifierField;
use CmsIg\Seal\Schema\Index;
use CmsIg\Seal\Schema\Schema;
use CmsIg\Seal\Search\Result;
use CmsIg\Seal\Search\SearchBuilder;
use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Mcp\Application\Search\ProductSearch;
use Sulu\Mcp\Application\Search\WebsiteSearch;
use Sulu\Mcp\UserInterface\Mcp\Tool\Product\ProductSearchTool;

/**
 * ProductSearch (final, so Prophecy can't double it directly) is real here, built over a
 * mocked SEAL engine and an empty schema; ProductSearchTest covers its actual search behavior
 * in depth, this just proves the adapter threads every argument to it in the right order.
 */
#[CoversClass(ProductSearchTool::class)]
#[Group('product')]
final class ProductSearchToolTest extends TestCase
{
    use ProphecyTrait;

    public function testSearchDelegatesToProductSearch(): void
    {
        $engine = $this->prophesize(EngineInterface::class);
        $searcher = $this->prophesize(SearcherInterface::class);

        $schema = new Schema(['website' => new Index('website', ['id' => new IdentifierField('id')])]);
        $builder = (new SearchBuilder($schema, $searcher->reveal()))->index('website');
        $engine->createSearchBuilder('website')->willReturn($builder);
        $searcher->search(Argument::cetera())->willReturn(Result::createEmpty());

        $productSearch = new ProductSearch(new WebsiteSearch($engine->reveal()), $schema);
        $tool = new ProductSearchTool($productSearch);

        // No structured filters, so the empty schema (no "product" field) is never rejected.
        $result = $tool->search('en', 'connector', 'example', null, null, null, 2, 10);

        $this->assertSame(2, $result['page']);
        $this->assertSame(10, $result['limit']);
        $this->assertArrayHasKey('results', $result);
    }

    public function testSearchMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(ProductSearchTool::class, 'search');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes, 'search() must have exactly one #[McpTool] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_product_search', $instance->name);
    }
}
