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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\Preview;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Article\Domain\Model\Article;
use Sulu\Article\Domain\Model\ArticleDimensionContent;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Bundle\PreviewBundle\Application\Manager\PreviewLinkManagerInterface;
use Sulu\Mcp\Application\Content\ContentTypeSchemaExpander;
use Sulu\Mcp\Tests\Application\TestBundle\Metadata\TestGroupProvider;
use Sulu\Mcp\Tests\Unit\Fixture\ContentTypes;
use Sulu\Mcp\Tests\Unit\Fixture\FakeContentTypeExtension;
use Sulu\Mcp\Tests\Unit\Fixture\FakeToolPermissionChecker;
use Sulu\Mcp\UserInterface\Mcp\Tool\Preview\PreviewLinkRevokeTool;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;

#[CoversClass(PreviewLinkRevokeTool::class)]
final class PreviewLinkRevokeToolTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<PreviewLinkManagerInterface> */
    private ObjectProphecy $previewLinkManager;

    /** @var ObjectProphecy<PageRepositoryInterface> */
    private ObjectProphecy $pageRepository;

    /** @var ObjectProphecy<ArticleRepositoryInterface> */
    private ObjectProphecy $articleRepository;

    private FakeToolPermissionChecker $permissionChecker;
    private PreviewLinkRevokeTool $tool;

    protected function setUp(): void
    {
        $this->previewLinkManager = $this->prophesize(PreviewLinkManagerInterface::class);
        $this->pageRepository = $this->prophesize(PageRepositoryInterface::class);
        $this->articleRepository = $this->prophesize(ArticleRepositoryInterface::class);
        $this->permissionChecker = FakeToolPermissionChecker::grantingAll();
        $groupProvider = new TestGroupProvider([]);

        $snippetRepository = $this->prophesize(SnippetRepositoryInterface::class);

        $this->tool = new PreviewLinkRevokeTool(
            $this->previewLinkManager->reveal(),
            ContentTypes::resolver($this->pageRepository->reveal(), $this->articleRepository->reveal(), $snippetRepository->reveal(), $groupProvider),
            $this->permissionChecker,
            ContentTypes::securityResolver($groupProvider),
        );
    }

    private function setupEntity(string $type): void
    {
        if ('pages' === $type) {
            $page = new Page('page-uuid');
            $page->setWebspaceKey('example');
            $this->pageRepository->getOneBy(Argument::cetera())->willReturn($page);
        } else {
            $article = new Article('article-uuid');
            $this->articleRepository->getOneBy(Argument::cetera())->willReturn($article);
            $dimensionContent = new ArticleDimensionContent($article);
            $dimensionContent->setTemplateKey('default');
            $article->addDimensionContent($dimensionContent);
        }
    }

    public function testRevokeRejectsATypeMarkedNotSearchable(): void
    {
        $this->previewLinkManager->revoke(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->revokePreviewLink('snippets', 'snippet-uuid', 'en');

        $this->assertStringContainsString('cannot be previewed', $result['error']);
    }

    public function testRevokeRejectsAnUnknownType(): void
    {
        $this->previewLinkManager->revoke(Argument::cetera())->shouldNotBeCalled();

        $this->assertStringContainsString('cannot be previewed', $this->tool->revokePreviewLink('nope', 'snippet-uuid', 'en')['error']);
    }

    public function testRevokeResolvesTheResourceKeyFromAnExtension(): void
    {
        $this->previewLinkManager->revoke('widgets', 'w-1', 'en')->shouldBeCalledOnce();

        $tool = new PreviewLinkRevokeTool(
            $this->previewLinkManager->reveal(),
            ContentTypes::inertResolver([new FakeContentTypeExtension(draft: new \stdClass())]),
            $this->permissionChecker,
            ContentTypes::securityResolver(null, [new FakeContentTypeExtension(draft: new \stdClass())]),
        );

        $this->assertSame('widgets', $tool->revokePreviewLink('widgets', 'w-1', 'en')['resourceKey']);
    }

    public function testRevokePreviewLinkSuccess(): void
    {
        $this->setupEntity('pages');

        $this->previewLinkManager
            ->revoke('pages', 'page-uuid-1', 'en')
            ->shouldBeCalledOnce();

        $result = $this->tool->revokePreviewLink('pages', 'page-uuid-1', 'en');

        $this->assertTrue($result['success']);
        $this->assertSame('revoked', $result['action']);
        $this->assertSame('pages', $result['resourceKey']);
        $this->assertSame('page-uuid-1', $result['resourceId']);
        $this->assertSame('en', $result['locale']);
    }

    public function testTypeIsMappedToResourceKeyForRevoke(): void
    {
        $this->setupEntity('articles');

        $capturedResourceKey = null;
        $this->previewLinkManager
            ->revoke(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use (&$capturedResourceKey): void {
                $capturedResourceKey = $args[0];
            });

        $this->tool->revokePreviewLink('articles', 'article-uuid-1', 'en');

        $this->assertSame('articles', $capturedResourceKey, 'Singular "article" must be mapped to plural "articles" before calling the manager.');
    }

    public function testRevokePreviewLinkReturnsErrorOnException(): void
    {
        $this->setupEntity('pages');

        $this->previewLinkManager
            ->revoke(Argument::cetera())
            ->willThrow(new \RuntimeException('No preview link found'));

        $result = $this->tool->revokePreviewLink('pages', 'bad-uuid', 'en');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('No preview link found', $result['error']);
        $this->assertArrayHasKey('hint', $result);
    }

    public function testEntityNotFoundReturnsErrorWithoutRevoke(): void
    {
        $this->pageRepository->getOneBy(Argument::cetera())->willThrow(new \RuntimeException('not found'));
        $this->previewLinkManager->revoke(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->revokePreviewLink('pages', 'missing-uuid', 'en');

        $this->assertArrayHasKey('error', $result);
    }

    public function testRevokePreviewLinkThrowsToolCallExceptionWhenPermissionDenied(): void
    {
        $this->setupEntity('pages');

        $this->permissionChecker->denyAll();

        $this->previewLinkManager->revoke(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(ToolCallException::class);

        $this->tool->revokePreviewLink('pages', 'page-uuid-1', 'en');
    }

    public function testMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(PreviewLinkRevokeTool::class, 'revokePreviewLink');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes, 'revokePreviewLink() method must have exactly one #[McpTool] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_preview_link_revoke', $instance->name);
    }

    public function testResourceKeyParameterHasSchemaAttributeWithResourceKeyPlaceholder(): void
    {
        $reflection = new \ReflectionMethod(PreviewLinkRevokeTool::class, 'revokePreviewLink');
        $parameter = $reflection->getParameters()[0];
        $this->assertSame('resourceKey', $parameter->getName());

        $attributes = $parameter->getAttributes(Schema::class);
        $this->assertCount(1, $attributes);

        $schema = $attributes[0]->newInstance();
        $this->assertSame([ContentTypeSchemaExpander::RESOURCE_KEYS], $schema->enum);
    }
}
