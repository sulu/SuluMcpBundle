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

namespace Sulu\Mcp\Infrastructure\Sulu\Security;

use Sulu\Article\Domain\Model\ArticleInterface;
use Sulu\Bundle\AdminBundle\Metadata\GroupProviderInterface;
use Sulu\Mcp\Application\Security\ToolContextResolverInterface;

/**
 * Resolves an article's per-group security context:
 * `sulu.article.articles` for the default/only group, else
 * `sulu.article.articles_<groupIdentifier>`.
 *
 * @internal
 */
final readonly class ArticleSecurityContextResolver implements ToolContextResolverInterface
{
    /**
     * Sentinel for objectResolved article tools: the real context is only known once
     * the entity is loaded, so the coarse check asks "any article group?" rather than
     * pinning the base group. The `#` keeps it fail-closed if checked directly.
     */
    public const ANY_ARTICLE_GROUP_CONTEXT = 'sulu.article.#any#';

    private const BASE_CONTEXT = 'sulu.article.articles';

    public function __construct(
        private GroupProviderInterface $groupProvider,
    ) {
    }

    public function resolve(array $arguments): string
    {
        $template = $arguments['template'] ?? null;

        return \is_string($template) ? $this->forTemplateKey($template) : self::BASE_CONTEXT;
    }

    public function forTemplateKey(string $templateKey): string
    {
        $groups = $this->groupProvider->getGroups(ArticleInterface::TEMPLATE_TYPE);
        if (\count($groups) <= 1) {
            return self::BASE_CONTEXT;
        }

        foreach ($groups as $group) {
            if (\in_array($templateKey, $group->templates, true)) {
                return 'default' === $group->identifier
                    ? self::BASE_CONTEXT
                    : \sprintf('%s_%s', self::BASE_CONTEXT, $group->identifier);
            }
        }

        // Multi-group install, no group claims this template key: the context is
        // unresolvable. Fail closed instead of falling back to the base group.
        return '';
    }

    /**
     * Security context => the template keys it owns, so a caller can constrain a
     * query to the templates the user may read instead of filtering rows after.
     *
     * @return array<string, list<string>>
     */
    public function templateKeysByContext(): array
    {
        $groups = $this->groupProvider->getGroups(ArticleInterface::TEMPLATE_TYPE);

        $map = [];
        foreach ($groups as $group) {
            $context = (\count($groups) <= 1 || 'default' === $group->identifier)
                ? self::BASE_CONTEXT
                : \sprintf('%s_%s', self::BASE_CONTEXT, $group->identifier);

            $map[$context] = [...($map[$context] ?? []), ...\array_values($group->templates)];
        }

        return $map;
    }

    public function candidates(): array
    {
        $groups = $this->groupProvider->getGroups(ArticleInterface::TEMPLATE_TYPE);
        if (\count($groups) <= 1) {
            return [self::BASE_CONTEXT];
        }

        $contexts = [self::BASE_CONTEXT];
        foreach ($groups as $group) {
            $contexts[] = 'default' === $group->identifier
                ? self::BASE_CONTEXT
                : \sprintf('%s_%s', self::BASE_CONTEXT, $group->identifier);
        }

        return \array_values(\array_unique($contexts));
    }
}
