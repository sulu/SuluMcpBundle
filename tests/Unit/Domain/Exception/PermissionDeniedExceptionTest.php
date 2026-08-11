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

namespace Sulu\Mcp\Tests\Unit\Domain\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;

#[CoversClass(PermissionDeniedException::class)]
final class PermissionDeniedExceptionTest extends TestCase
{
    public function testGetSecurityContextReturnsContext(): void
    {
        $exception = new PermissionDeniedException('sulu.webspaces.example', PermissionTypes::VIEW, 'en');

        $this->assertSame('sulu.webspaces.example', $exception->getSecurityContext());
    }

    public function testGetPermissionTypeReturnsType(): void
    {
        $exception = new PermissionDeniedException('sulu.webspaces.example', PermissionTypes::VIEW, 'en');

        $this->assertSame(PermissionTypes::VIEW, $exception->getPermissionType());
    }

    public function testGetLocaleReturnsLocale(): void
    {
        $exception = new PermissionDeniedException('sulu.webspaces.example', PermissionTypes::VIEW, 'en');

        $this->assertSame('en', $exception->getLocale());
    }

    public function testMessageContainsSecurityContextAndPermissionType(): void
    {
        $exception = new PermissionDeniedException('sulu.webspaces.example', PermissionTypes::VIEW, 'en');

        $this->assertStringContainsString('sulu.webspaces.example', $exception->getMessage());
        $this->assertStringContainsString((string) PermissionTypes::VIEW, $exception->getMessage());
    }

    public function testLocaleIsOptional(): void
    {
        $exception = new PermissionDeniedException('sulu.webspaces.example', PermissionTypes::VIEW);

        $this->assertNull($exception->getLocale());
        $this->assertStringContainsString('sulu.webspaces.example', $exception->getMessage());
    }
}
