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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * A tool that puts the text of a caught exception into its result can hand SQL, class names and
 * absolute paths to the model. The text has to go through ThrowableMessage.
 */
#[CoversNothing]
final class ToolErrorResultsTest extends TestCase
{
    /**
     * Messages of exceptions the bundle throws itself and words for the caller.
     */
    private const OWN_EXCEPTION_RESULTS = [
        'Media/MediaUploadTool.php' => ["refused as unsafe: %s'", "'error' => \$e->getMessage(),"],
    ];

    public function testNoToolResultCarriesTheRawTextOfACaughtException(): void
    {
        $root = \dirname(__DIR__, 5) . '/src';
        $files = [];
        foreach (['/UserInterface/Mcp/Tool', '/Application/Search'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . $directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && 'php' === $file->getExtension()) {
                    $files[] = (string) $file->getPathname();
                }
            }
        }

        $raw = [];
        foreach ($files as $path) {
            foreach (\explode("\n", (string) \file_get_contents($path)) as $number => $line) {
                if (!\str_contains($line, '$e->getMessage()') || \str_contains($line, 'throw new ToolCallException')) {
                    continue;
                }
                if (!\str_contains($line, "'error'") && !\str_contains($line, "'warning'")) {
                    continue;
                }
                foreach (self::OWN_EXCEPTION_RESULTS as $suffix => $needles) {
                    if (\str_ends_with($path, $suffix) && [] !== \array_filter($needles, static fn (string $needle): bool => \str_contains($line, $needle))) {
                        continue 2;
                    }
                }
                $raw[] = \substr($path, \strlen($root) + 1) . ':' . ($number + 1);
            }
        }

        self::assertGreaterThan(30, \count($files));
        self::assertSame([], $raw, 'Use ThrowableMessage::of($e) instead of $e->getMessage() in a tool result.');
    }
}
