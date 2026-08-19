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

namespace Sulu\Mcp\Tests\Unit\Infrastructure\Symfony\HttpKernel\EventListener;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Mcp\Infrastructure\Symfony\HttpKernel\EventListener\McpRequestFormatListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(McpRequestFormatListener::class)]
final class McpRequestFormatListenerTest extends TestCase
{
    use ProphecyTrait;

    private McpRequestFormatListener $listener;

    protected function setUp(): void
    {
        $this->listener = new McpRequestFormatListener('/admin/mcp');
    }

    private function createRequestEvent(string $pathInfo, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        $kernel = $this->prophesize(HttpKernelInterface::class);

        return new RequestEvent($kernel->reveal(), Request::create($pathInfo), $type);
    }

    public function testSetsJsonFormatOnMcpPath(): void
    {
        $event = $this->createRequestEvent('/admin/mcp');

        $this->listener->onKernelRequest($event);

        $this->assertSame('json', $event->getRequest()->getRequestFormat());
    }

    public function testSetsJsonFormatOnPercentEncodedMcpPath(): void
    {
        $event = $this->createRequestEvent('/admin/mc%70');

        $this->listener->onKernelRequest($event);

        $this->assertSame('json', $event->getRequest()->getRequestFormat());
    }

    public function testLeavesNonMcpPathUntouched(): void
    {
        $event = $this->createRequestEvent('/admin');

        $this->listener->onKernelRequest($event);

        // Default format is "html" when nothing overrides it.
        $this->assertSame('html', $event->getRequest()->getRequestFormat());
    }

    public function testLeavesAdjacentPathSharingPrefixUntouched(): void
    {
        $event = $this->createRequestEvent('/admin/mcpfoo');

        $this->listener->onKernelRequest($event);

        $this->assertSame('html', $event->getRequest()->getRequestFormat());
    }

    public function testLeavesSubPathOfMcpPathUntouched(): void
    {
        // The mcp-bundle route loader registers exactly one route at the
        // configured path -- no sub-paths are served.
        $event = $this->createRequestEvent('/admin/mcp/nested');

        $this->listener->onKernelRequest($event);

        $this->assertSame('html', $event->getRequest()->getRequestFormat());
    }

    public function testIgnoresSubRequests(): void
    {
        $event = $this->createRequestEvent('/admin/mcp', HttpKernelInterface::SUB_REQUEST);

        $this->listener->onKernelRequest($event);

        $this->assertSame('html', $event->getRequest()->getRequestFormat());
    }
}
