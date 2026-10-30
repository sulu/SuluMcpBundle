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

namespace Sulu\Mcp\Application\Error;

use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\Exception\ORMException;

/**
 * The text a tool may show a model for an exception it caught.
 *
 * A database or a PHP error carries SQL, column names, class names and absolute paths. A
 * model can do nothing with them and the response may be stored or shown to a user, so
 * they are replaced by a sentence that says what to change. The original exception is not
 * logged here: the tools have no logger, and callers that need it rethrow instead.
 *
 * @internal
 */
final class ThrowableMessage
{
    private const TOO_LONG = 'A value is longer than the field allows. Shorten the text and try again.';
    private const DATABASE = 'The database rejected the values. Check lengths, types and ids and try again.';
    private const INPUT = 'The input has a type or value the tool cannot handle. Check the parameters against the tool schema.';

    public static function of(\Throwable $throwable): string
    {
        for ($current = $throwable; null !== $current; $current = $current->getPrevious()) {
            if ($current instanceof DbalException || $current instanceof ORMException) {
                return self::isTooLong($current) ? self::TOO_LONG : self::DATABASE;
            }

            if ($current instanceof \Error) {
                return self::INPUT;
            }
        }

        return $throwable->getMessage();
    }

    private static function isTooLong(\Throwable $throwable): bool
    {
        // SQLSTATE 22001 is "string data, right truncation".
        return \str_contains($throwable->getMessage(), 'SQLSTATE[22001]');
    }

    private function __construct()
    {
    }
}
