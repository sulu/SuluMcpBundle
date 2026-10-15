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

namespace Sulu\Mcp\Application\OAuth;

/**
 * Which redirect URIs an OAuth client may use: HTTPS, an HTTP loopback address (RFC 8252
 * section 7.3) or a private-use scheme for native apps (section 7.1), never with a fragment.
 *
 * @internal
 */
final class RedirectUriPolicy
{
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1'];

    public static function isAllowed(string $uri): bool
    {
        $parts = \parse_url($uri);
        if (false === $parts || !isset($parts['scheme']) || isset($parts['fragment'])) {
            return false;
        }

        $scheme = \strtolower($parts['scheme']);

        if ('https' === $scheme) {
            return isset($parts['host']);
        }

        if ('http' === $scheme) {
            return isset($parts['host'])
                && \in_array(\strtolower(\trim($parts['host'], '[]')), self::LOOPBACK_HOSTS, true);
        }

        // Private-use URI scheme for native apps (RFC 8252), e.g. "com.example.app:/oauth".
        return 1 === \preg_match('/^[a-z][a-z0-9+-]*(\.[a-z0-9+-]+)+$/', $scheme);
    }
}
