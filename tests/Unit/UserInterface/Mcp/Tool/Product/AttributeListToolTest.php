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

use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Mcp\UserInterface\Mcp\Tool\Product\AttributeListTool;
use Sulu\Product\Domain\Model\Attribute;
use Sulu\Product\Domain\Model\AttributeGroup;
use Sulu\Product\Domain\Model\AttributeGroupAttribute;
use Sulu\Product\Domain\Model\AttributeGroupTranslation;
use Sulu\Product\Domain\Model\AttributeInterface;
use Sulu\Product\Domain\Model\AttributeOption;
use Sulu\Product\Domain\Model\AttributeOptionTranslation;
use Sulu\Product\Domain\Model\AttributeTranslation;
use Sulu\Product\Domain\Repository\AttributeGroupRepositoryInterface;

#[CoversClass(AttributeListTool::class)]
final class AttributeListToolTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<AttributeGroupRepositoryInterface> */
    private ObjectProphecy $attributeGroupRepository;
    private AttributeListTool $tool;

    protected function setUp(): void
    {
        $this->attributeGroupRepository = $this->prophesize(AttributeGroupRepositoryInterface::class);
        $this->tool = new AttributeListTool($this->attributeGroupRepository->reveal());
    }

    public function testListAttributesExposesTheIntegerIdUsedAsAttributeKey(): void
    {
        $group = new AttributeGroup();
        $group->addTranslation(new AttributeGroupTranslation($group, 'en', 'Appearance'));

        $this->addAttribute($group, 12, 'colour', AttributeInterface::TYPE_TEXT, 'Colour');

        $this->attributeGroupRepository->findAll()->willReturn([$group]);

        $result = $this->tool->listAttributes('en');

        $this->assertSame(1, $result['total']);
        $this->assertSame('Appearance', $result['groups'][0]['name']);

        $attribute = $result['groups'][0]['attributes'][0];
        $this->assertSame(12, $attribute['id']);
        $this->assertSame('colour', $attribute['key']);
        $this->assertSame(AttributeInterface::TYPE_TEXT, $attribute['type']);
        $this->assertSame('Colour', $attribute['name']);
        $this->assertArrayNotHasKey('options', $attribute);
    }

    public function testListAttributesIncludesOptionsForOptionAttributes(): void
    {
        $group = new AttributeGroup();
        $attribute = $this->addAttribute($group, 13, 'size', AttributeInterface::TYPE_OPTIONS, 'Size');

        $option = new AttributeOption($attribute, 'xl');
        $option->addTranslation(new AttributeOptionTranslation($option, 'en', 'Extra Large'));
        $attribute->addOption($option);

        $this->attributeGroupRepository->findAll()->willReturn([$group]);

        $result = $this->tool->listAttributes('en');

        $this->assertSame([['key' => 'xl', 'name' => 'Extra Large']], $result['groups'][0]['attributes'][0]['options']);
    }

    public function testListAttributesFallsBackToTheKeyWhenALocaleIsMissing(): void
    {
        $group = new AttributeGroup();
        $this->addAttribute($group, 14, 'material', AttributeInterface::TYPE_TEXT, 'Material');

        $this->attributeGroupRepository->findAll()->willReturn([$group]);

        $result = $this->tool->listAttributes('de');

        $this->assertSame('material', $result['groups'][0]['attributes'][0]['name']);
    }

    public function testListAttributesReturnsErrorOnFailure(): void
    {
        $this->attributeGroupRepository->findAll()->willThrow(new \RuntimeException('DB gone'));

        $result = $this->tool->listAttributes('en');

        $this->assertArrayHasKey('error', $result);
        $this->assertNotEmpty($result['hint']);
    }

    public function testListAttributesMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(AttributeListTool::class, 'listAttributes');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes, 'listAttributes() must have exactly one #[McpTool] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_attribute_list', $instance->name);
    }

    private function addAttribute(AttributeGroup $group, int $id, string $key, string $type, string $name): Attribute
    {
        $attribute = new Attribute($group);
        (new \ReflectionProperty($attribute, 'id'))->setValue($attribute, $id);
        $attribute->setKey($key);
        $attribute->setType($type);
        $attribute->addTranslation(new AttributeTranslation($attribute, 'en', $name));

        $group->addGroupAttribute(new AttributeGroupAttribute($group, $attribute));

        return $attribute;
    }
}
