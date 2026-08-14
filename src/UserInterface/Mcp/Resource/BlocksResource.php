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

namespace Sulu\Mcp\UserInterface\Mcp\Resource;

use Mcp\Capability\Attribute\McpResource;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Mcp\Application\Metadata\FieldNormalizer;

/**
 * @internal
 */
class BlocksResource
{
    public function __construct(
        private readonly MetadataProviderInterface $formMetadataProvider,
        private readonly FieldNormalizer $fieldNormalizer,
    ) {
    }

    /** @return list<array<string, mixed>> */
    #[McpResource(
        uri: 'sulu://blocks',
        name: 'sulu_blocks',
        description: 'Catalogue of global block types, each listed once with its field definitions. Block types defined inline in a template are listed with that template in sulu://templates instead; a template block type referencing a global block carries a `globalBlock: <name>` reference pointing at an entry here.',
        mimeType: 'application/json',
    )]
    public function getBlocks(): array
    {
        $typedMetadata = $this->formMetadataProvider->getMetadata('block', 'en', ['ignore_global_blocks' => true]);
        if (!$typedMetadata instanceof TypedFormMetadata) {
            return [];
        }

        $blocks = [];
        foreach ($typedMetadata->getForms() as $key => $form) {
            $blocks[] = [
                'key' => $key,
                'label' => $form->getTitle('en'),
                'fields' => $this->fieldNormalizer->normalizeForm($form, 'en'),
            ];
        }

        return $blocks;
    }
}
