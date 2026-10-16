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

namespace Sulu\Mcp\Tests\Unit\Infrastructure\Symfony\Security\EventListener;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Mcp\Infrastructure\Symfony\Security\EventListener\DynamicClientRegistrationListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(DynamicClientRegistrationListener::class)]
final class DynamicClientRegistrationListenerTest extends TestCase
{
    use ProphecyTrait;

    public function testAnswersNotFoundWhenRegistrationIsDisabled(): void
    {
        $event = $this->requestEvent('sulu_mcp_client_registration');

        (new DynamicClientRegistrationListener(false))->onKernelRequest($event);

        self::assertSame(404, $event->getResponse()?->getStatusCode());
    }

    public function testLeavesTheEndpointAloneWhenRegistrationIsEnabled(): void
    {
        $event = $this->requestEvent('sulu_mcp_client_registration');

        (new DynamicClientRegistrationListener(true))->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testLeavesOtherRoutesAlone(): void
    {
        $event = $this->requestEvent('sulu_mcp_oauth_token');

        (new DynamicClientRegistrationListener(false))->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testIgnoresSubRequests(): void
    {
        $event = $this->requestEvent('sulu_mcp_client_registration', HttpKernelInterface::SUB_REQUEST);

        (new DynamicClientRegistrationListener(false))->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    private function requestEvent(string $route, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        return new RequestEvent($this->prophesize(HttpKernelInterface::class)->reveal(), new Request(attributes: ['_route' => $route]), $type);
    }
}
