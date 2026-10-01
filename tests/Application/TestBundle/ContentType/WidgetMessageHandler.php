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

namespace Sulu\Mcp\Tests\Application\TestBundle\ContentType;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Records what the generic tools send, so a test can assert on the message a call produced.
 */
#[AsMessageHandler]
final class WidgetMessageHandler
{
    /**
     * @var list<WidgetMessage>
     */
    public array $handled = [];

    public function __invoke(WidgetMessage $message): void
    {
        $this->handled[] = $message;
    }
}
