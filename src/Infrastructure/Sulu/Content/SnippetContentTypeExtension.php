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
use Sulu\Content\Domain\Model\ContentRichEntityInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\TemplateInterface;
use Sulu\Content\Infrastructure\Doctrine\DimensionContentQueryEnhancer;
use Sulu\Mcp\Domain\Content\ContentSecurity;
use Sulu\Mcp\Domain\Content\ContentTypeExtensionInterface;
use Sulu\Mcp\Domain\Content\NotSearchableContentTypeInterface;
use Sulu\Mcp\Infrastructure\Sulu\Security\SnippetSecurityContextResolver;
use Sulu\Snippet\Application\Message\ApplyWorkflowTransitionSnippetMessage;
use Sulu\Snippet\Application\Message\ModifySnippetMessage;
use Sulu\Snippet\Application\Message\RemoveSnippetMessage;
use Sulu\Snippet\Domain\Model\SnippetDimensionContentInterface;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;

/**
 * @internal
 */
final readonly class SnippetContentTypeExtension implements ContentTypeExtensionInterface, NotSearchableContentTypeInterface
{
    public function __construct(
        private SnippetRepositoryInterface $repository,
        private SnippetSecurityContextResolver $snippetContextResolver,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function getResourceKey(): string
    {
        return 'snippets';
    }

    public function getTemplateType(): string
    {
        return 'snippet';
    }

    public function getViewSecurityContexts(): array
    {
        return $this->snippetContextResolver->candidates();
    }

    public function getSecurity(object $aggregate, string $locale): ContentSecurity
    {
        return new ContentSecurity($this->snippetContextResolver->forTemplateKey($this->templateKeyOf($aggregate, $locale)));
    }

    public function createRemoveMessage(string $uuid, string $locale, bool $forceRemoveChildren = false): object
    {
        return new RemoveSnippetMessage(['uuid' => $uuid], $locale);
    }

    public function loadDraft(string $uuid, string $locale, bool $loadGhost = false): ?object
    {
        try {
            return $this->repository->getOneBy([
                'uuid' => $uuid,
                'locale' => $locale,
                'stage' => DimensionContentInterface::STAGE_DRAFT,
                'loadGhost' => $loadGhost,
            ], [SnippetRepositoryInterface::GROUP_SELECT_SNIPPET_ADMIN => true]);
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
            ], [SnippetRepositoryInterface::SELECT_SNIPPET_CONTENT => [
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
        return new ModifySnippetMessage(['uuid' => $uuid], $data);
    }

    public function createTransitionMessage(string $uuid, string $locale, string $transition): object
    {
        return new ApplyWorkflowTransitionSnippetMessage(['uuid' => $uuid], $locale, $transition);
    }

    /**
     * A locale the snippet has content in resolves to its own template, queried when the aggregate did not load it.
     * A ghost has none, so the group comes from the locale it is a ghost of.
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

        $source = \in_array($locale, $unlocalized?->getAvailableLocales() ?? [], true) ? $locale : $unlocalized?->getGhostLocale();
        if (null === $source) {
            return '';
        }

        return $loaded[$source] ?? $this->queryTemplateKey($aggregate, $source) ?? '';
    }

    private function queryTemplateKey(object $aggregate, string $locale): ?string
    {
        $dimensionContent = $this->entityManager->getRepository(SnippetDimensionContentInterface::class)->findOneBy([
            'snippet' => $aggregate,
            'locale' => $locale,
            'stage' => DimensionContentInterface::STAGE_DRAFT,
        ]);

        return $dimensionContent instanceof TemplateInterface ? $dimensionContent->getTemplateKey() : null;
    }
}
