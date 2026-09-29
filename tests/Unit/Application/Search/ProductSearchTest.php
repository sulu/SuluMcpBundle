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
use CmsIg\Seal\Schema\Field\FloatField;
use CmsIg\Seal\Schema\Field\IdentifierField;
use CmsIg\Seal\Schema\Field\ObjectField;
use CmsIg\Seal\Schema\Field\TextField;
use CmsIg\Seal\Schema\Index;
use CmsIg\Seal\Schema\Schema;
use CmsIg\Seal\Search\Condition\EqualCondition;
use CmsIg\Seal\Search\Condition\GreaterThanEqualCondition;
use CmsIg\Seal\Search\Condition\InCondition;
use CmsIg\Seal\Search\Condition\LessThanEqualCondition;
use CmsIg\Seal\Search\Result;
use CmsIg\Seal\Search\Search;
use CmsIg\Seal\Search\SearchBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Mcp\Application\Search\ProductSearch;
use Sulu\Mcp\Application\Search\WebsiteSearch;
use Sulu\Product\Infrastructure\Sulu\Search\Visitor\WebsiteProductAttributesReindexProviderEnhancer;

#[CoversClass(ProductSearch::class)]
#[Group('product')]
final class ProductSearchTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<EngineInterface> */
    private ObjectProphecy $engine;

    /** @var ObjectProphecy<SearcherInterface> */
    private ObjectProphecy $searcher;

    protected function setUp(): void
    {
        $this->engine = $this->prophesize(EngineInterface::class);
        $this->searcher = $this->prophesize(SearcherInterface::class);
    }

    /**
     * Pins the duplicated sanitization/encoding against
     * WebsiteProductAttributesReindexProviderEnhancer's real, public textValue()/numericField():
     * a rename or a rule change there must fail this test instead of silently drifting.
     */
    #[DataProvider('attributeKeyProvider')]
    public function testTextValueMatchesTheEnhancer(string $key, string $value): void
    {
        $this->assertSame(
            WebsiteProductAttributesReindexProviderEnhancer::textValue($key, $value),
            ProductSearch::textValue($key, $value),
        );
    }

    #[DataProvider('attributeKeyProvider')]
    public function testNumericFieldMatchesTheEnhancer(string $key): void
    {
        $this->assertSame(
            WebsiteProductAttributesReindexProviderEnhancer::numericField($key),
            ProductSearch::sanitizeAttributeKey($key),
        );
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function attributeKeyProvider(): array
    {
        return [
            'plain key' => ['colour', 'red'],
            'digit-prefixed key' => ['1st-pin', 'xlr'],
            'already sanitized key' => ['current_rating', '16'],
            'dotted key' => ['a.b', 'x'],
            'non-ascii key' => ['Ärger', 'x'],
        ];
    }

    public function testSearchAlwaysFiltersByProductResourceKey(): void
    {
        $productSearch = $this->productSearch($this->schemaWithoutProductField());
        $builder = $this->searchBuilder($this->schemaWithoutProductField());
        $this->engine->createSearchBuilder('website')->willReturn($builder);

        $this->searcher
            ->search(Argument::that(fn (Search $search): bool => $this->hasEqualCondition($search, 'resourceKey', 'products')))
            ->shouldBeCalledOnce()
            ->willReturn(Result::createEmpty());

        $productSearch->search('en', 'connector');
    }

    public function testSearchAppliesWebspaceAndFamilyFilters(): void
    {
        $productSearch = $this->productSearch($this->schemaWithProductField());
        $builder = $this->searchBuilder($this->schemaWithProductField());
        $this->engine->createSearchBuilder('website')->willReturn($builder);

        $this->searcher
            ->search(Argument::that(function(Search $search): bool {
                return $this->hasEqualCondition($search, 'webspaces', 'example')
                    && $this->hasEqualCondition($search, 'product.productFamilyId', 'family-uuid');
            }))
            ->shouldBeCalledOnce()
            ->willReturn(Result::createEmpty());

        $productSearch->search('en', null, 'example', 'family-uuid');
    }

    public function testSearchBuildsOptionFilterFromSanitizedKeyAndRawOptionKey(): void
    {
        $productSearch = $this->productSearch($this->schemaWithProductField());
        $builder = $this->searchBuilder($this->schemaWithProductField());
        $this->engine->createSearchBuilder('website')->willReturn($builder);

        $this->searcher
            ->search(Argument::that(function(Search $search): bool {
                foreach ($search->filters as $filter) {
                    if ($filter instanceof InCondition
                        && 'product.attributes_text_values' === $filter->field
                        && ['colour:red', 'colour:black'] === $filter->values
                    ) {
                        return true;
                    }
                }

                return false;
            }))
            ->shouldBeCalledOnce()
            ->willReturn(Result::createEmpty());

        $productSearch->search('en', null, null, null, ['colour' => ['red', 'black']]);
    }

    public function testSearchBuildsRangeFilterForNumericAttribute(): void
    {
        $schema = $this->schemaWithProductField(['current_rating' => new FloatField('current_rating', multiple: true, filterable: true)]);
        $productSearch = $this->productSearch($schema);
        $builder = $this->searchBuilder($schema);
        $this->engine->createSearchBuilder('website')->willReturn($builder);

        $this->searcher
            ->search(Argument::that(function(Search $search): bool {
                $min = null;
                $max = null;
                foreach ($search->filters as $filter) {
                    if ($filter instanceof GreaterThanEqualCondition && 'product.attributes_numeric_values.current_rating' === $filter->field) {
                        $min = $filter->value;
                    }
                    if ($filter instanceof LessThanEqualCondition && 'product.attributes_numeric_values.current_rating' === $filter->field) {
                        $max = $filter->value;
                    }
                }

                return 10.0 === $min && 20.0 === $max;
            }))
            ->shouldBeCalledOnce()
            ->willReturn(Result::createEmpty());

        $result = $productSearch->search('en', null, null, null, null, ['current_rating' => ['min' => 10, 'max' => 20]]);

        $this->assertArrayNotHasKey('error', $result);
    }

    public function testSearchParsesADateRangeBoundAsAUtcMidnightTimestamp(): void
    {
        $schema = $this->schemaWithProductField(['available_from' => new FloatField('available_from', multiple: true, filterable: true)]);
        $productSearch = $this->productSearch($schema);
        $builder = $this->searchBuilder($schema);
        $this->engine->createSearchBuilder('website')->willReturn($builder);

        $expected = (float) (new \DateTimeImmutable('2024-01-01', new \DateTimeZone('UTC')))->getTimestamp();

        $this->searcher
            ->search(Argument::that(function(Search $search) use ($expected): bool {
                foreach ($search->filters as $filter) {
                    if ($filter instanceof GreaterThanEqualCondition
                        && 'product.attributes_numeric_values.available_from' === $filter->field
                    ) {
                        return $expected === $filter->value;
                    }
                }

                return false;
            }))
            ->shouldBeCalledOnce()
            ->willReturn(Result::createEmpty());

        $productSearch->search('en', null, null, null, null, ['available_from' => ['min' => '2024-01-01']]);
    }

    public function testSearchRejectsARangeForANonFilterableAttribute(): void
    {
        $productSearch = $this->productSearch($this->schemaWithProductField());

        $this->engine->createSearchBuilder(Argument::cetera())->shouldNotBeCalled();

        $result = $productSearch->search('en', null, null, null, null, ['unknown_key' => ['min' => 1]]);

        $this->assertSame('"unknown_key" is not a filterable number or date attribute.', $result['error']);
    }

    public function testSearchRejectsStructuredFiltersWhenTheProductFieldIsNotIndexed(): void
    {
        $productSearch = $this->productSearch($this->schemaWithoutProductField());

        $this->engine->createSearchBuilder(Argument::cetera())->shouldNotBeCalled();

        $result = $productSearch->search('en', null, null, 'family-uuid');

        $this->assertSame('Product attribute filtering is not indexed.', $result['error']);
    }

    public function testSearchAllowsAPlainQueryWhenTheProductFieldIsNotIndexed(): void
    {
        $productSearch = $this->productSearch($this->schemaWithoutProductField());
        $builder = $this->searchBuilder($this->schemaWithoutProductField());
        $this->engine->createSearchBuilder('website')->willReturn($builder);

        $this->searcher->search(Argument::cetera())->shouldBeCalledOnce()->willReturn(Result::createEmpty());

        $result = $productSearch->search('en', 'connector');

        $this->assertArrayNotHasKey('error', $result);
    }

    public function testSearchRejectsEmptyOptionsForAnAttribute(): void
    {
        $productSearch = $this->productSearch($this->schemaWithProductField());

        $this->engine->createSearchBuilder(Argument::cetera())->shouldNotBeCalled();

        $result = $productSearch->search('en', null, null, null, ['colour' => []]);

        $this->assertSame('Invalid options for attribute "colour".', $result['error']);
    }

    public function testSearchRejectsAnUnparsableRangeBound(): void
    {
        $productSearch = $this->productSearch($this->schemaWithProductField(['current_rating' => new FloatField('current_rating', multiple: true, filterable: true)]));

        $this->engine->createSearchBuilder(Argument::cetera())->shouldNotBeCalled();

        $result = $productSearch->search('en', null, null, null, null, ['current_rating' => ['min' => 'not a number']]);

        $this->assertSame('Invalid "min" for attribute "current_rating".', $result['error']);
    }

    private function productSearch(Schema $schema): ProductSearch
    {
        return new ProductSearch(new WebsiteSearch($this->engine->reveal()), $schema);
    }

    /**
     * @param array<string, FloatField> $numericFields
     */
    private function schemaWithProductField(array $numericFields = []): Schema
    {
        $productField = new ObjectField('product', [
            'productFamilyId' => new TextField('productFamilyId', searchable: false, filterable: true),
            'attributes_text_values' => new TextField('attributes_text_values', multiple: true, searchable: false, filterable: true),
            'attributes_numeric_values' => new ObjectField('attributes_numeric_values', $numericFields),
        ]);

        return new Schema(['website' => new Index('website', [
            'id' => new IdentifierField('id'),
            'webspaces' => new TextField('webspaces', multiple: true, searchable: false, filterable: true),
            'resourceKey' => new TextField('resourceKey', searchable: false, filterable: true),
            'product' => $productField,
        ])]);
    }

    private function schemaWithoutProductField(): Schema
    {
        return new Schema(['website' => new Index('website', [
            'id' => new IdentifierField('id'),
            'webspaces' => new TextField('webspaces', multiple: true, searchable: false, filterable: true),
            'resourceKey' => new TextField('resourceKey', searchable: false, filterable: true),
        ])]);
    }

    private function searchBuilder(Schema $schema): SearchBuilder
    {
        return (new SearchBuilder($schema, $this->searcher->reveal()))->index('website');
    }

    private function hasEqualCondition(Search $search, string $field, string $value): bool
    {
        foreach ($search->filters as $filter) {
            if ($filter instanceof EqualCondition && $field === $filter->field && $value === $filter->value) {
                return true;
            }
        }

        return false;
    }
}
