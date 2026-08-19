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
use Sulu\Mcp\Infrastructure\Sulu\Security\EventListener\OAuthSystemStoreListener;
use Sulu\Mcp\Infrastructure\Symfony\HttpKernel\EventListener\McpRequestFormatListener;
use Sulu\Mcp\Infrastructure\Symfony\Security\EventListener\McpScopeListener;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Http\Firewall;

/**
 * Pins the kernel.request listener order the path checks and the scope check rely on:
 * router before firewall (unknown paths 404 before authentication), McpScopeListener
 * after it (the token storage must be populated). Priorities are asserted relative to
 * each other, not as fixed numbers.
 */
#[CoversNothing]
final class RequestListenerOrderTest extends KernelTestCase
{
    public function testRouterRunsBeforeFirewall(): void
    {
        // Compare the worst case in each direction: the latest-running router must
        // still precede the earliest-running firewall. Several listeners can match
        // either predicate -- the firewall is registered more than once -- so taking
        // the first match would hide a straggler.
        $router = \min($this->prioritiesOf(
            static fn (object $listener): bool => \str_ends_with($listener::class, '\\RouterListener'),
            'router',
        ));
        $firewall = \max($this->prioritiesOf(
            static fn (object $listener): bool => $listener instanceof Firewall,
            'firewall',
        ));

        self::assertGreaterThan(
            $firewall,
            $router,
            'The router must run before the firewall, so a request to an unrouted path under the MCP '
            . 'prefix is rejected with a 404 before authentication is attempted.',
        );
    }

    public function testMcpRequestListenersRunBeforeFirewall(): void
    {
        $firewall = \max($this->prioritiesOf(
            static fn (object $listener): bool => $listener instanceof Firewall,
            'firewall',
        ));

        foreach ([McpRequestFormatListener::class, OAuthSystemStoreListener::class] as $class) {
            $priority = \min($this->prioritiesOf(
                static fn (object $listener): bool => $listener instanceof $class,
                $class,
            ));

            self::assertGreaterThan(
                $firewall,
                $priority,
                \sprintf('%s prepares request state the firewall consumes and must run before it.', $class),
            );
        }
    }

    public function testScopeListenerRunsAfterFirewall(): void
    {
        $firewall = \min($this->prioritiesOf(
            static fn (object $listener): bool => $listener instanceof Firewall,
            'firewall',
        ));
        $scopeListener = \max($this->prioritiesOf(
            static fn (object $listener): bool => $listener instanceof McpScopeListener,
            McpScopeListener::class,
        ));

        self::assertLessThan(
            $firewall,
            $scopeListener,
            'McpScopeListener reads the authenticated token and must run after the firewall.',
        );
    }

    /**
     * @param callable(object): bool $matches
     *
     * @return non-empty-list<int>
     */
    private function prioritiesOf(callable $matches, string $label): array
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertNotNull($dispatcher);

        $priorities = [];
        foreach ($dispatcher->getListeners(KernelEvents::REQUEST) as $listener) {
            $object = \is_array($listener) ? $listener[0] : $listener;
            if (!\is_object($object) || !$matches($object)) {
                continue;
            }

            $priority = $dispatcher->getListenerPriority(KernelEvents::REQUEST, $listener);
            self::assertNotNull($priority);
            $priorities[] = $priority;
        }

        if ([] === $priorities) {
            self::fail(\sprintf('No kernel.request listener matched "%s".', $label));
        }

        return $priorities;
    }
}
