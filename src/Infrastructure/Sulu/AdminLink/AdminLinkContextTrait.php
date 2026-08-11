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

namespace Sulu\Mcp\Infrastructure\Sulu\AdminLink;

use Sulu\Bundle\AdminBundle\Admin\View\ViewRegistry;

/**
 * @internal
 */
trait AdminLinkContextTrait
{
    /**
     * @param array<string, mixed> $context
     */
    private function requireString(array $context, string $key): ?string
    {
        $value = $context[$key] ?? null;

        return \is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function requireId(array $context, string $key): ?string
    {
        $value = $context[$key] ?? null;

        if (\is_int($value) && $value > 0) {
            return (string) $value;
        }

        return \is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * Resolve the admin SPA hash path for a registered Sulu admin view, with its
     * `:placeholder` segments replaced. The path template is read from Sulu's
     * view registry rather than hardcoded, so it stays in sync if Sulu changes
     * a route. Returns null when a placeholder is left unresolved.
     *
     * @param array<string, string> $replacements maps ":placeholder" to its value
     */
    private function resolveViewPath(ViewRegistry $viewRegistry, string $viewName, array $replacements): ?string
    {
        $path = \strtr($viewRegistry->findViewByName($viewName)->getPath(), $replacements);

        return \str_contains($path, ':') ? null : $path;
    }
}
