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

use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Mcp\Domain\Model\OAuthConsentRequest;
use Sulu\Mcp\Infrastructure\Symfony\Security\OAuthConsentStore;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @internal
 */
final readonly class OAuthConsentController
{
    public function __construct(
        private OAuthConsentStore $consentStore,
        private TranslatorInterface $translator,
        private Security $security,
    ) {
    }

    #[Route(
        '/mcp/consent/{requestId}',
        name: 'sulu_mcp_oauth_consent_details',
        options: ['expose' => true],
        methods: ['GET'],
    )]
    public function details(Request $request, string $requestId): JsonResponse
    {
        $consentRequest = $this->consentStore->get($request, $requestId);
        if (!$consentRequest instanceof OAuthConsentRequest) {
            return $this->notFoundResponse();
        }

        return new JsonResponse([
            'clientId' => $consentRequest->getClientId(),
            'clientName' => $consentRequest->getClientName(),
            'redirectUri' => $consentRequest->getRedirectUri(),
            'scopes' => \array_map($this->scopePayload(...), $consentRequest->getScopes()),
            'state' => $consentRequest->getState(),
        ]);
    }

    #[Route(
        '/mcp/consent/{requestId}',
        name: 'sulu_mcp_oauth_consent_decision',
        options: ['expose' => true],
        methods: ['POST'],
    )]
    public function decision(Request $request, string $requestId): JsonResponse
    {
        $payload = \json_decode($request->getContent(), true);
        $approved = \is_array($payload) ? ($payload['approved'] ?? null) : null;
        if (!\is_bool($approved)) {
            return new JsonResponse(['error' => 'invalid_request'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $consentRequest = $this->consentStore->decide($request, $requestId, $approved);
        if (!$consentRequest instanceof OAuthConsentRequest) {
            return $this->notFoundResponse();
        }

        return new JsonResponse([
            'redirectUrl' => $consentRequest->getContinuationUrl(),
        ]);
    }

    /**
     * @return array{id: string, label: string}
     */
    private function scopePayload(string $scope): array
    {
        return [
            'id' => $scope,
            'label' => $this->translator->trans($scope, [], 'sulu_mcp', $this->userLocale()),
        ];
    }

    private function userLocale(): ?string
    {
        $user = $this->security->getUser();

        return $user instanceof UserInterface ? $user->getLocale() : null;
    }

    private function notFoundResponse(): JsonResponse
    {
        return new JsonResponse(['error' => 'not_found'], JsonResponse::HTTP_NOT_FOUND);
    }
}
