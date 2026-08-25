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

namespace Sulu\Mcp\Tests\Functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use Sulu\Bundle\SecurityBundle\System\SystemStoreInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Domain\Model\WorkflowInterface;
use Sulu\Mcp\UserInterface\Mcp\Tool\Block\BlockAddTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Block\BlockListTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Block\BlockRemoveTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Block\BlockReorderTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Block\BlockUpdateTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Content\ContentPublishTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Product\AttributeListTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Product\ProductCreateTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Product\ProductFamilyListTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Product\ProductGetTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Product\ProductVariantCreateTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Product\ProductVariantListTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Product\ProductVariantUpdateTool;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Product\Application\Message\CreateAttributeGroupMessage;
use Sulu\Product\Application\Message\CreateAttributeMessage;
use Sulu\Product\Application\Message\CreateProductFamilyMessage;
use Sulu\Product\Domain\Model\AttributeGroupInterface;
use Sulu\Product\Domain\Model\AttributeInterface;
use Sulu\Product\Domain\Model\ProductFamilyInterface;
use Sulu\Product\Domain\Model\ProductInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[CoversNothing]
final class ProductVariantLifecycleTest extends FunctionalTestCase
{
    private const LOCALE = 'en';

    private MessageBusInterface $messageBus;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var MessageBusInterface $messageBus */
        $messageBus = self::getContainer()->get('sulu_message_bus');
        $this->messageBus = $messageBus;

        $this->authenticateWithFullProductPermissions();
    }

    public function testParentAndVariantsAreCreatedAndPublishedTogether(): void
    {
        [$family, $sharedAttribute, $variantAttribute] = $this->createFamilyWithSharedAndVariantAttribute();

        $familyUuid = (string) $family->getUuid();

        $attributeList = $this->tool(AttributeListTool::class)->listAttributes(self::LOCALE);
        $listedIds = \array_column(\array_merge(...\array_column($attributeList['groups'], 'attributes')), 'id');
        self::assertContains($sharedAttribute->getId(), $listedIds);
        self::assertContains($variantAttribute->getId(), $listedIds);

        $familyList = $this->tool(ProductFamilyListTool::class)->listProductFamilies(self::LOCALE);
        $listedFamily = $this->findFamily($familyList, $familyUuid);
        $flagsById = \array_column($listedFamily['attributes'], null, 'attributeId');
        self::assertFalse($flagsById[$sharedAttribute->getId()]['variantSpecific']);
        self::assertTrue($flagsById[$variantAttribute->getId()]['variantSpecific']);

        $parent = $this->tool(ProductCreateTool::class)->createProduct(
            self::LOCALE,
            $familyUuid,
            'Shirt',
            code: 'SHIRT',
            type: ProductInterface::TYPE_PRODUCT_WITH_VARIANTS,
            attributes: [(string) $sharedAttribute->getId() => 'cotton'],
        );
        self::assertTrue($parent['success'] ?? false, \json_encode($parent));
        $parentUuid = $parent['uuid'];
        self::assertStringEndsWith('#/en/products/' . $parentUuid, $parent['admin_url'] ?? '');

        foreach (['red' => 'SHIRT-RED', 'blue' => 'SHIRT-BLUE'] as $colour => $code) {
            $variant = $this->tool(ProductVariantCreateTool::class)->createProductVariant(
                self::LOCALE,
                $parentUuid,
                'Shirt ' . $colour,
                code: $code,
                attributes: [(string) $variantAttribute->getId() => $colour],
            );
            self::assertTrue($variant['success'] ?? false, \json_encode($variant));
            self::assertStringEndsWith('#/en/products/' . $parentUuid . '/variants', $variant['admin_url'] ?? '');
        }

        $variants = $this->tool(ProductVariantListTool::class)->listProductVariants(self::LOCALE, $parentUuid);
        self::assertSame(2, $variants['total']);

        $published = $this->tool(ContentPublishTool::class)->publishContent('product', $parentUuid, self::LOCALE);
        self::assertTrue($published['success'] ?? false, \json_encode($published));

        foreach ($variants['variants'] as $variant) {
            $reloaded = $this->tool(ProductGetTool::class)->getProduct(self::LOCALE, $variant['uuid']);
            self::assertSame(
                WorkflowInterface::WORKFLOW_PLACE_PUBLISHED,
                $reloaded['data']['workflowPlace'] ?? null,
                'Publishing the parent must cascade to its variants.',
            );
        }
    }

    public function testBlockToolsOperateOnAProductThroughTheTypeParameter(): void
    {
        [$family] = $this->createFamilyWithSharedAndVariantAttribute();

        $product = $this->tool(ProductCreateTool::class)->createProduct(
            self::LOCALE,
            (string) $family->getUuid(),
            'Block Carrier',
            code: 'BLOCKS-1',
        );
        self::assertTrue($product['success'] ?? false, \json_encode($product));
        $uuid = $product['uuid'];

        $added = $this->tool(BlockAddTool::class)->addBlock(
            'product',
            $uuid,
            self::LOCALE,
            'text',
            'blocks',
            ['content' => '<p>First</p>'],
        );
        self::assertTrue($added['success'] ?? false, \json_encode($added));

        $second = $this->tool(BlockAddTool::class)->addBlock(
            'product',
            $uuid,
            self::LOCALE,
            'text',
            'blocks',
            ['content' => '<p>Second</p>'],
        );
        self::assertTrue($second['success'] ?? false, \json_encode($second));

        $listed = $this->tool(BlockListTool::class)->listBlocks('product', $uuid, self::LOCALE, 'blocks');
        self::assertSame(2, $listed['total'] ?? null, \json_encode($listed));

        $blockId = $listed['blocks'][0]['_id'];

        $updated = $this->tool(BlockUpdateTool::class)->updateBlock(
            'product',
            $uuid,
            self::LOCALE,
            $blockId,
            ['content' => '<p>Updated</p>'],
        );
        self::assertTrue($updated['success'] ?? false, \json_encode($updated));

        $reordered = $this->tool(BlockReorderTool::class)->reorderBlocks('product', $uuid, self::LOCALE, 'blocks', [1, 0]);
        self::assertTrue($reordered['success'] ?? false, \json_encode($reordered));

        $afterUpdate = $this->tool(BlockListTool::class)->listBlocks('product', $uuid, self::LOCALE, 'blocks');
        self::assertStringContainsString('Updated', \json_encode($afterUpdate));

        $removed = $this->tool(BlockRemoveTool::class)->removeBlock('product', $uuid, self::LOCALE, 'blocks', blockId: $blockId);
        self::assertTrue($removed['success'] ?? false, \json_encode($removed));

        $afterRemove = $this->tool(BlockListTool::class)->listBlocks('product', $uuid, self::LOCALE, 'blocks');
        self::assertSame(1, $afterRemove['total'] ?? null, \json_encode($afterRemove));

        $reloaded = $this->tool(ProductGetTool::class)->getProduct(self::LOCALE, $uuid);
        self::assertSame(
            (string) $family->getUuid(),
            $reloaded['data']['productFamily'] ?? null,
            'A block write must not drop the product-specific fields it round-trips.',
        );
        self::assertSame('BLOCKS-1', $reloaded['data']['code'] ?? null);
    }

    public function testAVariantCannotBeCreatedUnderAPlainProductOrAnotherVariant(): void
    {
        [$family, , $variantAttribute] = $this->createFamilyWithSharedAndVariantAttribute();
        $familyUuid = (string) $family->getUuid();

        $plain = $this->tool(ProductCreateTool::class)->createProduct(
            self::LOCALE,
            $familyUuid,
            'Plain',
            code: 'PLAIN',
            type: ProductInterface::TYPE_PRODUCT,
        );
        self::assertTrue($plain['success'] ?? false, \json_encode($plain));

        $rejected = $this->tool(ProductVariantCreateTool::class)
            ->createProductVariant(self::LOCALE, $plain['uuid'], 'Nope');
        self::assertArrayNotHasKey('success', $rejected);
        self::assertStringContainsString('cannot have variants', $rejected['error']);

        $parent = $this->tool(ProductCreateTool::class)->createProduct(
            self::LOCALE,
            $familyUuid,
            'Parent',
            code: 'PARENT',
            type: ProductInterface::TYPE_PRODUCT_WITH_VARIANTS,
        );
        $variant = $this->tool(ProductVariantCreateTool::class)->createProductVariant(
            self::LOCALE,
            $parent['uuid'],
            'Variant',
            code: 'VARIANT',
            attributes: [(string) $variantAttribute->getId() => 'red'],
        );
        self::assertTrue($variant['success'] ?? false, \json_encode($variant));

        $nested = $this->tool(ProductVariantCreateTool::class)
            ->createProductVariant(self::LOCALE, $variant['uuid'], 'Nested');
        self::assertArrayNotHasKey('success', $nested);
        self::assertStringContainsString('cannot have variants', $nested['error']);
    }

    public function testAVariantCannotBeEditedThroughAForeignParent(): void
    {
        [$family, , $variantAttribute] = $this->createFamilyWithSharedAndVariantAttribute();
        $familyUuid = (string) $family->getUuid();

        $parents = [];
        foreach (['A', 'B'] as $suffix) {
            $created = $this->tool(ProductCreateTool::class)->createProduct(
                self::LOCALE,
                $familyUuid,
                'Parent ' . $suffix,
                code: 'PARENT-' . $suffix,
                type: ProductInterface::TYPE_PRODUCT_WITH_VARIANTS,
            );
            self::assertTrue($created['success'] ?? false, \json_encode($created));
            $parents[$suffix] = $created['uuid'];
        }

        $variant = $this->tool(ProductVariantCreateTool::class)->createProductVariant(
            self::LOCALE,
            $parents['A'],
            'Variant of A',
            code: 'VARIANT-A',
            attributes: [(string) $variantAttribute->getId() => 'red'],
        );
        self::assertTrue($variant['success'] ?? false, \json_encode($variant));

        $rejected = $this->tool(ProductVariantUpdateTool::class)->updateProductVariant(
            self::LOCALE,
            $parents['B'],
            $variant['uuid'],
            title: 'Hijacked',
        );

        self::assertArrayNotHasKey('success', $rejected);
        self::assertStringContainsString('does not belong to parent', $rejected['error']);
    }

    /**
     * @return array{0: ProductFamilyInterface, 1: AttributeInterface, 2: AttributeInterface}
     */
    private function createFamilyWithSharedAndVariantAttribute(): array
    {
        $group = $this->dispatch(new CreateAttributeGroupMessage([
            'locale' => self::LOCALE,
            'name' => 'Appearance',
        ]));
        \assert($group instanceof AttributeGroupInterface);

        $shared = $this->dispatch(new CreateAttributeMessage([
            'locale' => self::LOCALE,
            'key' => 'material',
            'type' => AttributeInterface::TYPE_TEXT,
            'name' => 'Material',
            'group' => (string) $group->getUuid(),
        ]));
        \assert($shared instanceof AttributeInterface);

        $variantSpecific = $this->dispatch(new CreateAttributeMessage([
            'locale' => self::LOCALE,
            'key' => 'colour',
            'type' => AttributeInterface::TYPE_TEXT,
            'name' => 'Colour',
            'group' => (string) $group->getUuid(),
        ]));
        \assert($variantSpecific instanceof AttributeInterface);

        $family = $this->dispatch(new CreateProductFamilyMessage([
            'locale' => self::LOCALE,
            'name' => 'Shirts',
            'attributes' => [
                $shared->getId() => ['enabled' => true, 'required' => false, 'variantSpecific' => false],
                $variantSpecific->getId() => ['enabled' => true, 'required' => false, 'variantSpecific' => true],
            ],
        ]));
        \assert($family instanceof ProductFamilyInterface);

        return [$family, $shared, $variantSpecific];
    }

    /**
     * @param array<string, mixed> $familyList
     *
     * @return array<string, mixed>
     */
    private function findFamily(array $familyList, string $uuid): array
    {
        foreach ($familyList['families'] as $family) {
            if ($uuid === $family['uuid']) {
                return $family;
            }
        }

        self::fail(\sprintf('Family %s was not returned by sulu_product_family_list.', $uuid));
    }

    private function dispatch(object $message): object
    {
        $envelope = $this->messageBus->dispatch(new Envelope($message, [new EnableFlushStamp()]));

        /** @var HandledStamp[] $handled */
        $handled = $envelope->all(HandledStamp::class);
        $result = $handled[0]->getResult();
        \assert(\is_object($result));

        return $result;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function tool(string $class): object
    {
        /** @var T $tool */
        $tool = self::getContainer()->get($class);

        return $tool;
    }

    private function authenticateWithFullProductPermissions(): void
    {
        $builder = new PermissionFixtureBuilder(
            $this->entityManager,
            self::getContainer()->get('sulu_security.mask_converter'),
            self::getContainer()->get('security.token_storage'),
            self::getContainer()->get(SystemStoreInterface::class),
        );

        $all = [
            PermissionTypes::VIEW => true,
            PermissionTypes::ADD => true,
            PermissionTypes::EDIT => true,
            PermissionTypes::DELETE => true,
            PermissionTypes::LIVE => true,
        ];

        $role = $builder->role('ProductAuthor', [
            'sulu.product.products' => $all,
            'sulu.product.product_families' => $all,
            'sulu.product.attributes' => $all,
            'sulu.product.attribute_groups' => $all,
        ]);

        $builder->authenticate($builder->user('product-author', $role));
    }
}
