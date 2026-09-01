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
 * The URL did not yield a response: an error status, or a transport failure.
 *
 * Split from its parent so a caller can tell "this address does not serve the file" from
 * "the file it served is not one we accept". Only the former is worth retrying against a
 * different address; retrying after a size or content rejection would just work around the
 * limit that did the rejecting.
 *
 * @internal
 */
class MediaSourceUnreachableException extends MediaDownloadException
{
}
