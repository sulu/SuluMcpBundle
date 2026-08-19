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

namespace Sulu\Mcp\Tests\Functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Sulu\Component\HttpKernel\SuluKernel;
use Sulu\Mcp\Tests\Application\Kernel;
use Sulu\Mcp\UserInterface\Controller\Website\WellKnownController;

/**
 * The discovery documents run in the website kernel but advertise endpoints
 * defined in routing_admin.yaml, so that file has to be imported there too.
 */
#[CoversNothing]
final class WellKnownWebsiteContextTest extends TestCase
{
    public function testAuthorizationServerMetadataRendersInWebsiteContext(): void
    {
        $kernel = new Kernel('test', true, SuluKernel::CONTEXT_WEBSITE);
        $kernel->boot();

        try {
            /** @var WellKnownController $controller */
            $controller = $kernel->getContainer()->get(WellKnownController::class);

            $body = \json_decode((string) $controller->authorizationServerMetadata()->getContent(), true);

            self::assertIsArray($body);
            self::assertSame('https://sulu-mcp-server.test/admin/mcp', $body['issuer']);
            self::assertSame('https://sulu-mcp-server.test/admin/mcp/authorize', $body['authorization_endpoint']);
            self::assertSame('https://sulu-mcp-server.test/admin/mcp/token', $body['token_endpoint']);
            self::assertSame('https://sulu-mcp-server.test/admin/mcp/register', $body['registration_endpoint']);
        } finally {
            $kernel->shutdown();
        }
    }
}
