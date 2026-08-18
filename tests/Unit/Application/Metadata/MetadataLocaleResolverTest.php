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
use Sulu\Mcp\Application\Metadata\MetadataLocaleResolver;
use Sulu\Mcp\Tests\Unit\Fixture\TestUser;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface as SymfonyUserInterface;

#[CoversClass(MetadataLocaleResolver::class)]
final class MetadataLocaleResolverTest extends TestCase
{
    public function testResolveReturnsLocaleOfAuthenticatedSuluUser(): void
    {
        $resolver = new MetadataLocaleResolver((new TestUser(1, 'de'))->inTokenStorage(), 'en');

        $this->assertSame('de', $resolver->resolve());
    }

    public function testResolveReturnsFallbackLocaleWhenNoTokenIsPresent(): void
    {
        $resolver = new MetadataLocaleResolver(new TokenStorage(), 'de');

        $this->assertSame('de', $resolver->resolve());
    }

    public function testResolveReturnsFallbackLocaleWhenUserIsNotASuluUser(): void
    {
        // A Symfony user that is not a Sulu user carries no locale to read.
        $resolver = new MetadataLocaleResolver(
            $this->tokenStorageWithUser(new InMemoryUser('someone', null)),
            'de',
        );

        $this->assertSame('de', $resolver->resolve());
    }

    private function tokenStorageWithUser(SymfonyUserInterface $user): TokenStorageInterface
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'admin'));

        return $tokenStorage;
    }
}
