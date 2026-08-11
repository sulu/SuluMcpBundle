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

use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;

/**
 * Decides whether a tool is shown at discovery time (`tools/list`,
 * `sulu_get_context`), mirroring the coarse check PermissionAwareCallToolHandler
 * runs at call time. Errs toward showing; the in-body check decides.
 *
 * @internal
 */
final readonly class ToolVisibilityResolver
{
    /**
     * @param array<string, array{name: string, requirements: list<array{context: string, permission: string}>, contextArgument: ?string, contextResolver: ?string, objectResolved: bool, discoveryContexts: list<string>}> $permissionMap
     * @param array<string, ToolContextResolverInterface>                                                                                                                                                                   $contextResolvers
     * @param list<string>                                                                                                                                                                                                  $allowlist
     */
    public function __construct(
        private array $permissionMap,
        private ToolPermissionCheckerInterface $permissionChecker,
        private WebspacePermissionResolver $webspacePermissionResolver,
        private ArticleSecurityContextResolver $articleContextResolver,
        private array $contextResolvers,
        private array $allowlist,
    ) {
    }

    public function isVisible(string $toolName, ?string $locale = null): bool
    {
        if (\in_array($toolName, $this->allowlist, true)) {
            return true;
        }

        $entry = $this->permissionMap[$toolName] ?? null;
        if (null === $entry || [] === $entry['requirements']) {
            return false; // fail-closed
        }

        $candidates = $this->candidatesFor($entry);
        // one candidate must grant EVERY requirement, as in the handler
        foreach ($candidates as $candidate) {
            $all = true;
            foreach ($entry['requirements'] as $requirement) {
                if (!$this->grants($candidate, $requirement['permission'], $locale)) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reports every requirement, not just the first: compound declarations are the
     * norm (delete needs view+delete), and reporting one understates what is needed.
     *
     * @return array{name: string, available: bool, requires: list<array{context: string, permission: string}>, reason: ?string}
     */
    public function describe(string $toolName, ?string $locale = null): array
    {
        $available = $this->isVisible($toolName, $locale);

        $entry = $this->permissionMap[$toolName] ?? null;
        $contextArgument = $entry['contextArgument'] ?? null;

        // The raw `#context#` template tells a client nothing about what to grant.
        $requirements = array_map(
            fn (array $requirement): array => [
                'context' => $this->renderContext($requirement['context'], $contextArgument),
                'permission' => $requirement['permission'],
            ],
            $entry['requirements'] ?? [],
        );

        $reason = null;
        if (!$available) {
            $reason = [] !== $requirements
                ? \sprintf(
                    'Your Sulu role does not grant %s.',
                    implode(' and ', array_map(
                        static fn (array $requirement): string => \sprintf('"%s" on "%s"', $requirement['permission'], $requirement['context']),
                        $requirements,
                    )),
                )
                : \sprintf('Tool "%s" has no permission declaration and is not allowlisted.', $toolName);
        }

        return [
            'name' => $toolName,
            'available' => $available,
            'requires' => $requirements,
            'reason' => $reason,
        ];
    }

    /**
     * The full catalogue (permission map plus allowlist), with denial reasons.
     *
     * @return list<array{name: string, available: bool, requires: list<array{context: string, permission: string}>, reason: ?string}>
     */
    public function describeAll(?string $locale = null): array
    {
        $names = array_unique([...array_keys($this->permissionMap), ...$this->allowlist]);
        sort($names);

        return array_map(fn (string $name): array => $this->describe($name, $locale), $names);
    }

    /**
     * `#context#` is substituted from the call arguments, so it is rendered here as
     * a readable placeholder rather than reported verbatim.
     */
    private function renderContext(string $context, ?string $contextArgument): string
    {
        if (!str_contains($context, '#context#')) {
            return $context;
        }

        if ('#context#' === $context) {
            return '<the security context of the target item>';
        }

        $hint = $contextArgument ?? (str_starts_with($context, 'sulu.webspaces.') ? 'webspace' : 'resolved-at-call-time');

        return str_replace('#context#', '<'.$hint.'>', $context);
    }

    private function grants(string $candidate, string $permission, ?string $locale): bool
    {
        return match ($candidate) {
            WebspacePermissionResolver::ANY_WEBSPACE_CONTEXT => [] !== $this->webspacePermissionResolver->permittedWebspaceKeys($permission, $locale),
            ArticleSecurityContextResolver::ANY_ARTICLE_GROUP_CONTEXT => $this->anyArticleGroupGrants($permission, $locale),
            default => $this->permissionChecker->has($candidate, $permission, $locale),
        };
    }

    /**
     * Expands the article-group sentinel, so a user holding only a non-default
     * group still passes.
     */
    private function anyArticleGroupGrants(string $permission, ?string $locale): bool
    {
        foreach ($this->articleContextResolver->candidates() as $context) {
            if ($this->permissionChecker->has($context, $permission, $locale)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Candidate contexts for a map entry, covering all four tool shapes.
     *
     * @param array{requirements: list<array{context: string, permission: string}>, contextResolver: ?string, objectResolved: bool, discoveryContexts: list<string>} $entry
     *
     * @return list<string>
     */
    private function candidatesFor(array $entry): array
    {
        if ($entry['objectResolved']) {
            return $entry['discoveryContexts']; // may include the ANY_WEBSPACE sentinel
        }

        if (null !== $entry['contextResolver'] && isset($this->contextResolvers[$entry['contextResolver']])) {
            return $this->contextResolvers[$entry['contextResolver']]->candidates();
        }

        // webspace-arg tools: '#context#' in a webspace template => any webspace
        $out = [];
        foreach ($entry['requirements'] as $requirement) {
            $out[] = str_contains($requirement['context'], '#')
                ? WebspacePermissionResolver::ANY_WEBSPACE_CONTEXT
                : $requirement['context']; // static literal context
        }

        return array_values(array_unique($out));
    }
}
