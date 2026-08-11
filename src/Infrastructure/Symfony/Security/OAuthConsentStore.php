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

namespace Sulu\Mcp\Infrastructure\Symfony\Security;

use League\Bundle\OAuth2ServerBundle\Event\AuthorizationRequestResolveEvent;
use Sulu\Mcp\Domain\Model\OAuthConsentRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * @internal
 */
final readonly class OAuthConsentStore
{
    public const REQUEST_ID_PARAMETER = 'sulu_mcp_consent';

    private const SESSION_KEY = '_sulu_mcp_oauth_consent';

    public function create(Request $request, AuthorizationRequestResolveEvent $event): OAuthConsentRequest
    {
        $id = \bin2hex(\random_bytes(16));
        $authorizationUrl = $this->authorizationUrl($request);
        $consentRequest = new OAuthConsentRequest(
            $id,
            $authorizationUrl,
            $this->withConsentRequestId($authorizationUrl, $id),
            $event->getClient()->getIdentifier(),
            $event->getClient()->getName(),
            $event->getRedirectUri(),
            \array_map(static fn (object $scope): string => (string) $scope, $event->getScopes()),
            $event->getState(),
        );

        $this->save($request, $consentRequest);

        return $consentRequest;
    }

    public function get(Request $request, string $id): ?OAuthConsentRequest
    {
        $entry = $this->entries($request)[$id] ?? null;
        if (!\is_array($entry)) {
            return null;
        }

        return OAuthConsentRequest::fromArray($entry);
    }

    public function decide(Request $request, string $id, bool $approved): ?OAuthConsentRequest
    {
        $consentRequest = $this->get($request, $id);
        if (!$consentRequest instanceof OAuthConsentRequest) {
            return null;
        }

        $consentRequest = $consentRequest->withApproved($approved);
        $this->save($request, $consentRequest);

        return $consentRequest;
    }

    public function consumeDecision(Request $request, string $id): ?bool
    {
        $consentRequest = $this->get($request, $id);
        if (!$consentRequest instanceof OAuthConsentRequest || null === $consentRequest->getApproved()) {
            return null;
        }

        $entries = $this->entries($request);
        unset($entries[$id]);
        $this->session($request)->set(self::SESSION_KEY, $entries);

        return $consentRequest->getApproved();
    }

    public function getRequestId(Request $request): ?string
    {
        $requestId = $request->query->get(self::REQUEST_ID_PARAMETER);

        return \is_string($requestId) && '' !== $requestId ? $requestId : null;
    }

    private function save(Request $request, OAuthConsentRequest $consentRequest): void
    {
        $entries = $this->entries($request);
        $entries[$consentRequest->getId()] = $consentRequest->toArray();
        $this->session($request)->set(self::SESSION_KEY, $entries);
    }

    /**
     * @return array<string, mixed>
     */
    private function entries(Request $request): array
    {
        $entries = $this->session($request)->get(self::SESSION_KEY, []);

        return \is_array($entries) ? $entries : [];
    }

    private function session(Request $request): SessionInterface
    {
        if (!$request->hasSession()) {
            throw new \LogicException('OAuth consent requires an admin session.');
        }

        return $request->getSession();
    }

    private function authorizationUrl(Request $request): string
    {
        $queryString = $request->server->get('QUERY_STRING', '');
        $queryString = \is_string($queryString) ? $this->withoutConsentRequestId($queryString) : '';

        return $request->getPathInfo().('' !== $queryString ? '?'.$queryString : '');
    }

    private function withConsentRequestId(string $authorizationUrl, string $id): string
    {
        $separator = \str_contains($authorizationUrl, '?') ? '&' : '?';

        return $authorizationUrl.$separator.\rawurlencode(self::REQUEST_ID_PARAMETER).'='.\rawurlencode($id);
    }

    private function withoutConsentRequestId(string $queryString): string
    {
        $queryParameters = [];

        foreach (\explode('&', $queryString) as $queryParameter) {
            if ('' === $queryParameter) {
                continue;
            }

            $name = \explode('=', $queryParameter, 2)[0];
            if (self::REQUEST_ID_PARAMETER === \urldecode($name)) {
                continue;
            }

            $queryParameters[] = $queryParameter;
        }

        return \implode('&', $queryParameters);
    }
}
