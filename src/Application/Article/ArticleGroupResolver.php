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

use Sulu\Article\Domain\Model\ArticleInterface;
use Sulu\Bundle\AdminBundle\Metadata\GroupProviderInterface;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;

/**
 * Resolves the Sulu admin "group" URL segment for an article.
 *
 * Article admin edit routes are /{locale}/{group}/{id}, where {group}
 * is the article template's <group> element (falling back to "default"). The
 * group is not stored on the article — it is derived from the template via the
 * admin metadata, the same way Sulu's own GroupProvider builds the routes.
 *
 * @internal
 */
final readonly class ArticleGroupResolver
{
    private const DEFAULT_GROUP = 'default';

    public function __construct(
        private GroupProviderInterface $groupProvider,
        private ContentManagerInterface $contentManager,
    ) {
    }

    public function resolveByTemplate(?string $template): string
    {
        if (null === $template || '' === $template) {
            return self::DEFAULT_GROUP;
        }

        foreach ($this->groupProvider->getGroups(ArticleInterface::TEMPLATE_TYPE) as $group) {
            if (\in_array($template, $group->templates, true)) {
                return $group->identifier;
            }
        }

        return self::DEFAULT_GROUP;
    }

    /**
     * Derive the group from an article entity by reading its draft template.
     * Used where only the entity is available (publish/unpublish). Best-effort:
     * any failure falls back to the default group so a deeplink is never blocked.
     */
    public function resolveByArticle(ArticleInterface $article, string $locale): string
    {
        try {
            $dimensionContent = $this->contentManager->resolve($article, [
                'locale' => $locale,
                'stage' => DimensionContentInterface::STAGE_DRAFT,
            ]);
            $data = $this->contentManager->normalize($dimensionContent);

            $template = \is_string($data['template'] ?? null) ? $data['template'] : null;

            return $this->resolveByTemplate($template);
        } catch (\Throwable) {
            return self::DEFAULT_GROUP;
        }
    }
}
