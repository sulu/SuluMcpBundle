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
use Sulu\Bundle\SecurityBundle\System\SystemStoreInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Application\Security\ToolVisibilityResolver;
use Sulu\Mcp\Infrastructure\Sulu\Security\SnippetSecurityContextResolver;

/**
 * Per-group snippet permissions on a MULTI-GROUP install (`default` in the default
 * group, `promo` in the `marketing` group). Group contexts exist from Sulu 3.1 on:
 * there the `marketing` group has its own context, while on 3.0 every snippet falls
 * back to `sulu.snippet.snippets`. Each test asserts the path of the installed core,
 * so the main workflow covers the fallback and the product workflow the groups.
 */
#[CoversNothing]
final class SnippetGroupScopingTest extends FunctionalTestCase
{
    private const SNIPPET_TOOLS = ['sulu_snippet_get', 'sulu_snippet_update', 'sulu_block_list'];

    private static function coreHasGroupContexts(): bool
    {
        $flag = self::getContainer()->getParameter('sulu_mcp.core.snippet_group_contexts');
        self::assertIsBool($flag);

        return $flag;
    }

    /**
     * Guards the premise of the tests below: if the dev app ever collapses back to a
     * single snippet group, they would pass vacuously.
     */
    public function testDevAppIsAMultiGroupSnippetInstall(): void
    {
        $resolver = self::getContainer()->get(SnippetSecurityContextResolver::class);

        self::assertSame('sulu.snippet.snippets', $resolver->forTemplateKey('default'));

        if (self::coreHasGroupContexts()) {
            self::assertSame('sulu.snippet.snippets_marketing', $resolver->forTemplateKey('promo'));
            self::assertContains('sulu.snippet.snippets_marketing', $resolver->candidates());
        } else {
            self::assertSame('sulu.snippet.snippets', $resolver->forTemplateKey('promo'));
            self::assertSame(['sulu.snippet.snippets'], $resolver->candidates());
        }
    }

    public function testRoleWithOnlyTheMarketingGroupUsesSnippetToolsOnlyWhereTheGroupExists(): void
    {
        $this->authenticateWithSnippetContext('sulu.snippet.snippets_marketing', 'MarketingGroupOnly', 'marketing-group-only');

        $visibility = self::getContainer()->get(ToolVisibilityResolver::class);
        $expected = self::coreHasGroupContexts();

        foreach (self::SNIPPET_TOOLS as $tool) {
            self::assertSame(
                $expected,
                $visibility->isVisible($tool, 'en'),
                \sprintf('%s must follow the "marketing" group only when core registers it.', $tool),
            );
        }
    }

    /**
     * Positive control for the base group, so the assertion above cannot pass merely
     * because snippet tools are hidden from everyone.
     */
    public function testRoleWithTheBaseSnippetContextUsesSnippetTools(): void
    {
        $this->authenticateWithSnippetContext('sulu.snippet.snippets', 'BaseSnippetGroup', 'base-snippet-group');

        $visibility = self::getContainer()->get(ToolVisibilityResolver::class);

        foreach (self::SNIPPET_TOOLS as $tool) {
            self::assertTrue($visibility->isVisible($tool, 'en'), \sprintf('%s must be available with the base snippet context.', $tool));
        }
    }

    /**
     * Negative control: the sentinel must expand to the snippet groups only.
     */
    public function testRoleWithNoSnippetContextCannotUseSnippetTools(): void
    {
        $this->authenticateWithSnippetContext('sulu.settings.tags', 'NoSnippetGroup', 'no-snippet-group');

        $visibility = self::getContainer()->get(ToolVisibilityResolver::class);

        foreach (['sulu_snippet_get', 'sulu_snippet_update'] as $tool) {
            self::assertFalse($visibility->isVisible($tool, 'en'), \sprintf('%s must stay hidden from a role holding no snippet group.', $tool));
        }
    }

    private function authenticateWithSnippetContext(string $context, string $roleName, string $username): void
    {
        $container = self::getContainer();

        $builder = new PermissionFixtureBuilder(
            $this->entityManager,
            $container->get('sulu_security.mask_converter'),
            $container->get('security.token_storage'),
            $container->get(SystemStoreInterface::class),
        );

        $role = $builder->role($roleName, [
            $context => [PermissionTypes::VIEW => true, PermissionTypes::EDIT => true],
        ]);

        $builder->authenticate($builder->user($username, $role));
    }
}
