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

namespace Sulu\Mcp\Infrastructure\League\Grant;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\Uri\Exceptions\SyntaxError;
use League\Uri\Uri;
use Psr\Http\Message\ServerRequestInterface;
use Sulu\Mcp\Infrastructure\League\Manager\CimdClientManager;

/**
 * league ignores the port of a loopback redirect URI only for 127.0.0.1 and [::1]. Native clients
 * such as Claude Code register http://localhost/callback and then listen on a random port, so
 * RFC 8252 section 7.3 needs the same leniency for localhost. It applies to clients that identify
 * themselves by URL only: a client registered by DCR or by sulu:mcp:create-client keeps league's
 * exact comparison, because RFC 8252 prefers IP literals and localhost may not resolve to loopback.
 *
 * @internal
 */
final class LoopbackAuthCodeGrant extends AuthCodeGrant
{
    protected function validateRedirectUri(string $redirectUri, ClientEntityInterface $client, ServerRequestInterface $request): void
    {
        if (!CimdClientManager::isStoredIdentifier($client->getIdentifier()) || !$this->matchesLocalhostIgnoringPort($redirectUri, $client->getRedirectUri())) {
            parent::validateRedirectUri($redirectUri, $client, $request);
        }
    }

    /**
     * @param string|string[] $registered
     */
    private function matchesLocalhostIgnoringPort(string $redirectUri, array|string $registered): bool
    {
        $requested = self::localhostWithoutPort($redirectUri);
        if (null === $requested) {
            return false;
        }

        foreach ((array) $registered as $candidate) {
            if (self::localhostWithoutPort($candidate) === $requested) {
                return true;
            }
        }

        return false;
    }

    private static function localhostWithoutPort(string $uri): ?string
    {
        try {
            $parsed = Uri::new($uri);
        } catch (SyntaxError) {
            return null;
        }

        if ('http' !== $parsed->getScheme() || 'localhost' !== $parsed->getHost()) {
            return null;
        }

        return (string) $parsed->withPort(null);
    }
}
