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

namespace Sulu\Mcp\Infrastructure\League\EventListener;

use League\Bundle\OAuth2ServerBundle\Event\AuthorizationRequestResolveEvent;
use League\Bundle\OAuth2ServerBundle\OAuth2Events;
use Sulu\Mcp\Domain\Model\OAuthConsentRequest;
use Sulu\Mcp\Infrastructure\Symfony\Security\OAuthConsentStore;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @internal
 */
#[AsEventListener(event: OAuth2Events::AUTHORIZATION_REQUEST_RESOLVE)]
final readonly class OAuthAuthorizationListener
{
    public function __construct(
        private RequestStack $requestStack,
        private OAuthConsentStore $consentStore,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(AuthorizationRequestResolveEvent $event): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof Request || !$request->hasSession()) {
            return;
        }

        if ('sulu_mcp_oauth_authorize' !== $request->attributes->get('_route')) {
            return;
        }

        $requestId = $this->consentStore->getRequestId($request);
        if (null !== $requestId) {
            $decision = $this->consentStore->consumeDecision($request, $requestId);
            if (null !== $decision) {
                $event->resolveAuthorization($decision);

                return;
            }

            if ($this->consentStore->get($request, $requestId) instanceof OAuthConsentRequest) {
                $event->setResponse(new RedirectResponse($this->consentViewUrl($requestId)));

                return;
            }
        }

        $consentRequest = $this->consentStore->create($request, $event);
        $event->setResponse(new RedirectResponse($this->consentViewUrl($consentRequest->getId())));
    }

    private function consentViewUrl(string $requestId): string
    {
        return \sprintf('%s#/mcp/authorize/%s', $this->urlGenerator->generate('sulu_admin'), $requestId);
    }
}
