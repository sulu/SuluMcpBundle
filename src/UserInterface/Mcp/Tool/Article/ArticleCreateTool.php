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

namespace Sulu\Mcp\UserInterface\Mcp\Tool\Article;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Sulu\Article\Application\Message\CreateArticleMessage;
use Sulu\Article\Domain\Model\ArticleInterface;
use Sulu\Bundle\AdminBundle\Application\BlockIdGenerator\BlockIdGeneratorInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Application\AdminLink\AdminLinkGeneratorInterface;
use Sulu\Mcp\Application\Article\ArticleGroupResolver;
use Sulu\Mcp\Application\Article\ArticleRouteValidator;
use Sulu\Mcp\Application\Content\BlockDataNormalizerTrait;
use Sulu\Mcp\Application\Content\BlockDataValidator;
use Sulu\Mcp\Application\Content\ContentMetadataMapper;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @internal
 */
class ArticleCreateTool
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
        private readonly ArticleGroupResolver $articleGroupResolver,
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
        name: 'sulu_article_create',
        description: 'Create a new article (draft). Workflow: 1) Call sulu_get_context to discover article templates and their fields. 2) Choose a template key (e.g. "blog") and pass its field values in "content" as a flat object: content={"article": "<p>HTML here</p>"}. Content may also include a full "blocks" tree (nested blocks allowed), e.g. content={"blocks": [{"type": "text", "content": "<p>…</p>"}]} — block _ids are assigned automatically and unknown block fields are rejected before saving. The "title" is a separate parameter -- do not repeat it in content. IMPORTANT: articles need URL routing data, and the form depends on the template field type. If the template has a property of type "route", pass content={"url": "/my-article"}. If the template has a property of type "page_tree_route" (most blog templates), pass content={"page": {"path": "/blog", "uuid": "<parent-page-uuid>", "suffix": "my-article"}}. The wrong form is rejected here (so you do not get a silent url=null). Leave type unset unless the project defines multiple article types. After create, call sulu_content_publish (type: article) to make it live.',
    )]
    #[RequiresPermission(
        requirements: [
            new PermissionRequirement('#context#', PermissionTypes::EDIT),
            new PermissionRequirement('#context#', PermissionTypes::ADD),
        ],
        contextResolver: 'sulu_mcp.article_context_resolver',
    )]
    public function createArticle(
        string $locale,
        string $template,
        string $title,
        #[Schema(description: 'Optional article type key, only for projects that configure multiple article types in their article template metadata. Omit to use the default type. Most installs do not need this.')]
        ?string $type = null,
        #[Schema(type: 'object', description: 'Template field values as key-value pairs, e.g. {"article": "<p>HTML content</p>"}. Must include URL routing data: either {"url": "/path"} for simple route templates, or {"page": {"path": "/blog", "uuid": "<parent-page-uuid>", "suffix": "my-article"}} for page_tree_route templates.', additionalProperties: true)]
        ?array $content = null,
        #[Schema(type: 'object', description: 'Optional excerpt/teaser fields keyed by the project\'s excerpt field names (e.g. title, description, more, image, icon, excerptCategories, excerptTags). Media fields take {"id": <mediaId>}. Call sulu_get_context for the exact field list.', additionalProperties: true)]
        ?array $excerpt = null,
        #[Schema(type: 'object', description: 'Optional SEO fields keyed by the project\'s SEO field names (e.g. title, description, keywords, canonicalUrl, seoNoIndex, seoNoFollow, seoHideInSitemap). Call sulu_get_context for the exact field list.', additionalProperties: true)]
        ?array $seo = null,
    ): array {
        try {
            $normalizedContent = null !== $content ? self::normalizeContent($content) : [];

            if ($validationError = ArticleRouteValidator::validate($normalizedContent, required: true)) {
                return $validationError;
            }

            $suluContent = ArticleRouteValidator::normalizeForSulu($normalizedContent);

            if ($blockError = $this->blockDataValidator->validateContentTree($suluContent, 'article', $template)) {
                return $blockError;
            }

            $suluContent = $this->stringifyKeys($this->assignBlockIds($suluContent, $this->blockIdGenerator));

            $data = \array_merge($suluContent, [
                'locale' => $locale,
                'template' => $template,
                'title' => $title,
            ]);

            if (null !== $type) {
                $data['type'] = $type;
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

            // Force the trusted top-level values immediately before dispatch -- custom
            // excerpt/seo metadata fields above must not be able to smuggle a different
            // locale, template, or title (the EDIT+ADD preflight only ever checked
            // the `template` arg).
            $data['locale'] = $locale;
            $data['template'] = $template;
            $data['title'] = $title;
            if (null !== $type) {
                $data['type'] = $type;
            }

            $message = new CreateArticleMessage($data);

            /** @var ArticleInterface $article */
            $article = $this->handle(new Envelope($message, [new EnableFlushStamp()]));

            $dimensionContent = $this->contentManager->resolve($article, [
                'locale' => $locale,
                'stage' => DimensionContentInterface::STAGE_DRAFT,
            ]);
            $normalized = $this->contentManager->normalize($dimensionContent);

            if ($postCheckError = ArticleRouteValidator::assertRoutingResolved($normalized, $normalizedContent)) {
                $postCheckError['uuid'] = $article->getUuid();

                return $postCheckError;
            }

            $result = [
                'success' => true,
                'uuid' => $article->getUuid(),
                'data' => $normalized,
            ];

            $adminUrl = $this->adminLinkGenerator->generate('article', [
                'locale' => $locale,
                'uuid' => $article->getUuid(),
                'group' => $this->articleGroupResolver->resolveByTemplate($template),
            ]);
            if (null !== $adminUrl) {
                $result['admin_url'] = $adminUrl;
            }

            return $result;
        } catch (\Throwable $e) {
            return [
                'error' => \sprintf('Failed to create article "%s": %s', $title, $e->getMessage()),
                'hint' => 'Verify the template key exists (use sulu_get_context) and content fields match the template schema.',
            ];
        }
    }
}
