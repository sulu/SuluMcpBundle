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

/**
 * Validates the URL routing fields passed in article create/update content.
 *
 * Sulu article templates use either a simple `route` property (flat URL string)
 * or a `page_tree_route` property (nested page reference + suffix). The MCP
 * layer used to forward both forms to Sulu without checking, which caused:
 *   - create silently returning `url: null` when the wrong form was used,
 *   - update throwing a cryptic `extractRouteSlug()` type error from vendor.
 *
 * This validator runs on the MCP side so the LLM gets an actionable message
 * before the message hits Sulu's RoutableDataMapper.
 *
 * @internal
 */
final class ArticleRouteValidator
{
    /**
     * Validate a routing payload supplied to article create/update.
     *
     * @param array<string, mixed> $content  Normalized article content
     * @param bool                 $required When true (create), having no routing form is an error
     *
     * @return array<string, mixed>|null Error payload, or null when the content is valid
     */
    public static function validate(array $content, bool $required): ?array
    {
        $hasUrl = \array_key_exists('url', $content);
        $hasPage = \array_key_exists('page', $content);

        if (!$hasUrl && !$hasPage) {
            if (!$required) {
                return null;
            }

            return self::error(
                'Article content is missing routing data. Pass either content={"url": "/my-article"} (simple route templates) or content={"page": {"path": "/blog", "uuid": "<page-uuid>", "suffix": "my-article"}} (page_tree_route templates). Call sulu_get_context to see which form your template expects -- look for a field of type "route" or "page_tree_route" in the template schema.'
            );
        }

        if ($hasUrl && $hasPage) {
            return self::error(
                'Article content has both "url" and "page" routing fields. Pass exactly one form depending on the template (use sulu_get_context to check).'
            );
        }

        if ($hasUrl) {
            $url = $content['url'];
            if (\is_array($url)) {
                return self::validatePageTreeRoute($url, 'url');
            }

            if (!\is_string($url) || '' === $url) {
                return self::error('Article content.url must be a non-empty string, e.g. "/my-article", or a page_tree_route object.');
            }
            if (!\str_starts_with($url, '/')) {
                return self::error(\sprintf('Article content.url must start with "/". Got: %s', $url));
            }

            return null;
        }

        return self::validatePageTreeRouteAlias($content['page']);
    }

    /**
     * Convert the MCP-friendly page_tree_route alias to Sulu's actual template field shape.
     *
     * Sulu templates still name the route property `url`, even when its type is
     * `page_tree_route`. MCP clients may pass the more natural alias
     * content.page={path, uuid, suffix}; before dispatching to Sulu, that needs
     * to become content.url={page: {path, uuid}, suffix}.
     *
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    public static function normalizeForSulu(array $content): array
    {
        if (!\array_key_exists('page', $content) || \array_key_exists('url', $content)) {
            return $content;
        }

        $page = $content['page'];
        if (!\is_array($page)) {
            return $content;
        }

        unset($content['page']);
        $content['url'] = [
            'page' => [
                'path' => $page['path'] ?? null,
                'uuid' => $page['uuid'] ?? null,
            ],
            'suffix' => $page['suffix'] ?? null,
        ];

        return $content;
    }

    /**
     * After a create call, check whether routing actually resolved.
     *
     * Sulu silently produces `url: null` when the supplied routing form does
     * not match the template's route property type. Catch that here and turn
     * it into a clear error the LLM can act on.
     *
     * @param array<string, mixed> $normalizedArticle Output of ContentManager::normalize()
     * @param array<string, mixed> $content           The routing content the caller supplied
     *
     * @return array<string, string>|null
     */
    public static function assertRoutingResolved(array $normalizedArticle, array $content): ?array
    {
        if (!\array_key_exists('url', $content) && !\array_key_exists('page', $content)) {
            return null;
        }

        $resolvedUrl = $normalizedArticle['url'] ?? null;
        if (\is_string($resolvedUrl) && '' !== $resolvedUrl) {
            return null;
        }
        if (\is_array($resolvedUrl)) {
            $page = $resolvedUrl['page'] ?? null;
            $suffix = $resolvedUrl['suffix'] ?? null;
            if (\is_array($page) && \is_string($suffix) && '' !== $suffix) {
                return null;
            }
        }

        if (\array_key_exists('url', $content)) {
            $suggestion = \is_string($content['url'] ?? null)
                ? 'Tried content.url as a simple route but the template likely uses page_tree_route. Retry with content={"page": {"path": "...", "uuid": "...", "suffix": "..."}}.'
                : 'Tried content.url as a page_tree_route object but the template likely uses a simple route. Retry with content={"url": "/<full-path>"}.';
        } else {
            $suggestion = 'Tried content.page as a page_tree_route but the template likely uses a simple route. Retry with content={"url": "/<full-path>"}.';
        }

        return self::error(
            'Article was created but routing was dropped (url resolved to null). This usually means the URL form does not match the template\'s route property type. '.$suggestion.' Call sulu_get_context to inspect the template field types.'
        );
    }

    /** @return array<string, mixed>|null */
    private static function validatePageTreeRouteAlias(mixed $page): ?array
    {
        if (!\is_array($page)) {
            return self::error('Article content.page must be an object with keys "path", "uuid", and "suffix".');
        }

        foreach (['path', 'uuid', 'suffix'] as $key) {
            $value = $page[$key] ?? null;
            if (!\is_string($value) || '' === $value) {
                return self::error(\sprintf(
                    'Article content.page.%s must be a non-empty string. Required keys: path (parent URL), uuid (parent page UUID), suffix (article slug).',
                    $key,
                ));
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $route
     *
     * @return array<string, mixed>|null
     */
    private static function validatePageTreeRoute(array $route, string $field): ?array
    {
        $page = $route['page'] ?? null;
        if (!\is_array($page)) {
            return self::error(\sprintf('Article content.%s.page must be an object with keys "path" and "uuid".', $field));
        }

        foreach (['path', 'uuid'] as $key) {
            $value = $page[$key] ?? null;
            if (!\is_string($value) || '' === $value) {
                return self::error(\sprintf('Article content.%s.page.%s must be a non-empty string.', $field, $key));
            }
        }

        $suffix = $route['suffix'] ?? null;
        if (!\is_string($suffix) || '' === $suffix) {
            return self::error(\sprintf('Article content.%s.suffix must be a non-empty string.', $field));
        }

        return null;
    }

    /** @return array<string, string> */
    private static function error(string $message): array
    {
        return [
            'error' => $message,
            'hint' => 'Use sulu_get_context to inspect template fields. Look for a property with type "route" (use content.url) or "page_tree_route" (use content.page).',
        ];
    }
}
