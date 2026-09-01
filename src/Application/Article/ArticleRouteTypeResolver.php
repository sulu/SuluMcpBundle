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

namespace Sulu\Mcp\Application\Article;

use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Mcp\Application\Metadata\MetadataLocaleResolver;

/**
 * Reads which routing form an article template expects.
 *
 * Both forms are well-formed on their own, so only the template can tell them apart.
 *
 * @internal
 */
final readonly class ArticleRouteTypeResolver
{
    public const TYPE_ROUTE = 'route';

    public const TYPE_PAGE_TREE_ROUTE = 'page_tree_route';

    public function __construct(
        private MetadataProviderInterface $formMetadataProvider,
        private MetadataLocaleResolver $localeResolver,
    ) {
    }

    /**
     * Null when the metadata does not say: a project may define its own route field
     * type, and refusing to save is worse than the error the caller would have got.
     *
     * @return self::TYPE_*|null
     */
    public function resolve(?string $templateKey): ?string
    {
        if (null === $templateKey) {
            return null;
        }

        try {
            $typed = $this->formMetadataProvider->getMetadata('article', $this->localeResolver->resolve(), []);
        } catch (\Throwable) {
            return null;
        }

        if (!$typed instanceof TypedFormMetadata) {
            return null;
        }

        $form = $typed->getForms()[$templateKey] ?? null;
        if (!$form instanceof FormMetadata) {
            return null;
        }

        foreach ($form->getFlatFieldMetadata() as $field) {
            if (self::TYPE_ROUTE === $field->getType() || self::TYPE_PAGE_TREE_ROUTE === $field->getType()) {
                return $field->getType();
            }
        }

        return null;
    }
}
