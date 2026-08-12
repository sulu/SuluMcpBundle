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

namespace Sulu\Mcp\Tests\Unit\Infrastructure\Symfony\HttpKernel\Compiler;

use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Infrastructure\Symfony\HttpKernel\Compiler\ToolPermissionMapPass;
use Sulu\Mcp\UserInterface\Mcp\Tool\Article\ArticleCreateTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Article\ArticleGetTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Article\ArticleListTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Article\ArticleUpdateTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Block\BlockAddTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Block\BlockListTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Block\BlockRemoveTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Block\BlockReorderTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Block\BlockUpdateTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Contact\ContactListTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Content\ContentDeleteTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Content\ContentPublishTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Content\ContentUnpublishTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\ContentSearchTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\GetContextTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Media\MediaGetTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Media\MediaListTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Media\MediaUpdateTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Navigation\NavigationGetTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Page\PageCreateTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Page\PageGetTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Page\PageListTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Page\PageTreeTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Page\PageUpdateTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\PingTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Preview\PreviewLinkGenerateTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Preview\PreviewLinkRevokeTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Snippet\SnippetCreateTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Snippet\SnippetGetTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Snippet\SnippetListTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Snippet\SnippetUpdateTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Taxonomy\CategoryCreateTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Taxonomy\CategoryDeleteTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Taxonomy\CategoryListTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Taxonomy\TagCreateTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Taxonomy\TagDeleteTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Taxonomy\TagListTool;

/**
 * Golden-table pin for every tool's #[RequiresPermission] declaration, plus a
 * uniqueness check across all registered MCP tool names (duplicates are
 * silently overwritten at ToolPermissionMapPass.php:31).
 */
#[CoversClass(ToolPermissionMapPass::class)]
final class ToolPermissionGoldenTest extends TestCase
{
    /**
     * class-string => [tool name, list of [security context, permission type]].
     *
     * @var array<class-string, array{0: string, 1: list<array{0: string, 1: string}>}>
     */
    private const GOLDEN = [
        ArticleCreateTool::class => ['sulu_article_create', [['#context#', PermissionTypes::EDIT], ['#context#', PermissionTypes::ADD]]],
        ArticleGetTool::class => ['sulu_article_get', [['sulu.article.articles', PermissionTypes::VIEW]]],
        ArticleListTool::class => ['sulu_article_list', [['sulu.article.articles', PermissionTypes::VIEW]]],
        ArticleUpdateTool::class => ['sulu_article_update', [['sulu.article.articles', PermissionTypes::EDIT]]],
        ContactListTool::class => ['sulu_contact_list', [['#context#', PermissionTypes::VIEW]]],
        ContentSearchTool::class => ['sulu_content_search', [['#context#', PermissionTypes::VIEW]]],
        ContentDeleteTool::class => ['sulu_content_delete', [['#context#', PermissionTypes::EDIT], ['#context#', PermissionTypes::DELETE]]],
        ContentPublishTool::class => ['sulu_content_publish', [['#context#', PermissionTypes::EDIT], ['#context#', PermissionTypes::LIVE]]],
        ContentUnpublishTool::class => ['sulu_content_unpublish', [['#context#', PermissionTypes::EDIT], ['#context#', PermissionTypes::LIVE]]],
        MediaGetTool::class => ['sulu_media_get', [['sulu.media.collections', PermissionTypes::VIEW]]],
        MediaListTool::class => ['sulu_media_list', [['sulu.media.collections', PermissionTypes::VIEW]]],
        MediaUpdateTool::class => ['sulu_media_update', [['sulu.media.collections', PermissionTypes::EDIT]]],
        NavigationGetTool::class => ['sulu_navigation_get', [['sulu.webspaces.#context#', PermissionTypes::VIEW]]],
        BlockAddTool::class => ['sulu_block_add', [['#context#', PermissionTypes::EDIT]]],
        BlockListTool::class => ['sulu_block_list', [['#context#', PermissionTypes::VIEW]]],
        BlockRemoveTool::class => ['sulu_block_remove', [['#context#', PermissionTypes::EDIT]]],
        BlockReorderTool::class => ['sulu_block_reorder', [['#context#', PermissionTypes::EDIT]]],
        BlockUpdateTool::class => ['sulu_block_update', [['#context#', PermissionTypes::EDIT]]],
        PageCreateTool::class => ['sulu_page_create', [['sulu.webspaces.#context#', PermissionTypes::EDIT], ['sulu.webspaces.#context#', PermissionTypes::ADD]]],
        PageGetTool::class => ['sulu_page_get', [['sulu.webspaces.#context#', PermissionTypes::VIEW]]],
        PageListTool::class => ['sulu_page_list', [['sulu.webspaces.#context#', PermissionTypes::VIEW]]],
        PageTreeTool::class => ['sulu_page_tree', [['sulu.webspaces.#context#', PermissionTypes::VIEW]]],
        PageUpdateTool::class => ['sulu_page_update', [['sulu.webspaces.#context#', PermissionTypes::EDIT]]],
        PreviewLinkGenerateTool::class => ['sulu_preview_link_generate', [['#context#', PermissionTypes::EDIT]]],
        PreviewLinkRevokeTool::class => ['sulu_preview_link_revoke', [['#context#', PermissionTypes::EDIT]]],
        SnippetCreateTool::class => ['sulu_snippet_create', [['sulu.snippet.snippets', PermissionTypes::EDIT], ['sulu.snippet.snippets', PermissionTypes::ADD]]],
        SnippetGetTool::class => ['sulu_snippet_get', [['sulu.snippet.snippets', PermissionTypes::VIEW]]],
        SnippetListTool::class => ['sulu_snippet_list', [['sulu.snippet.snippets', PermissionTypes::VIEW]]],
        SnippetUpdateTool::class => ['sulu_snippet_update', [['sulu.snippet.snippets', PermissionTypes::EDIT]]],
        CategoryCreateTool::class => ['sulu_category_create', [['sulu.settings.categories', PermissionTypes::VIEW], ['sulu.settings.categories', PermissionTypes::ADD]]],
        CategoryDeleteTool::class => ['sulu_category_delete', [['sulu.settings.categories', PermissionTypes::VIEW], ['sulu.settings.categories', PermissionTypes::DELETE]]],
        CategoryListTool::class => ['sulu_category_list', [['sulu.settings.categories', PermissionTypes::VIEW]]],
        TagCreateTool::class => ['sulu_tag_create', [['sulu.settings.tags', PermissionTypes::EDIT], ['sulu.settings.tags', PermissionTypes::ADD]]],
        TagDeleteTool::class => ['sulu_tag_delete', [['sulu.settings.tags', PermissionTypes::EDIT], ['sulu.settings.tags', PermissionTypes::DELETE]]],
        TagListTool::class => ['sulu_tag_list', [['sulu.settings.tags', PermissionTypes::VIEW]]],
    ];

    /**
     * Scans src/UserInterface/Mcp/Tool/ for every class with a #[McpTool]-attributed
     * method. NavigationGetTool is excluded -- its #[McpTool] attribute lives
     * inside a docblock comment, not live PHP attribute syntax.
     *
     * @return list<class-string>
     */
    private function discoverToolClasses(): array
    {
        $srcRoot = \dirname(__DIR__, 6) . '/src';
        $root = $srcRoot . '/UserInterface/Mcp/Tool';

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        $classes = [];
        foreach ($files as $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }

            $relative = \substr((string) $file->getPathname(), \strlen($srcRoot) + 1);
            $relative = \str_replace(\DIRECTORY_SEPARATOR, '\\', $relative);
            $class = 'Sulu\\Mcp\\' . \substr($relative, 0, -4);

            if (!\class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            foreach ($reflection->getMethods() as $method) {
                if ([] !== $method->getAttributes(McpTool::class)) {
                    $classes[] = $class;

                    continue 2;
                }
            }
        }

        \sort($classes);

        return $classes;
    }

    public function testDiscoveredToolsMatchGoldenTable(): void
    {
        $expected = [...\array_keys(self::GOLDEN), PingTool::class, GetContextTool::class];
        \sort($expected);

        self::assertSame(
            $expected,
            $this->discoverToolClasses(),
            'A tool class carrying #[McpTool] was found under src/UserInterface/Mcp/Tool/ that is not in '
            . 'GOLDEN and not in the attribute-free allowlist (Ping, GetContext). Add it to GOLDEN.',
        );
    }

    public static function golden(): iterable
    {
        foreach (self::GOLDEN as $class => [$name, $requirements]) {
            yield $class => [$class, $name, $requirements];
        }
    }

    /**
     * Pins WHICH permission on WHICH security context each tool demands. The
     * discovery plumbing (contextArgument/contextResolver/etc.) is deliberately
     * NOT pinned -- it changes for legitimate reasons and pinning it produced
     * pure churn.
     *
     * @param list<array{0: string, 1: string}> $requirements
     */
    #[DataProvider('golden')]
    public function testDeclaredRequirementsMatchGoldenRow(string $class, string $name, array $requirements): void
    {
        $extracted = ToolPermissionMapPass::extract($class);

        self::assertNotNull($extracted, \sprintf('%s declares no #[RequiresPermission].', $class));
        self::assertSame($name, $extracted['name']);

        $actual = \array_map(
            static fn (array $r): array => [$r['context'], $r['permission']],
            $extracted['requirements'],
        );
        self::assertSame($requirements, $actual);
    }

    public function testAllToolNamesAreUnique(): void
    {
        $names = \array_map($this->mcpToolName(...), $this->discoverToolClasses());

        self::assertCount(
            \count($names),
            \array_unique($names),
            'Duplicate MCP tool name found -- ToolPermissionMapPass::process() silently overwrites '
            . 'earlier entries on a name collision (ToolPermissionMapPass.php:31).',
        );
    }

    private function mcpToolName(string $class): string
    {
        $reflection = new \ReflectionClass($class);
        foreach ($reflection->getMethods() as $method) {
            $attributes = $method->getAttributes(McpTool::class);
            if ([] !== $attributes) {
                return $attributes[0]->newInstance()->name;
            }
        }

        throw new \LogicException(\sprintf('%s has no #[McpTool]-attributed method.', $class));
    }
}
