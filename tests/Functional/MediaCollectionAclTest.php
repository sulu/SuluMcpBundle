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

namespace Sulu\Mcp\Tests\Functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use Sulu\Bundle\MediaBundle\Entity\Collection;
use Sulu\Bundle\SecurityBundle\System\SystemStoreInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;

/**
 * An AccessControl deny row on a specific Collection::class+id
 * overrides a role-level EDIT grant on sulu.media.collections -- same
 * mechanism PermissionAclSmokeTest proves for Page::class, generalized here
 * (MediaGetTool/MediaListTool/MediaUpdateTool all check this context+object).
 */
#[CoversNothing]
final class MediaCollectionAclTest extends FunctionalTestCase
{
    private const DENIED_COLLECTION_ID = 42;

    public function testCollectionAclDenyOverridesRoleGrant(): void
    {
        $container = self::getContainer();
        $builder = new PermissionFixtureBuilder(
            $this->entityManager,
            $container->get('sulu_security.mask_converter'),
            $container->get('security.token_storage'),
            $container->get(SystemStoreInterface::class),
        );

        $role = $builder->role('MediaEditor', [
            'sulu.media.collections' => [
                PermissionTypes::VIEW => true, PermissionTypes::ADD => true, PermissionTypes::EDIT => true,
                PermissionTypes::DELETE => true, PermissionTypes::ARCHIVE => true, PermissionTypes::LIVE => true,
                PermissionTypes::SECURITY => true,
            ],
        ]);
        $builder->objectAcl(Collection::class, self::DENIED_COLLECTION_ID, $role, [
            PermissionTypes::VIEW => true, PermissionTypes::ADD => false, PermissionTypes::EDIT => false,
            PermissionTypes::DELETE => false, PermissionTypes::ARCHIVE => false, PermissionTypes::LIVE => false,
            PermissionTypes::SECURITY => false,
        ]);
        $user = $builder->user('media-editor', $role);
        $builder->authenticate($user);

        /** @var ToolPermissionCheckerInterface $checker */
        $checker = $container->get(ToolPermissionCheckerInterface::class);

        self::assertFalse(
            $checker->has('sulu.media.collections', PermissionTypes::EDIT, 'en', Collection::class, self::DENIED_COLLECTION_ID),
            'Per-collection ACL deny must override the role-level EDIT grant on sulu.media.collections.',
        );

        self::assertTrue(
            $checker->has('sulu.media.collections', PermissionTypes::EDIT, 'en', Collection::class, 43),
            'Role-level EDIT grant on sulu.media.collections must apply when no per-collection ACL denies it.',
        );
    }
}
