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

namespace Sulu\Mcp\UserInterface\Controller\Admin;

use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RFC 7591 — OAuth 2.0 Dynamic Client Registration, so MCP clients can register
 * themselves without a pre-provisioned client_id/secret.
 *
 * @internal
 */
class DynamicClientRegistrationController
{
    private const ALLOWED_GRANT_TYPES = ['authorization_code', 'refresh_token'];
    private const ALLOWED_TOKEN_ENDPOINT_AUTH_METHODS = ['client_secret_post', 'client_secret_basic', 'none'];
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1'];

    /**
     * @param list<string> $allowedScopes
     */
    public function __construct(
        private readonly ClientManagerInterface $clientManager,
        private readonly array $allowedScopes = ['mcp:tools', 'mcp:resources'],
    ) {
    }

    #[Route('/mcp/register', name: 'sulu_mcp_client_registration', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        try {
            $body = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->invalidClientMetadata('Request body must be valid JSON.');
        }

        if (!\is_array($body)) {
            return $this->invalidClientMetadata('Request body must be a JSON object.');
        }

        $redirectUris = $this->listOfStrings($body['redirect_uris'] ?? null);
        if (null === $redirectUris || [] === $redirectUris) {
            return $this->invalidClientMetadata('redirect_uris is required.');
        }

        foreach ($redirectUris as $redirectUri) {
            if (!$this->isAllowedRedirectUri($redirectUri)) {
                return $this->invalidClientMetadata(\sprintf('Invalid redirect URI "%s". Use HTTPS or an HTTP loopback URI, without fragments.', $redirectUri));
            }
        }

        $clientName = $body['client_name'] ?? 'MCP Client';
        if (!\is_string($clientName) || '' === $clientName) {
            return $this->invalidClientMetadata('client_name must be a non-empty string.');
        }

        $grantTypes = $this->listOfStrings($body['grant_types'] ?? ['authorization_code']);
        if (null === $grantTypes || [] === $grantTypes) {
            return $this->invalidClientMetadata('grant_types must be a non-empty list of strings.');
        }
        $unsupportedGrants = \array_values(\array_diff($grantTypes, self::ALLOWED_GRANT_TYPES));
        if ([] !== $unsupportedGrants) {
            return $this->invalidClientMetadata(\sprintf('Unsupported grant type "%s".', $unsupportedGrants[0]));
        }
        $grantTypes = \array_values(\array_unique([...$grantTypes, 'refresh_token']));

        $tokenEndpointAuthMethod = $body['token_endpoint_auth_method'] ?? 'client_secret_post';
        if (!\is_string($tokenEndpointAuthMethod) || '' === $tokenEndpointAuthMethod) {
            return $this->invalidClientMetadata('token_endpoint_auth_method must be a non-empty string.');
        }

        if (!\in_array($tokenEndpointAuthMethod, self::ALLOWED_TOKEN_ENDPOINT_AUTH_METHODS, true)) {
            return $this->invalidClientMetadata(\sprintf('Unsupported token_endpoint_auth_method "%s".', $tokenEndpointAuthMethod));
        }

        $scopes = $this->requestedScopes($body['scope'] ?? null);
        if (null === $scopes) {
            return $this->invalidClientMetadata('scope must be a string.');
        }
        if ([] === $scopes) {
            return $this->invalidClientMetadata('scope must include at least one supported scope.');
        }

        $clientId = bin2hex(random_bytes(16));
        $clientSecret = 'none' === $tokenEndpointAuthMethod ? null : bin2hex(random_bytes(32));

        $client = new Client($clientName, $clientId, $clientSecret);

        $client->setRedirectUris(...array_map(
            static fn (string $uri) => new RedirectUri($uri),
            $redirectUris,
        ));

        $client->setGrants(...array_map(
            static fn (string $grant) => new Grant($grant),
            $grantTypes,
        ));

        $client->setScopes(...array_map(static fn (string $scope) => new Scope($scope), $scopes));

        $this->clientManager->save($client);

        $payload = [
            'client_id' => $clientId,
            'client_name' => $clientName,
            'redirect_uris' => $redirectUris,
            'grant_types' => $grantTypes,
            'token_endpoint_auth_method' => $tokenEndpointAuthMethod,
            'scope' => \implode(' ', $scopes),
        ];

        if (null !== $clientSecret) {
            $payload['client_secret'] = $clientSecret;
        }

        return new JsonResponse($payload, Response::HTTP_CREATED);
    }

    private function invalidClientMetadata(string $description): JsonResponse
    {
        return new JsonResponse(
            ['error' => 'invalid_client_metadata', 'error_description' => $description],
            Response::HTTP_BAD_REQUEST,
        );
    }

    /**
     * @return list<string>|null
     */
    private function listOfStrings(mixed $value): ?array
    {
        if (!\is_array($value) || array_is_list($value)) {
            return $this->normalizeStringList($value);
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    private function normalizeStringList(mixed $value): ?array
    {
        if (\is_string($value)) {
            $value = [$value];
        }

        if (!\is_array($value)) {
            return null;
        }

        $strings = [];
        foreach ($value as $item) {
            if (!\is_string($item) || '' === $item) {
                return null;
            }

            $strings[] = $item;
        }

        return $strings;
    }

    private function isAllowedRedirectUri(string $uri): bool
    {
        $parts = parse_url($uri);
        if (false === $parts || !isset($parts['scheme']) || isset($parts['fragment'])) {
            return false;
        }

        $scheme = \strtolower($parts['scheme']);

        if ('https' === $scheme) {
            return isset($parts['host']);
        }

        if ('http' === $scheme) {
            return isset($parts['host'])
                && \in_array($this->normalizeHost($parts['host']), self::LOOPBACK_HOSTS, true);
        }

        // Private-use URI scheme for native apps (RFC 8252), e.g. "com.example.app:/oauth".
        return str_contains($scheme, '.');
    }

    /**
     * @return list<string>|null
     */
    private function requestedScopes(mixed $scope): ?array
    {
        if (null === $scope || '' === $scope) {
            return $this->allowedScopes;
        }

        if (!\is_string($scope)) {
            return null;
        }

        $requested = \array_values(\array_unique(\array_filter(\explode(' ', $scope), static fn (string $part): bool => '' !== $part)));
        if ([] === $requested) {
            return $this->allowedScopes;
        }

        $granted = \array_values(\array_intersect($requested, $this->allowedScopes));

        return $granted;
    }

    private function normalizeHost(string $host): string
    {
        return \strtolower(\trim($host, '[]'));
    }
}
