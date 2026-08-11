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
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * @internal
 */
#[AsEventListener(event: LoginSuccessEvent::class)]
final class McpLoginSuccessListener
{
    private const FIREWALL_NAME = 'admin';
    private const TARGET_PATH_KEY = '_security.admin.target_path';
    private const MCP_AUTHORIZE_PATH = '/admin/mcp/authorize';

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
            // The Sulu admin SPA only navigates (window.location.href) when the response says method 'redirect'.
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
     * The stored target is absolute; the root-relative form reuses the current
     * request's scheme/host, which is correct behind a proxy or TLS tunnel.
     */
    private function relativeMcpAuthorizeTarget(string $targetPath): ?string
    {
        $parts = \parse_url($targetPath);
        if (false === $parts || !isset($parts['path']) || self::MCP_AUTHORIZE_PATH !== $parts['path']) {
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
