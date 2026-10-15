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

use Sulu\Mcp\Domain\Exception\ClientMetadataException;

/**
 * Only public clients are accepted: the document cannot carry a secret, and the authorization server
 * demands PKCE from every public client.
 *
 * @internal
 */
class ClientMetadataValidator
{
    public const MAX_NAME_LENGTH = 128;
    public const MAX_REDIRECT_URIS = 10;
    public const MAX_REDIRECT_URI_LENGTH = 2000;

    private const FORBIDDEN_FIELDS = ['client_secret', 'client_secret_expires_at', 'jwks', 'jwks_uri'];

    /**
     * @throws ClientMetadataException
     */
    public function validate(string $url, string $json): ClientMetadata
    {
        try {
            $document = \json_decode($json, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ClientMetadataException('The metadata document is not valid JSON.');
        }

        if (!\is_array($document) || \array_is_list($document)) {
            throw new ClientMetadataException('The metadata document must be a JSON object.');
        }

        // exact comparison, no normalisation: a document must not speak for another URL
        if (($document['client_id'] ?? null) !== $url) {
            throw new ClientMetadataException('The client_id in the metadata document does not match the URL it was fetched from.');
        }

        $name = $document['client_name'] ?? null;
        // control, format and line separator characters (newlines, bidi overrides) could disguise the name on the consent page
        $name = \is_string($name) ? \preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', '', $name) : null;
        if (!\is_string($name) || '' === \trim($name)) {
            throw new ClientMetadataException('client_name is required.');
        }

        foreach (self::FORBIDDEN_FIELDS as $field) {
            if (\array_key_exists($field, $document)) {
                throw new ClientMetadataException(\sprintf('"%s" is not allowed: only public clients are supported.', $field));
            }
        }

        $authMethod = $document['token_endpoint_auth_method'] ?? 'none';
        if ('none' !== $authMethod) {
            throw new ClientMetadataException('token_endpoint_auth_method must be "none": only public clients are supported.');
        }

        if (\array_key_exists('grant_types', $document)) {
            $grantTypes = $document['grant_types'];
            // other grants a client lists (Claude adds jwt-bearer) are ignored: the stored client only ever gets these two
            if (!\is_array($grantTypes) || !\array_is_list($grantTypes) || !\in_array('authorization_code', $grantTypes, true)) {
                throw new ClientMetadataException('grant_types must include authorization_code.');
            }
        }

        if (\array_key_exists('response_types', $document) && ['code'] !== $document['response_types']) {
            throw new ClientMetadataException('response_types may only contain "code".');
        }

        return new ClientMetadata($url, \mb_substr(\trim($name), 0, self::MAX_NAME_LENGTH), $this->redirectUris($document['redirect_uris'] ?? null));
    }

    /**
     * @return list<non-empty-string>
     *
     * @throws ClientMetadataException
     */
    private function redirectUris(mixed $value): array
    {
        if (!\is_array($value) || !\array_is_list($value) || [] === $value) {
            throw new ClientMetadataException('redirect_uris is required and must be a non-empty list.');
        }

        if (\count($value) > self::MAX_REDIRECT_URIS) {
            throw new ClientMetadataException(\sprintf('redirect_uris may contain at most %d entries.', self::MAX_REDIRECT_URIS));
        }

        foreach ($value as $uri) {
            if (!\is_string($uri) || '' === $uri || \strlen($uri) > self::MAX_REDIRECT_URI_LENGTH || !RedirectUriPolicy::isAllowed($uri)) {
                throw new ClientMetadataException('redirect_uris must be HTTPS or HTTP loopback URIs, or private-use schemes, without fragments.');
            }
        }

        return \array_values(\array_unique($value));
    }
}
