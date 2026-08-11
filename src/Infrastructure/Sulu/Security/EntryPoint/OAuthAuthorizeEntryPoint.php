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
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Redirects unauthenticated OAuth-authorize hits to the admin login;
 * McpLoginSuccessListener resumes the flow afterwards.
 *
 * @internal
 */
class OAuthAuthorizeEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly AuthenticationEntryPointInterface $inner,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        $authorizePath = $this->urlGenerator->generate('sulu_mcp_oauth_authorize');
        if ($authorizePath !== $request->getPathInfo()) {
            return $this->inner->start($request, $authException);
        }

        return new RedirectResponse($this->urlGenerator->generate('sulu_admin'));
    }
}
