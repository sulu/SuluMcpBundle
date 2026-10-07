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

namespace Sulu\Mcp\Application\AdminLink;

/**
 * Lets a bundle point sulu_admin_link_generate at the resource that owns the admin view when
 * the requested one has none of its own, e.g. a variant that is edited inside its parent.
 * Autoconfigured with the tag `sulu_mcp.admin_link_resource_resolver`.
 */
interface AdminLinkResourceResolverInterface
{
    /**
     * Null when this resolver is not responsible, so the resource is linked as given.
     *
     * Runs after the user was authorized to view the requested resource key and id. The tool
     * authorizes the target again when it differs. The target's id is returned to the caller, so
     * it must not reveal anything beyond the target itself. The requested key must be a
     * `sulu_admin.resources` key.
     */
    public function resolve(string $resourceKey, string $resourceId, string $locale): ?AdminLinkTarget;
}
