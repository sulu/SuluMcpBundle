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

namespace Sulu\Mcp\Tests\Unit\Infrastructure\Sulu\OAuth;

use League\OAuth2\Server\Entities\UserEntityInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\SecurityBundle\Entity\User;
use Sulu\Mcp\Infrastructure\Sulu\OAuth\SuluOAuthUser;
use Sulu\Mcp\Infrastructure\Sulu\OAuth\SuluUserResolver;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[CoversClass(SuluUserResolver::class)]
final class SuluUserResolverTest extends TestCase
{
    private SuluUserResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new SuluUserResolver();
    }

    public function testResolveFromSecurityTokenReturnsSuluOAuthUserWithUsername(): void
    {
        $suluUser = $this->createMock(User::class);
        $suluUser->method('getUserIdentifier')->willReturn('admin');

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($suluUser);

        $oauthUser = $this->resolver->resolveFromSecurityToken($token);

        $this->assertInstanceOf(UserEntityInterface::class, $oauthUser);
        $this->assertInstanceOf(SuluOAuthUser::class, $oauthUser);
        $this->assertSame('admin', $oauthUser->getIdentifier());
    }

    public function testResolveFromSecurityTokenThrowsForNonSuluUser(): void
    {
        $genericUser = $this->createMock(UserInterface::class);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($genericUser);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Expected Sulu User entity');

        $this->resolver->resolveFromSecurityToken($token);
    }
}
