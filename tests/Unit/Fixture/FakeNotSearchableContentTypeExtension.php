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

namespace Sulu\Mcp\Tests\Unit\Fixture;

use Sulu\Mcp\Domain\Content\NotSearchableContentTypeInterface;

/**
 * @internal
 */
final class FakeNotSearchableContentTypeExtension extends FakeContentTypeExtension implements NotSearchableContentTypeInterface
{
}
