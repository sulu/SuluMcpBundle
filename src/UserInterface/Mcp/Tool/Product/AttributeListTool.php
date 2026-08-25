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

namespace Sulu\Mcp\UserInterface\Mcp\Tool\Product;

use Mcp\Capability\Attribute\McpTool;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;
use Sulu\Product\Domain\Model\AttributeInterface;
use Sulu\Product\Domain\Repository\AttributeGroupRepositoryInterface;

/**
 * @internal
 */
class AttributeListTool
{
    public function __construct(
        private readonly AttributeGroupRepositoryInterface $attributeGroupRepository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'sulu_attribute_list',
        title: 'List Product Attributes',
        description: 'List every product attribute, grouped by attribute group. The "id" of each attribute is the key to use in the "attributes" map of sulu_product_create, sulu_product_update and the variant tools — e.g. an attribute with id 12 is written as {"12": "red"}. "type" tells you what a value looks like: "text" a string, "number" a number, "date" an ISO-8601 date, "options" one of the listed option keys. Which attributes actually apply to a given product, and which are required or variant axes, depends on its family — see sulu_product_family_list.',
    )]
    #[RequiresPermission(requirements: [
        new PermissionRequirement('sulu.product.attributes', PermissionTypes::VIEW),
    ])]
    public function listAttributes(string $locale): array
    {
        try {
            $groups = [];
            $total = 0;

            foreach ($this->attributeGroupRepository->findAll() as $group) {
                $attributes = [];

                foreach ($group->getGroupAttributes() as $groupAttribute) {
                    $attributes[] = $this->describeAttribute($groupAttribute->getAttribute(), $locale);
                    ++$total;
                }

                $groups[] = [
                    'uuid' => $group->getUuid(),
                    'name' => $group->getTranslation($locale)?->getName(),
                    'attributes' => $attributes,
                ];
            }

            return [
                'groups' => $groups,
                'total' => $total,
            ];
        } catch (\Throwable $e) {
            return [
                'error' => \sprintf('Failed to list attributes: %s', $e->getMessage()),
                'hint' => 'Verify SuluProductBundle is installed and its schema is up to date.',
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function describeAttribute(AttributeInterface $attribute, string $locale): array
    {
        $described = [
            'id' => $attribute->getId(),
            'key' => $attribute->getKey(),
            'type' => $attribute->getType(),
            'name' => $attribute->getTranslation($locale)?->getName() ?? $attribute->getKey(),
            'localized' => $attribute->isLocalized(),
            'position' => $attribute->getPosition(),
        ];

        if (AttributeInterface::TYPE_OPTIONS === $attribute->getType()) {
            $options = [];
            foreach ($attribute->getOptions() as $option) {
                $options[] = [
                    'key' => $option->getKey(),
                    'name' => $option->getTranslation($locale)?->getName() ?? $option->getKey(),
                ];
            }

            $described['options'] = $options;
        }

        return $described;
    }
}
