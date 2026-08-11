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

namespace Sulu\Mcp\Domain\Model;

/**
 * @internal
 */
final readonly class OAuthConsentRequest
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        private string $id,
        private string $authorizationUrl,
        private string $continuationUrl,
        private string $clientId,
        private string $clientName,
        private ?string $redirectUri,
        private array $scopes,
        private ?string $state,
        private ?bool $approved = null,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getAuthorizationUrl(): string
    {
        return $this->authorizationUrl;
    }

    public function getContinuationUrl(): string
    {
        return $this->continuationUrl;
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function getClientName(): string
    {
        return $this->clientName;
    }

    public function getRedirectUri(): ?string
    {
        return $this->redirectUri;
    }

    /**
     * @return list<string>
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function getState(): ?string
    {
        return $this->state;
    }

    public function getApproved(): ?bool
    {
        return $this->approved;
    }

    public function withApproved(bool $approved): self
    {
        return new self(
            $this->id,
            $this->authorizationUrl,
            $this->continuationUrl,
            $this->clientId,
            $this->clientName,
            $this->redirectUri,
            $this->scopes,
            $this->state,
            $approved,
        );
    }

    /**
     * @return array{
     *     id: string,
     *     authorization_url: string,
     *     continuation_url: string,
     *     client_id: string,
     *     client_name: string,
     *     redirect_uri: string|null,
     *     scopes: list<string>,
     *     state: string|null,
     *     approved: bool|null
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'authorization_url' => $this->authorizationUrl,
            'continuation_url' => $this->continuationUrl,
            'client_id' => $this->clientId,
            'client_name' => $this->clientName,
            'redirect_uri' => $this->redirectUri,
            'scopes' => $this->scopes,
            'state' => $this->state,
            'approved' => $this->approved,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        if (
            !\is_string($data['id'] ?? null)
            || !\is_string($data['authorization_url'] ?? null)
            || !\is_string($data['continuation_url'] ?? null)
            || !\is_string($data['client_id'] ?? null)
            || !\is_string($data['client_name'] ?? null)
        ) {
            return null;
        }

        $redirectUri = $data['redirect_uri'] ?? null;
        if (null !== $redirectUri && !\is_string($redirectUri)) {
            return null;
        }

        $scopes = $data['scopes'] ?? null;
        if (!\is_array($scopes) || $scopes !== \array_values($scopes)) {
            return null;
        }

        foreach ($scopes as $scope) {
            if (!\is_string($scope)) {
                return null;
            }
        }

        $state = $data['state'] ?? null;
        if (null !== $state && !\is_string($state)) {
            return null;
        }

        $approved = $data['approved'] ?? null;
        if (null !== $approved && !\is_bool($approved)) {
            return null;
        }

        return new self(
            $data['id'],
            $data['authorization_url'],
            $data['continuation_url'],
            $data['client_id'],
            $data['client_name'],
            $redirectUri,
            $scopes,
            $state,
            $approved,
        );
    }
}
