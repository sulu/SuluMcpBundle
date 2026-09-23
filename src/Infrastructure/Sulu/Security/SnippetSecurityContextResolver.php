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

use Sulu\Bundle\AdminBundle\Metadata\GroupProviderInterface;
use Sulu\Mcp\Application\Security\ToolContextResolverInterface;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Sulu\Snippet\Infrastructure\Sulu\Admin\SnippetAdmin;

/**
 * Resolves a snippet's per-group security context:
 * `sulu.snippet.snippets` for the default/only group, else
 * `sulu.snippet.snippets_<groupIdentifier>`.
 *
 * Group contexts only exist from Sulu 3.1 on. Sulu 3.0 already parses `<group>`
 * on snippet templates but registers the base context alone, so there every
 * snippet resolves to `sulu.snippet.snippets`.
 *
 * @internal
 */
final readonly class SnippetSecurityContextResolver implements ToolContextResolverInterface
{
    /**
     * Sentinel for objectResolved snippet tools: the real context is only known once
     * the entity is loaded, so the coarse check asks "any snippet group?" rather than
     * pinning the base group. The `#` keeps it fail-closed if checked directly.
     */
    public const ANY_SNIPPET_GROUP_CONTEXT = 'sulu.snippet.#any#';

    private const BASE_CONTEXT = 'sulu.snippet.snippets';

    private bool $groupContexts;

    /**
     * @param bool|null $groupContexts whether core registers per-group snippet contexts; detected when null
     */
    public function __construct(
        private GroupProviderInterface $groupProvider,
        ?bool $groupContexts = null,
    ) {
        $this->groupContexts = $groupContexts ?? \method_exists(SnippetAdmin::class, 'getSnippetSecurityContext'); // @phpstan-ignore function.alreadyNarrowedType (false on sulu/sulu 3.0)
    }

    public function resolve(array $arguments): string
    {
        $template = $arguments['template'] ?? null;

        return \is_string($template) ? $this->forTemplateKey($template) : self::BASE_CONTEXT;
    }

    public function forTemplateKey(string $templateKey): string
    {
        if (!$this->groupContexts) {
            return self::BASE_CONTEXT;
        }

        $groups = $this->groupProvider->getGroups(SnippetInterface::TEMPLATE_TYPE);
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

    public function candidates(): array
    {
        if (!$this->groupContexts) {
            return [self::BASE_CONTEXT];
        }

        $groups = $this->groupProvider->getGroups(SnippetInterface::TEMPLATE_TYPE);
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
