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
use Sulu\Mcp\Application\Metadata\MetadataLocaleResolver;

/**
 * @internal
 */
class GlobalBlocksResource
{
    public function __construct(
        private readonly MetadataProviderInterface $formMetadataProvider,
        private readonly FieldNormalizer $fieldNormalizer,
        private readonly MetadataLocaleResolver $localeResolver,
    ) {
    }

    /** @return list<array<string, mixed>> */
    #[McpResource(
        uri: 'sulu://global_blocks',
        name: 'sulu_global_blocks',
        description: 'Catalogue of the project\'s global block types — block types defined once centrally and reusable across templates — each listed once with its field definitions. This resource does NOT list every block type in the project: block types defined inline in a template are listed with that template in sulu://templates instead, and are usually the majority. A template block type referencing a global block carries a `globalBlock: <name>` reference pointing at an entry here.',
        mimeType: 'application/json',
    )]
    public function getGlobalBlocks(): array
    {
        $locale = $this->localeResolver->resolve();

        $typedMetadata = $this->formMetadataProvider->getMetadata('block', $locale, ['ignore_global_blocks' => true]);
        if (!$typedMetadata instanceof TypedFormMetadata) {
            return [];
        }

        $globalBlocks = [];
        foreach ($typedMetadata->getForms() as $key => $form) {
            $globalBlocks[] = [
                'key' => $key,
                'label' => $form->getTitle($locale),
                'fields' => $this->fieldNormalizer->normalizeForm($form, $locale),
            ];
        }

        return $globalBlocks;
    }
}
