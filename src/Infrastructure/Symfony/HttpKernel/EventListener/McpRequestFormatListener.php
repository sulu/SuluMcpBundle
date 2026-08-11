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

namespace Sulu\Mcp\Infrastructure\Symfony\HttpKernel\EventListener;

use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Forces the request format to "json" for MCP requests.
 *
 * The MCP route has no `_format` and defaults to "html", so Sulu's MarkupBundle
 * runs HtmlMarkupParser on the response. `<sulu:...>` snippets in JSON-RPC output
 * (e.g. sulu_get_context) make the parser recurse until the stack exhausts (500).
 * "json" has no markup parser, so the response is left untouched.
 *
 * @internal
 */
class McpRequestFormatListener
{
    public function __construct(
        private readonly string $mcpPath,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if ($request->getPathInfo() !== $this->mcpPath) {
            return;
        }

        $request->setRequestFormat('json');
    }
}
