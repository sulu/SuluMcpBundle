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

use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Infrastructure\Doctrine\DimensionContentQueryEnhancer;
use Sulu\Mcp\Domain\Content\ContentSecurity;
use Sulu\Mcp\Domain\Content\ContentTypeExtensionInterface;
use Sulu\Mcp\Domain\Content\NotSearchableContentTypeInterface;
use Sulu\Snippet\Application\Message\ApplyWorkflowTransitionSnippetMessage;
use Sulu\Snippet\Application\Message\ModifySnippetMessage;
use Sulu\Snippet\Application\Message\RemoveSnippetMessage;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;

/**
 * @internal
 */
final readonly class SnippetContentTypeExtension implements ContentTypeExtensionInterface, NotSearchableContentTypeInterface
{
    public const SECURITY_CONTEXT = 'sulu.snippet.snippets';

    public function __construct(
        private SnippetRepositoryInterface $repository,
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
        return [self::SECURITY_CONTEXT];
    }

    public function getSecurity(object $aggregate, string $locale): ContentSecurity
    {
        return new ContentSecurity(self::SECURITY_CONTEXT);
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
}
