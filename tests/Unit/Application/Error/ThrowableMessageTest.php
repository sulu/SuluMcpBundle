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

namespace Sulu\Mcp\Tests\Unit\Application\Error;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\DriverException as DbalDriverException;
use Doctrine\ORM\Exception\ORMException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sulu\Mcp\Application\Error\ThrowableMessage;

#[CoversClass(ThrowableMessage::class)]
final class ThrowableMessageTest extends TestCase
{
    public function testAnOrdinaryExceptionKeepsItsText(): void
    {
        self::assertSame('Page not found', ThrowableMessage::of(new \RuntimeException('Page not found')));
    }

    public function testATruncationErrorSaysWhatToChange(): void
    {
        $message = ThrowableMessage::of($this->databaseError("SQLSTATE[22001]: String data, right truncation: 1406 Data too long for column 'name' at row 1"));

        self::assertStringContainsString('longer than the field allows', $message);
        self::assertStringNotContainsString('SQLSTATE', $message);
        self::assertStringNotContainsString("'name'", $message);
    }

    public function testAnyOtherDatabaseErrorHidesSqlAndColumns(): void
    {
        $message = ThrowableMessage::of($this->databaseError("SQLSTATE[42000]: Syntax error near '-1' at line 1"));

        self::assertStringNotContainsString('SQLSTATE', $message);
        self::assertStringNotContainsString('-1', $message);
        self::assertStringContainsString('database rejected', $message);
    }

    public function testAnOrmErrorHidesItsText(): void
    {
        $text = 'A new entity was found through the relationship Sulu\Page\Domain\Model\Page#x';
        // ORMException is a class in the lowest supported doctrine/orm and an interface in newer ones.
        $orm = \interface_exists(ORMException::class)
            ? new class($text) extends \Exception implements ORMException {
            }
        : new class($text) extends ORMException {
        };

        self::assertStringNotContainsString('Sulu\Page', ThrowableMessage::of($orm));
    }

    public function testAPhpErrorHidesClassNamesAndPaths(): void
    {
        $error = new \TypeError('Doctrine\ORM\QueryBuilder::setFirstResult(): Argument #1 must be of type ?int, called in /srv/app/vendor/x.php on line 322');

        $message = ThrowableMessage::of($error);

        self::assertStringNotContainsString('/srv/app', $message);
        self::assertStringNotContainsString('QueryBuilder', $message);
        self::assertStringContainsString('tool schema', $message);
    }

    public function testADatabaseErrorBehindAWrapperIsStillHidden(): void
    {
        $wrapped = new \RuntimeException('Could not save', 0, $this->databaseError("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'x' for key 'UNIQ_1'"));

        $message = ThrowableMessage::of($wrapped);

        self::assertStringNotContainsString('Duplicate entry', $message);
        self::assertStringNotContainsString('UNIQ_1', $message);
    }

    private function databaseError(string $text): DbalDriverException
    {
        $driver = new class($text) extends \Exception implements DriverException {
            public function getSQLState(): ?string
            {
                return null;
            }
        };

        return new DbalDriverException($driver, null);
    }
}
