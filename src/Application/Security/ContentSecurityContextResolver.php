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

namespace Sulu\Mcp\Application\Security;

use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\ContentRichEntityInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\TemplateInterface;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Page\Domain\Model\PageInterface;

/**
 * Per-type security context for a loaded content entity:
 * page → sulu.webspaces.<key> (from the aggregate), article → per-group (from the
 * RESOLVED dimension content's template key — NOT the aggregate),
 * snippet → sulu.snippet.snippets.
 *
 * @internal
 */
final readonly class ContentSecurityContextResolver
{
    public function __construct(
        private ArticleSecurityContextResolver $articleContextResolver,
        private ContentManagerInterface $contentManager,
    ) {
    }

    /**
     * @param object $aggregate the loaded draft aggregate (Page/Article/Snippet)
     * @param TemplateInterface|null $dimensionContent the resolved dimension content (carries the article template key)
     */
    public function forEntity(string $type, object $aggregate, ?TemplateInterface $dimensionContent = null): string
    {
        return match ($type) {
            'page' => $aggregate instanceof PageInterface ? 'sulu.webspaces.' . $aggregate->getWebspaceKey() : '',
            'article' => $this->articleContextResolver->forTemplateKey($dimensionContent?->getTemplateKey() ?? ''),
            'snippet' => 'sulu.snippet.snippets',
            default => '',
        };
    }

    /**
     * Same mapping for the loadGhost callers: a ghost carries no template key of its own,
     * so an article's group comes from the locale it is a ghost of.
     *
     * @template T of ContentRichEntityInterface
     *
     * @param object $aggregate the loaded draft aggregate (Page/Article/Snippet)
     * @param DimensionContentInterface<T> $dimensionContent the dimension content resolved for $locale, ghost or not
     */
    public function forEntityInLocale(string $type, object $aggregate, DimensionContentInterface $dimensionContent, string $locale): string
    {
        $ghostLocale = $dimensionContent->getGhostLocale();

        if ('article' === $type && $locale !== $dimensionContent->getLocale() && null !== $ghostLocale) {
            $dimensionContent = $this->contentManager->resolve($aggregate, [ // @phpstan-ignore argument.type, argument.templateType (upstream generic is invariant; the caller holds a bare object)
                'locale' => $ghostLocale,
                'stage' => DimensionContentInterface::STAGE_DRAFT,
            ]);
        }

        return $this->forEntity($type, $aggregate, $dimensionContent instanceof TemplateInterface ? $dimensionContent : null);
    }
}
