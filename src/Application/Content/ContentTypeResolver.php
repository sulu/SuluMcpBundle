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

namespace Sulu\Mcp\Application\Content;

use Sulu\Article\Application\Message\ApplyWorkflowTransitionArticleMessage;
use Sulu\Article\Application\Message\ModifyArticleMessage;
use Sulu\Article\Application\Message\RemoveArticleMessage;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Infrastructure\Doctrine\DimensionContentQueryEnhancer;
use Sulu\Page\Application\Message\ApplyWorkflowTransitionPageMessage;
use Sulu\Page\Application\Message\ModifyPageMessage;
use Sulu\Page\Application\Message\RemovePageMessage;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Sulu\Product\Application\Message\ApplyWorkflowTransitionProductMessage;
use Sulu\Product\Application\Message\ModifyProductMessage;
use Sulu\Product\Application\Message\RemoveProductMessage;
use Sulu\Product\Domain\Repository\ProductRepositoryInterface;
use Sulu\Snippet\Application\Message\ApplyWorkflowTransitionSnippetMessage;
use Sulu\Snippet\Application\Message\ModifySnippetMessage;
use Sulu\Snippet\Application\Message\RemoveSnippetMessage;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;

/**
 * Resolves the content-type-specific parts of block operations — loading the
 * draft entity and building the right modify message — so block tools can be
 * written once and dispatch over page / article / snippet via a `type` string.
 *
 * Everything else (content resolve/normalize, block-tree manipulation) is already
 * type-agnostic and stays in the tools.
 *
 * @internal
 */
final readonly class ContentTypeResolver
{
    /**
     * @var list<string>
     */
    private const SUPPORTED_TYPES = ['page', 'article', 'snippet'];

    private const PRODUCT_TYPE = 'product';

    public function __construct(
        private PageRepositoryInterface $pageRepository,
        private ArticleRepositoryInterface $articleRepository,
        private SnippetRepositoryInterface $snippetRepository,
        private ?ProductRepositoryInterface $productRepository = null,
    ) {
    }

    public function supports(string $type): bool
    {
        return \in_array($type, $this->supportedTypes(), true);
    }

    /**
     * @return list<string>
     */
    public function supportedTypes(): array
    {
        if (null === $this->productRepository) {
            return self::SUPPORTED_TYPES;
        }

        return [...self::SUPPORTED_TYPES, self::PRODUCT_TYPE];
    }

    /**
     * Load the draft aggregate for the given content type, or null when the type
     * is unsupported or no matching entity exists.
     *
     * $loadGhost also matches an entity in a locale it has no content in. It is opt-in:
     * the returned aggregate spans every locale, so a caller that does not check for a
     * missing translation with ContentLocaleTrait would act on all of them.
     */
    public function loadDraft(string $type, string $uuid, string $locale, bool $loadGhost = false): ?object
    {
        $filters = [
            'uuid' => $uuid,
            'locale' => $locale,
            'stage' => DimensionContentInterface::STAGE_DRAFT,
            'loadGhost' => $loadGhost,
        ];

        try {
            return match ($type) {
                'page' => $this->pageRepository->getOneBy($filters, [PageRepositoryInterface::GROUP_SELECT_PAGE_ADMIN => true]),
                'article' => $this->articleRepository->getOneBy($filters, [ArticleRepositoryInterface::GROUP_SELECT_ARTICLE_ADMIN => true]),
                'snippet' => $this->snippetRepository->getOneBy($filters, [SnippetRepositoryInterface::GROUP_SELECT_SNIPPET_ADMIN => true]),
                self::PRODUCT_TYPE => $this->productRepository?->getOneBy($filters, [ProductRepositoryInterface::GROUP_SELECT_PRODUCT_ADMIN => true]),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Load the aggregate for a workflow transition, dimension contents hydrated for draft *and*
     * live: the handler re-queries this same instance from the identity map, and Doctrine leaves
     * an initialized collection alone, so a draft-only aggregate duplicates the live rows.
     */
    public function loadForTransition(string $type, string $uuid, string $locale): ?object
    {
        $filters = [
            'uuid' => $uuid,
            'locale' => $locale,
            'stage' => DimensionContentInterface::STAGE_DRAFT,
        ];

        $contentSelect = [
            'selects' => [DimensionContentQueryEnhancer::GROUP_SELECT_CONTENT_ADMIN => true],
            'dimensionAttributes' => [
                'locale' => $locale,
                'stage' => [DimensionContentInterface::STAGE_DRAFT, DimensionContentInterface::STAGE_LIVE],
            ],
        ];

        try {
            return match ($type) {
                'page' => $this->pageRepository->getOneBy($filters, [PageRepositoryInterface::SELECT_PAGE_CONTENT => $contentSelect]),
                'article' => $this->articleRepository->getOneBy($filters, [ArticleRepositoryInterface::SELECT_ARTICLE_CONTENT => $contentSelect]),
                'snippet' => $this->snippetRepository->getOneBy($filters, [SnippetRepositoryInterface::SELECT_SNIPPET_CONTENT => $contentSelect]),
                self::PRODUCT_TYPE => $this->productRepository?->getOneBy($filters, [ProductRepositoryInterface::SELECT_PRODUCT_CONTENT => $contentSelect]),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createModifyMessage(string $type, string $uuid, array $data): object
    {
        $this->assertSupported($type);

        return match ($type) {
            'page' => new ModifyPageMessage(['uuid' => $uuid], $data),
            'article' => new ModifyArticleMessage(['uuid' => $uuid], $data),
            'snippet' => new ModifySnippetMessage(['uuid' => $uuid], $data),
            self::PRODUCT_TYPE => new ModifyProductMessage(['uuid' => $uuid], $data), // @phpstan-ignore argument.type (message shape is validated by its own handler)
            default => throw new \LogicException('Unreachable: assertSupported() rejects every other type.'),
        };
    }

    /**
     * Build the per-type Remove message. `forceRemoveChildren` only affects pages
     * (which can have a subtree); article/snippet ignore it.
     */
    public function createRemoveMessage(string $type, string $uuid, string $locale, bool $forceRemoveChildren = false): object
    {
        $this->assertSupported($type);

        return match ($type) {
            'page' => new RemovePageMessage(['uuid' => $uuid], $locale, $forceRemoveChildren),
            'article' => new RemoveArticleMessage(['uuid' => $uuid], $locale),
            'snippet' => new RemoveSnippetMessage(['uuid' => $uuid], $locale),
            self::PRODUCT_TYPE => new RemoveProductMessage(['uuid' => $uuid], $locale),
            default => throw new \LogicException('Unreachable: assertSupported() rejects every other type.'),
        };
    }

    /**
     * Build the per-type workflow transition message (e.g. 'publish' / 'unpublish').
     */
    public function createTransitionMessage(string $type, string $uuid, string $locale, string $transition): object
    {
        $this->assertSupported($type);

        return match ($type) {
            'page' => new ApplyWorkflowTransitionPageMessage(['uuid' => $uuid], $locale, $transition),
            'article' => new ApplyWorkflowTransitionArticleMessage(['uuid' => $uuid], $locale, $transition),
            'snippet' => new ApplyWorkflowTransitionSnippetMessage(['uuid' => $uuid], $locale, $transition),
            self::PRODUCT_TYPE => new ApplyWorkflowTransitionProductMessage(['uuid' => $uuid], $locale, $transition),
            default => throw new \LogicException('Unreachable: assertSupported() rejects every other type.'),
        };
    }

    /**
     * Checked against the live list, not the match arms below: those would otherwise build a
     * product message when SuluProductBundle is not installed.
     */
    private function assertSupported(string $type): void
    {
        if ($this->supports($type)) {
            return;
        }

        throw new \InvalidArgumentException(\sprintf(
            'Unsupported content type "%s". Supported: %s.',
            $type,
            \implode(', ', $this->supportedTypes()),
        ));
    }
}
