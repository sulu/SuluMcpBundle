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

namespace Sulu\Mcp\Tests\Unit\Application\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceCollection;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Component\Webspace\Webspace;
use Sulu\Mcp\Application\Security\ToolPermissionChecker;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Tests\Unit\Fixture\TestUser;

#[CoversClass(WebspacePermissionResolver::class)]
final class WebspacePermissionResolverTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<WebspaceManagerInterface> */
    private ObjectProphecy $webspaceManager;

    /** @var ObjectProphecy<SecurityCheckerInterface> */
    private ObjectProphecy $securityChecker;

    protected function setUp(): void
    {
        $this->webspaceManager = $this->prophesize(WebspaceManagerInterface::class);
        $this->securityChecker = $this->prophesize(SecurityCheckerInterface::class);
    }

    public function testReturnsOnlyPermittedWebspaceKeys(): void
    {
        $this->webspaceManager->getWebspaceCollection()->willReturn(
            new WebspaceCollection([
                'example' => $this->webspace('example'),
                'blog' => $this->webspace('blog'),
            ])
        );

        $this->securityChecker->hasPermission(Argument::cetera())->will(
            fn (array $args): bool => 'sulu.webspaces.example' === $args[0]->getSecurityContext(),
        );

        $tokenStorage = (new TestUser())->inTokenStorage();

        $checker = new ToolPermissionChecker($this->securityChecker->reveal(), $tokenStorage);
        $resolver = new WebspacePermissionResolver($this->webspaceManager->reveal(), $checker);

        self::assertSame(['example'], $resolver->permittedWebspaceKeys(PermissionTypes::EDIT));
    }

    private function webspace(string $key): Webspace
    {
        $webspace = new Webspace();
        $webspace->setKey($key);

        return $webspace;
    }
}
