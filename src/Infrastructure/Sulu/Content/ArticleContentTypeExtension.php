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

namespace Sulu\Mcp\Infrastructure\Sulu\Content;

use Doctrine\ORM\EntityManagerInterface;
use Sulu\Article\Application\Message\ApplyWorkflowTransitionArticleMessage;
use Sulu\Article\Application\Message\ModifyArticleMessage;
use Sulu\Article\Application\Message\RemoveArticleMessage;
use Sulu\Article\Domain\Model\ArticleDimensionContentInterface;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Content\Domain\Model\ContentRichEntityInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\TemplateInterface;
use Sulu\Content\Infrastructure\Doctrine\DimensionContentQueryEnhancer;
use Sulu\Mcp\Domain\Content\ContentSecurity;
use Sulu\Mcp\Domain\Content\ContentTypeExtensionInterface;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;

/**
 * @internal
 */
final readonly class ArticleContentTypeExtension implements ContentTypeExtensionInterface
{
    public function __construct(
        private ArticleRepositoryInterface $repository,
        private ArticleSecurityContextResolver $articleContextResolver,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function getType(): string
    {
        return 'article';
    }

    public function getResourceKey(): string
    {
        return 'articles';
    }

    public function getTemplateType(): string
    {
        return 'article';
    }

    public function getViewSecurityContexts(): array
    {
        return $this->articleContextResolver->candidates();
    }

    public function getSecurity(object $aggregate, string $locale): ContentSecurity
    {
        return new ContentSecurity($this->articleContextResolver->forTemplateKey($this->templateKeyOf($aggregate, $locale)));
    }

    public function createRemoveMessage(string $uuid, string $locale, bool $forceRemoveChildren = false): object
    {
        return new RemoveArticleMessage(['uuid' => $uuid], $locale);
    }

    public function loadDraft(string $uuid, string $locale, bool $loadGhost = false): ?object
    {
        try {
            return $this->repository->getOneBy([
                'uuid' => $uuid,
                'locale' => $locale,
                'stage' => DimensionContentInterface::STAGE_DRAFT,
                'loadGhost' => $loadGhost,
            ], [ArticleRepositoryInterface::GROUP_SELECT_ARTICLE_ADMIN => true]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Hydrated for draft and live: a draft-only aggregate duplicates the live rows on re-query.
     */
    public function loadForTransition(string $uuid, string $locale): ?object
    {
        try {
            return $this->repository->getOneBy([
                'uuid' => $uuid,
                'locale' => $locale,
                'stage' => DimensionContentInterface::STAGE_DRAFT,
            ], [ArticleRepositoryInterface::SELECT_ARTICLE_CONTENT => [
                'selects' => [DimensionContentQueryEnhancer::GROUP_SELECT_CONTENT_ADMIN => true],
                'dimensionAttributes' => [
                    'locale' => $locale,
                    'stage' => [DimensionContentInterface::STAGE_DRAFT, DimensionContentInterface::STAGE_LIVE],
                ],
            ]]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createModifyMessage(string $uuid, array $data): object
    {
        return new ModifyArticleMessage(['uuid' => $uuid], $data);
    }

    public function createTransitionMessage(string $uuid, string $locale, string $transition): object
    {
        return new ApplyWorkflowTransitionArticleMessage(['uuid' => $uuid], $locale, $transition);
    }

    /**
     * A ghost has no template key of its own. The group comes from the locale it is a ghost of,
     * which the aggregate does not carry when the ghost was loaded, so that locale is queried.
     */
    private function templateKeyOf(object $aggregate, string $locale): string
    {
        if (!$aggregate instanceof ContentRichEntityInterface) {
            return '';
        }

        $loaded = [];
        $unlocalized = null;
        foreach ($aggregate->getDimensionContents() as $dimensionContent) {
            if (DimensionContentInterface::STAGE_DRAFT !== $dimensionContent->getStage()) {
                continue;
            }

            if (null === $dimensionContent->getLocale()) {
                $unlocalized = $dimensionContent;
            } elseif ($dimensionContent instanceof TemplateInterface && null !== $dimensionContent->getTemplateKey()) {
                $loaded[$dimensionContent->getLocale()] = $dimensionContent->getTemplateKey();
            }
        }

        if (isset($loaded[$locale])) {
            return $loaded[$locale];
        }

        $sourceLocales = \array_unique(\array_filter([
            $unlocalized?->getGhostLocale(),
            ...($unlocalized?->getAvailableLocales() ?? []),
        ], static fn (?string $sourceLocale): bool => null !== $sourceLocale && $locale !== $sourceLocale));

        foreach ($sourceLocales as $sourceLocale) {
            $templateKey = $loaded[$sourceLocale] ?? $this->queryTemplateKey($aggregate, $sourceLocale);
            if (null !== $templateKey) {
                return $templateKey;
            }
        }

        return '';
    }

    private function queryTemplateKey(object $aggregate, string $locale): ?string
    {
        $dimensionContent = $this->entityManager->getRepository(ArticleDimensionContentInterface::class)->findOneBy([
            'article' => $aggregate,
            'locale' => $locale,
            'stage' => DimensionContentInterface::STAGE_DRAFT,
        ]);

        return $dimensionContent instanceof TemplateInterface ? $dimensionContent->getTemplateKey() : null;
    }
}
