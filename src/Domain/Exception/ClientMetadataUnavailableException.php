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
 * The host of a Client ID Metadata Document could not be reached or failed with a server error.
 * The document itself was not rejected.
 *
 * @internal
 */
class ClientMetadataUnavailableException extends ClientMetadataException
{
}
