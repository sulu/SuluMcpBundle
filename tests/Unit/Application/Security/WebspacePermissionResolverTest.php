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
use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceCollection;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Component\Webspace\Webspace;
use Sulu\Mcp\Application\Security\ToolPermissionChecker;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

#[CoversClass(WebspacePermissionResolver::class)]
final class WebspacePermissionResolverTest extends TestCase
{
    public function testReturnsOnlyPermittedWebspaceKeys(): void
    {
        $webspaceManager = $this->createMock(WebspaceManagerInterface::class);
        $webspaceManager->method('getWebspaceCollection')->willReturn(
            new WebspaceCollection([
                'example' => $this->webspace('example'),
                'blog' => $this->webspace('blog'),
            ])
        );

        $securityChecker = $this->createMock(SecurityCheckerInterface::class);
        $securityChecker->method('hasPermission')->willReturnCallback(
            static fn ($condition, string $permission): bool => 'sulu.webspaces.example' === $condition->getSecurityContext(),
        );

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($this->createMock(UserInterface::class));
        $tokenStorage->method('getToken')->willReturn($token);

        $checker = new ToolPermissionChecker($securityChecker, $tokenStorage);
        $resolver = new WebspacePermissionResolver($webspaceManager, $checker);

        self::assertSame(['example'], $resolver->permittedWebspaceKeys(PermissionTypes::EDIT));
    }

    private function webspace(string $key): Webspace
    {
        $webspace = new Webspace();
        $webspace->setKey($key);

        return $webspace;
    }
}
