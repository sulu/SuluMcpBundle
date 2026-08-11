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

namespace Sulu\Mcp\UserInterface\Mcp\Tool\Page;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Sulu\Bundle\AdminBundle\Application\BlockIdGenerator\BlockIdGeneratorInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Application\AdminLink\AdminLinkGeneratorInterface;
use Sulu\Mcp\Application\Content\BlockDataNormalizerTrait;
use Sulu\Mcp\Application\Content\BlockDataValidator;
use Sulu\Mcp\Application\Content\ContentMetadataMapper;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Page\Application\Message\CreatePageMessage;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @internal
 */
class PageCreateTool
{
    use HandleTrait;
    use BlockDataNormalizerTrait;

    public function __construct(
        MessageBusInterface $messageBus,
        private readonly ContentManagerInterface $contentManager,
        private readonly BlockDataValidator $blockDataValidator,
        private readonly BlockIdGeneratorInterface $blockIdGenerator,
        private readonly ContentMetadataMapper $contentMetadataMapper,
        private readonly AdminLinkGeneratorInterface $adminLinkGenerator,
        private readonly PageRepositoryInterface $pageRepository,
        private readonly ToolPermissionCheckerInterface $permissionChecker,
    ) {
        $this->messageBus = $messageBus;
    }

    /**
     * @param array<string, mixed>|null $content
     * @param array<string, mixed>|null $excerpt
     * @param array<string, mixed>|null $seo
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'sulu_page_create',
        description: 'Create a new page in a webspace. Workflow: 1) Call sulu_get_context to discover templates and their fields. 2) Call sulu_page_tree to find the parentId (UUID of the parent page under which this page should be created). 3) Choose a template key (e.g. "default") and pass its field values in "content" as a flat object: content={"article": "<p>HTML here</p>"}. Content may also include a full "blocks" tree (nested blocks allowed), e.g. content={"blocks": [{"type": "text", "content": "<p>…</p>"}, {"type": "section", "blocks": [{"type": "text", "content": "<p>…</p>"}]}]} — block _ids are assigned automatically and unknown block fields are rejected before saving. The "title" is a separate parameter — do not repeat it in content. The "url" is auto-generated from the title if omitted. The page is created as a draft — call sulu_content_publish (type: page) afterward to make it live.',
    )]
    #[RequiresPermission(
        requirements: [
            new PermissionRequirement('sulu.webspaces.#context#', PermissionTypes::EDIT),
            new PermissionRequirement('sulu.webspaces.#context#', PermissionTypes::ADD),
        ],
        contextArgument: 'webspace',
    )]
    public function createPage(
        string $webspace,
        string $locale,
        string $template,
        string $title,
        string $parentId,
        ?string $url = null,
        #[Schema(type: 'object', description: 'Template field values as key-value pairs, e.g. {"article": "<p>HTML content</p>"}', additionalProperties: true)]
        ?array $content = null,
        #[Schema(type: 'object', description: 'Optional excerpt/teaser fields keyed by the project\'s excerpt field names (e.g. title, description, more, image, icon, excerptCategories, excerptTags). Media fields take {"id": <mediaId>}. Call sulu_get_context for the exact field list.', additionalProperties: true)]
        ?array $excerpt = null,
        #[Schema(type: 'object', description: 'Optional SEO fields keyed by the project\'s SEO field names (e.g. title, description, keywords, canonicalUrl, seoNoIndex, seoNoFollow, seoHideInSitemap). Call sulu_get_context for the exact field list.', additionalProperties: true)]
        ?array $seo = null,
    ): array {
        try {
            // An unchecked parentId could attach the page under a parent in a different
            // webspace, or one the caller lacks per-page ACL access to. Verify it first.
            $parent = $this->pageRepository->getOneBy(
                [
                    'uuid' => $parentId,
                    'locale' => $locale,
                    'stage' => DimensionContentInterface::STAGE_DRAFT,
                ],
                [
                    PageRepositoryInterface::GROUP_SELECT_PAGE_ADMIN => true,
                ],
            );

            if ($webspace !== $parent->getWebspaceKey()) {
                throw new PermissionDeniedException('sulu.webspaces.'.$parent->getWebspaceKey(), PermissionTypes::EDIT, $locale);
            }

            $this->permissionChecker->check(
                'sulu.webspaces.'.$parent->getWebspaceKey(),
                [PermissionTypes::EDIT, PermissionTypes::ADD],
                $locale,
                Page::class,
                $parentId,
            );

            $normalizedContent = null !== $content ? self::normalizeContent($content) : [];

            if ($validationError = $this->blockDataValidator->validateContentTree($normalizedContent, 'page', $template)) {
                return $validationError;
            }

            $normalizedContent = $this->assignBlockIds($normalizedContent, $this->blockIdGenerator);

            $data = \array_merge($normalizedContent, [
                'locale' => $locale,
                'template' => $template,
                'title' => $title,
                'url' => $url ?? '/'.\mb_strtolower(\str_replace(' ', '-', $title)),
            ]);

            $data = $this->contentMetadataMapper->applyExcerpt($data, $excerpt, $locale);
            if (isset($data['error'])) {
                return $data;
            }
            $data = $this->contentMetadataMapper->applySeo($data, $seo, $locale);
            if (isset($data['error'])) {
                return $data;
            }

            // Force trusted values before dispatch: excerpt/seo must not smuggle a
            // different locale, template, or title.
            $data['locale'] = $locale;
            $data['template'] = $template;
            $data['title'] = $title;

            $message = new CreatePageMessage($webspace, $parentId, $data);

            /** @var PageInterface $page */
            $page = $this->handle(new Envelope($message, [new EnableFlushStamp()]));

            $dimensionContent = $this->contentManager->resolve($page, [
                'locale' => $locale,
                'stage' => DimensionContentInterface::STAGE_DRAFT,
            ]);
            $normalized = $this->contentManager->normalize($dimensionContent);

            $result = [
                'success' => true,
                'uuid' => $page->getUuid(),
                'data' => $normalized,
            ];

            $adminUrl = $this->adminLinkGenerator->generate('page', [
                'webspace' => $webspace,
                'locale' => $locale,
                'uuid' => $page->getUuid(),
            ]);
            if (null !== $adminUrl) {
                $result['admin_url'] = $adminUrl;
            }

            return $result;
        } catch (PermissionDeniedException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            return [
                'error' => \sprintf('Failed to create page "%s" in webspace "%s": %s', $title, $webspace, $e->getMessage()),
                'hint' => 'Verify the template key exists (use sulu_get_context), parentId is a valid page UUID (use sulu_page_tree), and content fields match the template schema.',
            ];
        }
    }
}
