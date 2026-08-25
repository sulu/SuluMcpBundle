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

namespace Sulu\Mcp\Tests\Application\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Sulu\Content\Domain\Model\WorkflowInterface;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Product\Application\Message\ApplyWorkflowTransitionProductMessage;
use Sulu\Product\Application\Message\CreateAttributeGroupMessage;
use Sulu\Product\Application\Message\CreateAttributeMessage;
use Sulu\Product\Application\Message\CreateProductFamilyMessage;
use Sulu\Product\Application\Message\CreateProductMessage;
use Sulu\Product\Domain\Model\AttributeGroupInterface;
use Sulu\Product\Domain\Model\AttributeInterface;
use Sulu\Product\Domain\Model\ProductFamilyInterface;
use Sulu\Product\Domain\Model\ProductInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * A product cannot exist without a family, and a family not without attributes, so the
 * vocabulary is seeded first.
 */
class ProductFixtures extends Fixture
{
    private const LOCALE = 'en';

    public function __construct(
        #[Autowire(service: 'sulu_message_bus')]
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        /** @var AttributeGroupInterface $group */
        $group = $this->dispatch(new CreateAttributeGroupMessage([
            'locale' => self::LOCALE,
            'name' => 'Appearance',
        ]));

        /** @var AttributeInterface $material */
        $material = $this->dispatch(new CreateAttributeMessage([
            'locale' => self::LOCALE,
            'key' => 'material',
            'type' => AttributeInterface::TYPE_TEXT,
            'name' => 'Material',
            'group' => (string) $group->getUuid(),
        ]));

        /** @var AttributeInterface $colour */
        $colour = $this->dispatch(new CreateAttributeMessage([
            'locale' => self::LOCALE,
            'key' => 'colour',
            'type' => AttributeInterface::TYPE_OPTIONS,
            'name' => 'Colour',
            'group' => (string) $group->getUuid(),
            'options' => [
                ['key' => 'red', 'name' => 'Red'],
                ['key' => 'blue', 'name' => 'Blue'],
            ],
        ]));

        /** @var ProductFamilyInterface $family */
        $family = $this->dispatch(new CreateProductFamilyMessage([
            'locale' => self::LOCALE,
            'name' => 'Shirts',
            'description' => 'Shirts, sized and coloured.',
            'attributes' => [
                $material->getId() => ['enabled' => true, 'required' => false, 'variantSpecific' => false],
                $colour->getId() => ['enabled' => true, 'required' => true, 'variantSpecific' => true],
            ],
        ]));

        $familyUuid = (string) $family->getUuid();

        $this->createAndPublish([
            'locale' => self::LOCALE,
            'productFamily' => $familyUuid,
            'title' => 'Canvas Tote Bag',
            'code' => 'TOTE-1',
            'type' => ProductInterface::TYPE_PRODUCT,
            'attributes' => [$material->getId() => 'canvas'],
        ]);

        /** @var ProductInterface $parent */
        $parent = $this->dispatch(new CreateProductMessage([
            'locale' => self::LOCALE,
            'productFamily' => $familyUuid,
            'title' => 'Classic T-Shirt',
            'code' => 'SHIRT',
            'type' => ProductInterface::TYPE_PRODUCT_WITH_VARIANTS,
            'attributes' => [$material->getId() => 'cotton'],
        ]));

        foreach (['red' => 'SHIRT-RED', 'blue' => 'SHIRT-BLUE'] as $option => $code) {
            $this->dispatch(new CreateProductMessage([
                'locale' => self::LOCALE,
                'productFamily' => $familyUuid,
                'title' => 'Classic T-Shirt ' . \ucfirst($option),
                'code' => $code,
                'type' => ProductInterface::TYPE_VARIANT,
                'parent' => $parent->getUuid(),
                'attributes' => [$colour->getId() => $option],
            ]));
        }

        // Publishing the parent cascades to its variants.
        $this->publish($parent);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createAndPublish(array $data): void
    {
        /** @var ProductInterface $product */
        $product = $this->dispatch(new CreateProductMessage($data)); // @phpstan-ignore argument.type (fixture payloads are literal)

        $this->publish($product);
    }

    private function publish(ProductInterface $product): void
    {
        $this->dispatch(new ApplyWorkflowTransitionProductMessage(
            identifier: ['uuid' => $product->getUuid()],
            locale: self::LOCALE,
            transitionName: WorkflowInterface::WORKFLOW_TRANSITION_PUBLISH,
        ));
    }

    private function dispatch(object $message): object
    {
        $envelope = $this->messageBus->dispatch(new Envelope($message, [new EnableFlushStamp()]));

        /** @var HandledStamp[] $handledStamps */
        $handledStamps = $envelope->all(HandledStamp::class);
        $result = $handledStamps[0]->getResult();
        \assert(\is_object($result));

        return $result;
    }
}
