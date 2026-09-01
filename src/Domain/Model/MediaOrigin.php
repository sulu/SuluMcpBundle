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

namespace Sulu\Mcp\Domain\Model;

/**
 * The values sulu_media's `origin` single_select offers, mirrored from
 * MediaBundle/Resources/config/forms/media_details.xml.
 *
 * Shared by every tool that writes the field. The #[Schema] attributes still spell the values
 * out, because an attribute argument cannot reference a constant.
 *
 * @internal
 */
final class MediaOrigin
{
    public const HUMAN_CREATED = 'human_created';
    public const AI_GENERATED = 'ai_generated';
    public const AI_MODIFIED = 'ai_modified';
    public const UNKNOWN = 'unknown';

    /**
     * @var list<string>
     */
    public const VALUES = [self::HUMAN_CREATED, self::AI_GENERATED, self::AI_MODIFIED, self::UNKNOWN];

    public static function isValid(string $origin): bool
    {
        return \in_array($origin, self::VALUES, true);
    }
}
