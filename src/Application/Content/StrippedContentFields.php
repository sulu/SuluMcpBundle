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

namespace Sulu\Mcp\Application\Content;

/**
 * Fields that are never useful for AI content operations.
 *
 * They are stripped from read responses ({@see ContentNormalizerTrait}) and left
 * out of the seo / excerpt field lists, so `sulu_get_context` never offers a
 * field that `sulu_page_get` does not return.
 *
 * @internal
 */
final class StrippedContentFields
{
    public const NAMES = [
        'id',                           // duplicate of uuid
        'customizeWebspaceSettings',
        'additionalWebspaces',
        'excerptAudienceTargetGroups',
        'excerptSegment',
        'lastModified',
        'lastModifiedEnabled',
        'version',
        'stage',                        // always 'draft' in our context
    ];

    private function __construct()
    {
    }
}
