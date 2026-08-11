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

namespace Sulu\Mcp\Tests\Unit\Infrastructure\Sulu\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sulu\Mcp\Infrastructure\Sulu\Security\ContactSecurityContextResolver;

#[CoversClass(ContactSecurityContextResolver::class)]
final class ContactSecurityContextResolverTest extends TestCase
{
    private ContactSecurityContextResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ContactSecurityContextResolver();
    }

    public function testResolvesAccountToOrganizations(): void
    {
        self::assertSame('sulu.contact.organizations', $this->resolver->resolve(['type' => 'account']));
    }

    public function testResolvesContactToPeople(): void
    {
        self::assertSame('sulu.contact.people', $this->resolver->resolve(['type' => 'contact']));
    }

    public function testDefaultsToPeopleWhenTypeMissing(): void
    {
        self::assertSame('sulu.contact.people', $this->resolver->resolve([]));
    }
}
