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

namespace Sulu\Mcp\Infrastructure\Symfony\Security\EventListener;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Answers the registration endpoint with 404 while dynamic client registration is switched off.
 *
 * @internal
 */
final readonly class DynamicClientRegistrationListener
{
    public function __construct(
        private bool $enabled,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if ($this->enabled || !$event->isMainRequest()) {
            return;
        }

        if ('sulu_mcp_client_registration' !== $event->getRequest()->attributes->get('_route')) {
            return;
        }

        $event->setResponse(new JsonResponse(
            ['error' => 'not_found', 'error_description' => 'Dynamic client registration is disabled.'],
            JsonResponse::HTTP_NOT_FOUND,
        ));
    }
}
