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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Bundle\MediaBundle\Media\FormatManager\FormatManagerInterface;
use Sulu\Mcp\UserInterface\Mcp\Tool\Media\MediaFormatListTool;

#[CoversClass(MediaFormatListTool::class)]
final class MediaFormatListToolTest extends TestCase
{
    use ProphecyTrait;

    public function testListsTheFormatsWithoutTheInternalOnes(): void
    {
        $formatManager = $this->prophesize(FormatManagerInterface::class);
        $formatManager->getFormatDefinitions('en')->willReturn([
            'sulu-400x400' => ['internal' => false, 'key' => 'sulu-400x400', 'title' => '400x400', 'scale' => ['x' => 400, 'y' => 400, 'mode' => 'outbound']],
            'sulu-admin' => ['internal' => true, 'key' => 'sulu-admin', 'title' => 'Admin', 'scale' => ['x' => 50, 'y' => 50, 'mode' => 'inset']],
        ]);

        $result = (new MediaFormatListTool($formatManager->reveal()))->listFormats('en');

        $this->assertSame(1, $result['total']);
        $this->assertSame(
            [['key' => 'sulu-400x400', 'title' => '400x400', 'scale' => ['x' => 400, 'y' => 400, 'mode' => 'outbound']]],
            $result['formats'],
        );
    }
}
