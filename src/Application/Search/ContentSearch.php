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

namespace Sulu\Mcp\Application\Search;

use CmsIg\Seal\Search\Condition\Condition;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Application\Content\ContentTypeExtensionRegistry;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Domain\Content\ContentTypeExtensionInterface;
use Sulu\Mcp\Domain\Content\NotSearchableContentTypeInterface;

/**
 * Keyword search over the `website` index. A whitelist: an indexed resourceKey no extension
 * registered stays invisible instead of leaking to anyone with webspace VIEW.
 *
 * @internal
 */
final class ContentSearch
{
    public function __construct(
        private readonly WebsiteSearch $websiteSearch,
        private readonly WebspacePermissionResolver $webspacePermissionResolver,
        private readonly ToolPermissionCheckerInterface $permissionChecker,
        private readonly ContentTypeExtensionRegistry $extensionRegistry,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function search(
        string $query,
        string $locale,
        ?string $webspace = null,
        ?string $resourceKey = null,
        int $page = 1,
        int $limit = 20,
    ): array {
        // The `website` index carries only `webspaces`, no securityContext,
        // so per-object ACL filtering isn't possible here. Constraining to the webspaces
        // the caller may VIEW is the best available mirror.
        $permitted = $this->webspacePermissionResolver->permittedWebspaceKeys(PermissionTypes::VIEW, $locale);
        if ([] === $permitted) {
            return ['results' => [], 'total' => 0, 'hint' => 'No webspaces are readable with your permissions.'];
        }

        $effective = null !== $webspace ? \array_values(\array_intersect($permitted, [$webspace])) : $permitted;
        if ([] === $effective) {
            return ['results' => [], 'total' => 0, 'hint' => \sprintf('Webspace "%s" is not readable with your permissions.', $webspace)];
        }

        // A type without view contexts (pages) is governed by the webspace check above.
        $visibleResourceKeys = [];
        foreach ($this->extensionRegistry->searchable() as $extension) {
            if ($this->canView($extension, $locale)) {
                $visibleResourceKeys[] = $extension->getResourceKey();
            }
        }

        if (null !== $resourceKey && !\in_array($resourceKey, $visibleResourceKeys, true)) {
            $extension = $this->extensionRegistry->find($resourceKey);
            if (null !== $extension && !$extension instanceof NotSearchableContentTypeInterface) {
                $contexts = \array_map(static fn (string $context): string => \sprintf('"%s"', $context), $extension->getViewSecurityContexts());

                return [
                    'error' => 'Permission denied: no accessible security context grants the required permissions.',
                    'hint' => \sprintf('Requires VIEW on %s.', 1 === \count($contexts) ? $contexts[0] : 'one of ' . \implode(', ', $contexts)),
                ];
            }

            return [
                'error' => \sprintf('Unsupported content type "%s".', $resourceKey),
                'hint' => \sprintf('Supported: %s.', \implode(', ', $this->extensionRegistry->searchableResourceKeys())),
            ];
        }

        try {
            $builder = $this->websiteSearch->builder($locale, $query, $page, $limit)
                ->addFilter(Condition::in('webspaces', $effective));

            if (null !== $resourceKey) {
                $builder->addFilter(Condition::equal('resourceKey', $resourceKey));
            } else {
                $builder->addFilter(Condition::in('resourceKey', $visibleResourceKeys));
            }

            return $this->websiteSearch->run($builder, $page, $limit);
        } catch (\Throwable $e) {
            return [
                'error' => \sprintf('Content search failed: %s', $e->getMessage()),
                'hint' => \sprintf(
                    'Only published content is indexed. Verify the locale is correct and resourceKey is one of %s (or omit to search all).',
                    \implode(', ', \array_map(static fn (string $key): string => \sprintf('"%s"', $key), $this->extensionRegistry->searchableResourceKeys())),
                ),
            ];
        }
    }

    /**
     * The index carries no template, so VIEW on any one context (e.g. one article group) suffices.
     */
    private function canView(ContentTypeExtensionInterface $extension, string $locale): bool
    {
        $contexts = $extension->getViewSecurityContexts();
        if ([] === $contexts) {
            return true;
        }

        foreach ($contexts as $context) {
            if ($this->permissionChecker->has($context, PermissionTypes::VIEW, $locale)) {
                return true;
            }
        }

        return false;
    }
}
