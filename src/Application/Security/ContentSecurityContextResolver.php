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

namespace Sulu\Mcp\Application\Security;

use Sulu\Mcp\Application\Content\ContentTypeResolver;
use Sulu\Mcp\Domain\Content\ContentSecurity;

/**
 * @internal
 */
final readonly class ContentSecurityContextResolver
{
    public function __construct(
        private ContentTypeResolver $contentTypeResolver,
    ) {
    }

    /**
     * An unknown type gets the empty context, which no permission check grants.
     *
     * @param object $aggregate the loaded draft aggregate (Page/Article/Snippet/...)
     */
    public function forEntity(string $type, object $aggregate, string $locale): ContentSecurity
    {
        return $this->contentTypeResolver->find($type)?->getSecurity($aggregate, $locale) ?? new ContentSecurity('');
    }
}
