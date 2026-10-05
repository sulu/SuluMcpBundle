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

use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Sulu\Bundle\SecurityBundle\System\SystemStoreInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Application\Security\ToolVisibilityResolver;
use Sulu\Mcp\Infrastructure\Sulu\Security\SnippetSecurityContextResolver;
use Sulu\Mcp\UserInterface\Mcp\Tool\Content\ContentDeleteTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Content\ContentPublishTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Content\ContentUnpublishTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Snippet\SnippetGetTool;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Snippet\Application\Message\ApplyWorkflowTransitionSnippetMessage;
use Sulu\Snippet\Application\Message\CreateSnippetMessage;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Per-group snippet permissions on a MULTI-GROUP install (`default` in the default
 * group, `promo` in the `marketing` group). Group contexts exist from Sulu 3.1 on:
 * there the `marketing` group has its own context, while on 3.0 every snippet falls
 * back to `sulu.snippet.snippets`. Each test asserts the path of the installed core,
 * so a run on sulu/sulu 3.0 covers the fallback and a run on 3.1 the groups.
 */
#[CoversNothing]
final class SnippetGroupScopingTest extends FunctionalTestCase
{
    private const SNIPPET_TOOLS = ['sulu_snippet_get', 'sulu_snippet_update', 'sulu_block_list'];

    private const CONTENT_TOOL_PERMISSIONS = [PermissionTypes::VIEW, PermissionTypes::EDIT, PermissionTypes::DELETE, PermissionTypes::LIVE];

    private static function coreHasGroupContexts(): bool
    {
        $flag = self::getContainer()->getParameter('sulu_mcp.core.snippet_group_contexts');
        self::assertIsBool($flag);

        return $flag;
    }

    /**
     * The flag comes from a class probe in the bundle; core's own registered contexts say whether it is right.
     */
    public function testGroupContextFlagMatchesTheSecurityContextsCoreRegisters(): void
    {
        $registered = [];
        foreach (self::getContainer()->get('sulu_admin.admin_pool')->getSecurityContexts() as $sections) {
            foreach ($sections as $contexts) {
                \array_push($registered, ...\array_keys($contexts));
            }
        }

        self::assertContains('sulu.snippet.snippets', $registered);
        self::assertSame(
            \in_array('sulu.snippet.snippets_marketing', $registered, true),
            self::coreHasGroupContexts(),
            'sulu_mcp.core.snippet_group_contexts must equal whether core registers the "marketing" group context.',
        );
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

    /**
     * Visibility alone would stay green if the in-body check of the tool stopped denying.
     */
    public function testRoleWithOnlyTheBaseSnippetContextCannotReadAMarketingSnippetWhereTheGroupExists(): void
    {
        $this->authenticateWithSnippetContext('sulu.snippet.snippets', 'BaseSnippetOnly', 'base-snippet-only');

        $tool = self::getContainer()->get(SnippetGetTool::class);

        // positive control: the same role reads a snippet of the default group
        $defaultUuid = $this->createSnippet('default');
        self::assertSame($defaultUuid, $tool->getSnippet('en', $defaultUuid)['uuid'] ?? null);

        $promoUuid = $this->createSnippet('promo');

        if (self::coreHasGroupContexts()) {
            $this->expectException(ToolCallException::class);
        }

        self::assertSame($promoUuid, $tool->getSnippet('en', $promoUuid)['uuid'] ?? null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function contentToolActions(): iterable
    {
        yield 'delete' => ['delete'];
        yield 'publish' => ['publish'];
        yield 'unpublish' => ['unpublish'];
    }

    /**
     * The unified content tools resolve the snippet's group from its template, as the snippet tools do.
     */
    #[DataProvider('contentToolActions')]
    public function testRoleWithOnlyTheMarketingGroupChangesAMarketingSnippetOnlyWhereTheGroupExists(string $action): void
    {
        $uuid = $this->createSnippet('promo', published: 'unpublish' === $action);
        $this->authenticateWithSnippetContext('sulu.snippet.snippets_marketing', 'MarketingGroupEditor', 'marketing-group-editor', self::CONTENT_TOOL_PERMISSIONS);

        if (!self::coreHasGroupContexts()) {
            $this->expectException(ToolCallException::class);
        }

        // Reaching the next line means the MCP gate let the call through.
        $result = $this->runContentTool($action, $uuid);

        // Past that gate, sulu 3.1 guards a publish itself, on the flat context `sulu_admin.resources.snippets`
        // declares, which a role holding only a group lacks.
        if ('publish' === $action && isset($result['error'])) {
            self::assertIsString($result['error']);
            self::assertStringContainsString('Publishing "sulu.snippet.snippets" requires the "live" permission', $result['error']);

            return;
        }

        self::assertArrayNotHasKey('error', $result, (string) \json_encode($result));
    }

    #[DataProvider('contentToolActions')]
    public function testRoleWithOnlyTheBaseSnippetContextChangesAMarketingSnippetOnlyWhereTheGroupDoesNotExist(string $action): void
    {
        $uuid = $this->createSnippet('promo', published: 'unpublish' === $action);
        $this->authenticateWithSnippetContext('sulu.snippet.snippets', 'BaseSnippetEditor', 'base-snippet-editor', self::CONTENT_TOOL_PERMISSIONS);

        if (self::coreHasGroupContexts()) {
            $this->expectException(ToolCallException::class);
        }

        $result = $this->runContentTool($action, $uuid);

        self::assertArrayNotHasKey('error', $result, (string) \json_encode($result));
    }

    /**
     * @return array<string, mixed>
     */
    private function runContentTool(string $action, string $uuid): array
    {
        $container = self::getContainer();

        return match ($action) {
            'delete' => $container->get(ContentDeleteTool::class)->deleteContent('snippets', $uuid, 'en'),
            'publish' => $container->get(ContentPublishTool::class)->publishContent('snippets', $uuid, 'en'),
            'unpublish' => $container->get(ContentUnpublishTool::class)->unpublishContent('snippets', $uuid, 'en'),
        };
    }

    private function createSnippet(string $template, bool $published = false): string
    {
        $messageBus = self::getContainer()->get(MessageBusInterface::class);
        $envelope = $messageBus->dispatch(new Envelope(
            new CreateSnippetMessage(['locale' => 'en', 'template' => $template, 'title' => \ucfirst($template) . ' snippet']),
            [new EnableFlushStamp()],
        ));

        /** @var SnippetInterface $snippet */
        $snippet = $envelope->last(HandledStamp::class)?->getResult();

        if ($published) {
            $messageBus->dispatch(new Envelope(
                new ApplyWorkflowTransitionSnippetMessage(['uuid' => $snippet->getUuid()], 'en', 'publish'),
                [new EnableFlushStamp()],
            ));
            // A tool call gets a fresh entity manager; the transition loads the live rows only then.
            $this->entityManager->clear();
        }

        return $snippet->getUuid();
    }

    /**
     * @param list<string> $permissions
     */
    private function authenticateWithSnippetContext(string $context, string $roleName, string $username, array $permissions = [PermissionTypes::VIEW, PermissionTypes::EDIT]): void
    {
        $container = self::getContainer();

        $builder = new PermissionFixtureBuilder(
            $this->entityManager,
            $container->get('sulu_security.mask_converter'),
            $container->get('security.token_storage'),
            $container->get(SystemStoreInterface::class),
        );

        $role = $builder->role($roleName, [
            $context => \array_fill_keys($permissions, true),
        ]);

        $builder->authenticate($builder->user($username, $role));
    }
}
