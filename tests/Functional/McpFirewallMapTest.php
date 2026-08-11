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
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\AccessMapInterface;

/**
 * An unanchored `^/admin/mcp` would pull authorize and consent into the
 * stateless firewall, where there is no session, and the consent flow breaks.
 */
#[CoversNothing]
final class McpFirewallMapTest extends FunctionalTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function firewallProvider(): iterable
    {
        yield 'transport' => ['/admin/mcp', 'mcp'];
        yield 'transport with trailing slash' => ['/admin/mcp/', 'mcp'];

        yield 'authorize' => ['/admin/mcp/authorize', 'admin'];
        yield 'consent' => ['/admin/mcp/consent/6f1c0a', 'admin'];
        yield 'token' => ['/admin/mcp/token', 'admin'];
        yield 'registration' => ['/admin/mcp/register', 'admin'];
    }

    #[DataProvider('firewallProvider')]
    public function testPathIsHandledByExpectedFirewall(string $path, string $firewall): void
    {
        /** @var Security $security */
        $security = self::getContainer()->get('security.helper');

        $config = $security->getFirewallConfig(Request::create($path));

        self::assertNotNull($config, \sprintf('No firewall matches "%s".', $path));
        self::assertSame($firewall, $config->getName());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function accessControlProvider(): iterable
    {
        yield 'token' => ['/admin/mcp/token', 'PUBLIC_ACCESS'];
        yield 'registration' => ['/admin/mcp/register', 'PUBLIC_ACCESS'];
        yield 'transport' => ['/admin/mcp', 'IS_AUTHENTICATED_FULLY'];
        yield 'authorize' => ['/admin/mcp/authorize', 'ROLE_USER'];
        yield 'consent' => ['/admin/mcp/consent/6f1c0a', 'ROLE_USER'];
    }

    #[DataProvider('accessControlProvider')]
    public function testPathRequiresExpectedAttribute(string $path, string $attribute): void
    {
        /** @var AccessMapInterface $accessMap */
        $accessMap = self::getContainer()->get('security.access_map');

        [$attributes] = $accessMap->getPatterns(Request::create($path));

        self::assertNotNull($attributes, \sprintf('No access-control rule matches "%s".', $path));
        self::assertContains($attribute, $attributes);
    }

    public function testTransportIsStatelessAndConsentIsNot(): void
    {
        /** @var Security $security */
        $security = self::getContainer()->get('security.helper');

        $transport = $security->getFirewallConfig(Request::create('/admin/mcp'));
        $consent = $security->getFirewallConfig(Request::create('/admin/mcp/consent/6f1c0a'));

        self::assertNotNull($transport);
        self::assertNotNull($consent);
        self::assertTrue($transport->isStateless(), 'The MCP transport must not issue session cookies.');
        self::assertFalse($consent->isStateless(), 'The consent screen needs the admin session.');
    }
}
