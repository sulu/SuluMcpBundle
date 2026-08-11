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

namespace Sulu\Mcp\Infrastructure\Sulu\Security\EntryPoint;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Redirects unauthenticated OAuth-authorize hits to the Sulu admin login
 * (McpLoginSuccessListener resumes the flow afterwards). All other admin
 * routes delegate to Sulu's original entry point.
 *
 * @internal
 */
class OAuthAuthorizeEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly AuthenticationEntryPointInterface $inner,
    ) {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        if ('/admin/mcp/authorize' !== $request->getPathInfo()) {
            return $this->inner->start($request, $authException);
        }

        return new RedirectResponse('/admin/');
    }
}
