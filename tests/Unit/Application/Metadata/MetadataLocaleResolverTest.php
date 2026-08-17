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

namespace Sulu\Mcp\Tests\Unit\Application\Metadata;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Mcp\Application\Metadata\MetadataLocaleResolver;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface as SymfonyUserInterface;

#[CoversClass(MetadataLocaleResolver::class)]
final class MetadataLocaleResolverTest extends TestCase
{
    public function testResolveReturnsLocaleOfAuthenticatedSuluUser(): void
    {
        $user = $this->createMock(UserInterface::class);
        $user->method('getLocale')->willReturn('de');

        $resolver = new MetadataLocaleResolver($this->tokenStorageWithUser($user), 'en');

        $this->assertSame('de', $resolver->resolve());
    }

    public function testResolveReturnsFallbackLocaleWhenNoTokenIsPresent(): void
    {
        $resolver = new MetadataLocaleResolver(new TokenStorage(), 'de');

        $this->assertSame('de', $resolver->resolve());
    }

    public function testResolveReturnsFallbackLocaleWhenUserIsNotASuluUser(): void
    {
        $resolver = new MetadataLocaleResolver(
            $this->tokenStorageWithUser($this->createMock(SymfonyUserInterface::class)),
            'de',
        );

        $this->assertSame('de', $resolver->resolve());
    }

    private function tokenStorageWithUser(SymfonyUserInterface $user): TokenStorageInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        return $tokenStorage;
    }
}
