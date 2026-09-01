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

namespace Sulu\Mcp\Domain\Exception;

/**
 * A media source URL could not be fetched, or what came back is not something the
 * bundle is willing to hand to the MediaBundle.
 *
 * The message is written for the assistant that called the tool: it is surfaced
 * verbatim in the tool result.
 *
 * @internal
 */
class MediaDownloadException extends \RuntimeException
{
}
