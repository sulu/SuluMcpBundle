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
use Sulu\Mcp\Application\Content\ContentNormalizerTrait;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Page\Application\Message\ModifyPageMessage;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @internal
 */
class PageUpdateTool
{
    use HandleTrait;
    use BlockDataNormalizerTrait;
    use ContentNormalizerTrait;

    public function __construct(
        MessageBusInterface $messageBus,
        private readonly ContentManagerInterface $contentManager,
        private readonly PageRepositoryInterface $pageRepository,
        private readonly BlockDataValidator $blockDataValidator,
        private readonly BlockIdGeneratorInterface $blockIdGenerator,
        private readonly ContentMetadataMapper $contentMetadataMapper,
        private readonly AdminLinkGeneratorInterface $adminLinkGenerator,
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
        name: 'sulu_page_update',
        description: 'Update an existing page. Reads the current page state, merges your changes, and writes back — so you only need to pass the fields you want to change. Pass template-specific field values in "content" as a flat object: content={"article": "<p>Updated HTML</p>"}. Content may also include a full "blocks" tree (nested blocks allowed) to replace the block content in one call — block _ids are assigned automatically and unknown block fields are rejected before saving. You can update title, url, and template as separate parameters. The page stays in draft state after updating — call sulu_content_publish (type: page) to make changes live.',
    )]
    #[RequiresPermission(
        requirements: [new PermissionRequirement('sulu.webspaces.#context#', PermissionTypes::EDIT)],
        objectResolved: true,
        discoveryContexts: [WebspacePermissionResolver::ANY_WEBSPACE_CONTEXT],
    )]
    public function updatePage(
        string $uuid,
        string $locale,
        ?string $title = null,
        ?string $url = null,
        ?string $template = null,
        #[Schema(type: 'object', description: 'Template field values as key-value pairs, e.g. {"article": "<p>HTML content</p>"}', additionalProperties: true)]
        ?array $content = null,
        #[Schema(type: 'object', description: 'Optional excerpt/teaser fields keyed by the project\'s excerpt field names (e.g. title, description, more, image, icon, excerptCategories, excerptTags). Media fields take {"id": <mediaId>}. Call sulu_get_context for the exact field list.', additionalProperties: true)]
        ?array $excerpt = null,
        #[Schema(type: 'object', description: 'Optional SEO fields keyed by the project\'s SEO field names (e.g. title, description, keywords, canonicalUrl, seoNoIndex, seoNoFollow, seoHideInSitemap). Call sulu_get_context for the exact field list.', additionalProperties: true)]
        ?array $seo = null,
    ): array {
        try {
            // Read current page state to get template and existing content
            $page = $this->pageRepository->getOneBy(
                [
                    'uuid' => $uuid,
                    'locale' => $locale,
                    'stage' => DimensionContentInterface::STAGE_DRAFT,
                ],
                [
                    PageRepositoryInterface::GROUP_SELECT_PAGE_ADMIN => true,
                ],
            );

            // Context comes from the loaded entity; the page ACL is checked object-level.
            $this->permissionChecker->check(
                'sulu.webspaces.' . $page->getWebspaceKey(),
                PermissionTypes::EDIT,
                $locale,
                Page::class,
                $uuid,
            );

            $currentDimensionContent = $this->contentManager->resolve($page, [
                'locale' => $locale,
                'stage' => DimensionContentInterface::STAGE_DRAFT,
            ]);
            $currentData = $this->contentManager->normalize($currentDimensionContent);

            // Trusted template: the `template` arg, else the current one. content/excerpt/seo
            // below must not smuggle a different value past this point.
            $currentTemplateKey = \is_string($currentData['template'] ?? null) ? $currentData['template'] : null;
            $effectiveTemplate = $template ?? $currentTemplateKey;

            // Build update data: start with current state, overlay user changes
            $data = \array_merge(
                $currentData,
                ['locale' => $locale],
            );

            if (null !== $title) {
                $data['title'] = $title;
            }
            if (null !== $url) {
                $data['url'] = $url;
            }
            if (null !== $content) {
                $normalizedContent = self::normalizeContent($content);
                if ($validationError = $this->blockDataValidator->validateContentTree($normalizedContent, 'page', $effectiveTemplate)) {
                    return $validationError;
                }
                $normalizedContent = $this->assignBlockIds($normalizedContent, $this->blockIdGenerator);
                $data = \array_merge($data, $normalizedContent);
            }

            $data = $this->contentMetadataMapper->applyExcerpt($data, $excerpt, $locale);
            if (isset($data['error'])) {
                return $data;
            }
            $data = $this->contentMetadataMapper->applySeo($data, $seo, $locale);
            if (isset($data['error'])) {
                return $data;
            }

            // Ensure all array keys are strings (Sulu's MetadataResolver requires string keys)
            $data = $this->stringifyKeys($data);

            // Force trusted values before dispatch: content/excerpt/seo must not smuggle
            // a different locale or template.
            $data['locale'] = $locale;
            $data['template'] = $effectiveTemplate;

            $message = new ModifyPageMessage(['uuid' => $uuid], $data);

            /** @var PageInterface $updatedPage */
            $updatedPage = $this->handle(new Envelope($message, [new EnableFlushStamp()]));

            $dimensionContent = $this->contentManager->resolve($updatedPage, [
                'locale' => $locale,
                'stage' => DimensionContentInterface::STAGE_DRAFT,
            ]);
            $normalized = $this->contentManager->normalize($dimensionContent);

            $result = [
                'success' => true,
                'uuid' => $updatedPage->getUuid(),
                'data' => $this->compactContent($normalized, $this->detectBlockProperties($normalized)),
            ];

            $adminUrl = $this->adminLinkGenerator->generate('page', [
                'webspace' => $updatedPage->getWebspaceKey(),
                'locale' => $locale,
                'uuid' => $updatedPage->getUuid(),
            ]);
            if (null !== $adminUrl) {
                $result['admin_url'] = $adminUrl;
            }

            return $result;
        } catch (PermissionDeniedException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            return [
                'error' => \sprintf('Failed to update page %s: %s', $uuid, $e->getMessage()),
                'hint' => 'Verify the UUID exists (use sulu_page_get) and content fields match the template schema (use sulu_get_context). Pass content as a flat object: content={"article": "<p>...</p>"}.',
            ];
        }
    }
}
