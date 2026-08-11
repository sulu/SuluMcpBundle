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

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * @internal
 */
#[AsEventListener(event: LoginSuccessEvent::class)]
final readonly class McpLoginSuccessListener
{
    private const FIREWALL_NAME = 'admin';
    private const TARGET_PATH_KEY = '_security.admin.target_path';

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        if (self::FIREWALL_NAME !== $event->getFirewallName()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        $targetPath = $session->get(self::TARGET_PATH_KEY);
        $relativeTarget = \is_string($targetPath) ? $this->relativeMcpAuthorizeTarget($targetPath) : null;
        if (null === $relativeTarget) {
            return;
        }

        $response = $event->getResponse();

        if ($response instanceof JsonResponse) {
            $data = json_decode((string) $response->getContent(), true);
            // Login not finished yet (e.g. 2FA pending): keep the target for the next step.
            if (!\is_array($data) || false === ($data['completed'] ?? true)) {
                return;
            }

            $session->remove(self::TARGET_PATH_KEY);
            // The admin SPA only navigates when the response says method 'redirect'.
            $data['method'] = 'redirect';
            $data['url'] = $relativeTarget;
            $response->setData($data);

            return;
        }

        if ($response instanceof RedirectResponse) {
            $session->remove(self::TARGET_PATH_KEY);
            $response->setTargetUrl($relativeTarget);
        }
    }

    /**
     * Root-relative so the current request's scheme and host apply, which is
     * what a proxy or TLS tunnel needs.
     */
    private function relativeMcpAuthorizeTarget(string $targetPath): ?string
    {
        $parts = \parse_url($targetPath);
        if (false === $parts || !isset($parts['path'])
            || $this->urlGenerator->generate('sulu_mcp_oauth_authorize') !== $parts['path']
        ) {
            return null;
        }

        $relative = $parts['path'];
        if (isset($parts['query'])) {
            $relative .= '?'.$parts['query'];
        }
        if (isset($parts['fragment'])) {
            $relative .= '#'.$parts['fragment'];
        }

        return $relative;
    }
}
