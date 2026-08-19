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

namespace Sulu\Mcp\Tests\Unit\Infrastructure\Sulu\Security\EventListener;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Bundle\SecurityBundle\System\SystemStoreInterface;
use Sulu\Mcp\Infrastructure\Sulu\Security\EventListener\OAuthSystemStoreListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(OAuthSystemStoreListener::class)]
final class OAuthSystemStoreListenerTest extends TestCase
{
    use ProphecyTrait;

    private function createRequestEvent(string $pathInfo, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        $kernel = $this->prophesize(HttpKernelInterface::class);

        return new RequestEvent($kernel->reveal(), Request::create($pathInfo), $type);
    }

    public function testSetsSystemOnMcpPath(): void
    {
        $systemStore = $this->prophesize(SystemStoreInterface::class);
        $systemStore->setSystem('Sulu')->shouldBeCalledOnce();

        $listener = new OAuthSystemStoreListener($systemStore->reveal(), '/admin/mcp');
        $listener->onKernelRequest($this->createRequestEvent('/admin/mcp'));
    }

    public function testSetsSystemOnPercentEncodedMcpPath(): void
    {
        $systemStore = $this->prophesize(SystemStoreInterface::class);
        $systemStore->setSystem('Sulu')->shouldBeCalledOnce();

        $listener = new OAuthSystemStoreListener($systemStore->reveal(), '/admin/mcp');
        $listener->onKernelRequest($this->createRequestEvent('/admin/mc%70'));
    }

    public function testSetsConfiguredSystemOnMcpPath(): void
    {
        $systemStore = $this->prophesize(SystemStoreInterface::class);
        $systemStore->setSystem('Website')->shouldBeCalledOnce();

        $listener = new OAuthSystemStoreListener($systemStore->reveal(), '/admin/mcp', 'Website');
        $listener->onKernelRequest($this->createRequestEvent('/admin/mcp'));
    }

    public function testLeavesNonMcpPathUntouched(): void
    {
        $systemStore = $this->prophesize(SystemStoreInterface::class);
        $systemStore->setSystem(Argument::cetera())->shouldNotBeCalled();

        $listener = new OAuthSystemStoreListener($systemStore->reveal(), '/admin/mcp');
        $listener->onKernelRequest($this->createRequestEvent('/admin'));
    }

    public function testLeavesAdjacentPathSharingPrefixUntouched(): void
    {
        $systemStore = $this->prophesize(SystemStoreInterface::class);
        $systemStore->setSystem(Argument::cetera())->shouldNotBeCalled();

        $listener = new OAuthSystemStoreListener($systemStore->reveal(), '/admin/mcp');
        $listener->onKernelRequest($this->createRequestEvent('/admin/mcpfoo'));
    }

    public function testLeavesSubPathOfMcpPathUntouched(): void
    {
        // The mcp-bundle route loader registers exactly one route at the
        // configured path -- no sub-paths are served.
        $systemStore = $this->prophesize(SystemStoreInterface::class);
        $systemStore->setSystem(Argument::cetera())->shouldNotBeCalled();

        $listener = new OAuthSystemStoreListener($systemStore->reveal(), '/admin/mcp');
        $listener->onKernelRequest($this->createRequestEvent('/admin/mcp/nested'));
    }

    public function testIgnoresSubRequests(): void
    {
        $systemStore = $this->prophesize(SystemStoreInterface::class);
        $systemStore->setSystem(Argument::cetera())->shouldNotBeCalled();

        $listener = new OAuthSystemStoreListener($systemStore->reveal(), '/admin/mcp');
        $listener->onKernelRequest($this->createRequestEvent('/admin/mcp', HttpKernelInterface::SUB_REQUEST));
    }
}
