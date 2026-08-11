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
use Mcp\Exception\ToolCallException;
use Sulu\Article\Application\Message\ModifyArticleMessage;
use Sulu\Article\Domain\Model\ArticleInterface;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
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
use Sulu\Mcp\Application\Content\ContentNormalizerTrait;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @internal
 */
class ArticleUpdateTool
{
    use HandleTrait;
    use BlockDataNormalizerTrait;
    use ContentNormalizerTrait;

    public function __construct(
        MessageBusInterface $messageBus,
        private readonly ContentManagerInterface $contentManager,
        private readonly ArticleRepositoryInterface $articleRepository,
        private readonly BlockDataValidator $blockDataValidator,
        private readonly BlockIdGeneratorInterface $blockIdGenerator,
        private readonly ContentMetadataMapper $contentMetadataMapper,
        private readonly AdminLinkGeneratorInterface $adminLinkGenerator,
        private readonly ArticleGroupResolver $articleGroupResolver,
        private readonly ToolPermissionCheckerInterface $permissionChecker,
        private readonly ArticleSecurityContextResolver $articleContextResolver,
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
        name: 'sulu_article_update',
        description: 'Update an existing article. Reads the current article state, merges your changes, and writes back -- so you only need to pass the fields you want to change. Pass template-specific field values in "content" as a flat object: content={"article": "<p>Updated HTML</p>"}. Content may also include a full "blocks" tree (nested blocks allowed) to replace the block content in one call — block _ids are assigned automatically and unknown block fields are rejected before saving. To change routing, pass either content={"url": "/path"} (simple route templates) or content={"page": {"path": "/blog", "uuid": "<parent-uuid>", "suffix": "slug"}} (page_tree_route templates) -- the wrong form is rejected here instead of failing inside Sulu. You can update title and template as separate parameters. The article stays in draft state after updating -- call sulu_content_publish (type: article) to make changes live.',
    )]
    #[RequiresPermission(
        requirements: [new PermissionRequirement('sulu.article.articles', PermissionTypes::EDIT)],
        objectResolved: true,
        discoveryContexts: [ArticleSecurityContextResolver::ANY_ARTICLE_GROUP_CONTEXT],
    )]
    public function updateArticle(
        string $uuid,
        string $locale,
        ?string $title = null,
        ?string $template = null,
        #[Schema(type: 'object', description: 'Template field values as key-value pairs, e.g. {"article": "<p>HTML content</p>"}. To change the URL, pass {"url": "/path"} or {"page": {"path": "/blog", "uuid": "<parent-page-uuid>", "suffix": "my-article"}} matching the template\'s route property type.', additionalProperties: true)]
        ?array $content = null,
        #[Schema(type: 'object', description: 'Optional excerpt/teaser fields keyed by the project\'s excerpt field names (e.g. title, description, more, image, icon, excerptCategories, excerptTags). Media fields take {"id": <mediaId>}. Call sulu_get_context for the exact field list.', additionalProperties: true)]
        ?array $excerpt = null,
        #[Schema(type: 'object', description: 'Optional SEO fields keyed by the project\'s SEO field names (e.g. title, description, keywords, canonicalUrl, seoNoIndex, seoNoFollow, seoHideInSitemap). Call sulu_get_context for the exact field list.', additionalProperties: true)]
        ?array $seo = null,
    ): array {
        try {
            // Read current article state to get template and existing content
            $article = $this->articleRepository->getOneBy(
                [
                    'uuid' => $uuid,
                    'locale' => $locale,
                    'stage' => DimensionContentInterface::STAGE_DRAFT,
                ],
                [
                    ArticleRepositoryInterface::GROUP_SELECT_ARTICLE_ADMIN => true,
                ],
            );

            $currentDimensionContent = $this->contentManager->resolve($article, [
                'locale' => $locale,
                'stage' => DimensionContentInterface::STAGE_DRAFT,
            ]);

            $currentTemplateKey = $currentDimensionContent->getTemplateKey() ?? '';
            $sourceContext = $this->articleContextResolver->forTemplateKey($currentTemplateKey);
            $this->permissionChecker->check(
                $sourceContext,
                PermissionTypes::EDIT,
                $locale,
            );

            // Trusted template: the `template` arg, else the current one. content/excerpt/seo
            // below must not smuggle a different value past this point (not even content.template).
            $effectiveTemplate = $template ?? $currentTemplateKey;
            if ('' === $effectiveTemplate) {
                return ['error' => \sprintf('Failed to update article %s: could not resolve its current template.', $uuid)];
            }

            $targetContext = $this->articleContextResolver->forTemplateKey($effectiveTemplate);
            if ($targetContext !== $sourceContext) {
                $this->permissionChecker->check(
                    $targetContext,
                    PermissionTypes::EDIT,
                    $locale,
                );
            }

            $currentData = $this->contentManager->normalize($currentDimensionContent);

            // Build update data: start with current state, overlay user changes
            $data = \array_merge(
                $currentData,
                ['locale' => $locale],
            );

            if (null !== $title) {
                $data['title'] = $title;
            }
            if (null !== $content) {
                $normalizedContent = self::normalizeContent($content);
                if ($validationError = ArticleRouteValidator::validate($normalizedContent, required: false)) {
                    return $validationError;
                }
                $suluContent = ArticleRouteValidator::normalizeForSulu($normalizedContent);
                if ($blockError = $this->blockDataValidator->validateContentTree($suluContent, 'article', $effectiveTemplate)) {
                    return $blockError;
                }
                $suluContent = $this->assignBlockIds($suluContent, $this->blockIdGenerator);
                $data = \array_merge($data, $suluContent);
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
            // a different locale or template past the checks above.
            $data['locale'] = $locale;
            $data['template'] = $effectiveTemplate;

            $message = new ModifyArticleMessage(['uuid' => $uuid], $data);

            /** @var ArticleInterface $updatedArticle */
            $updatedArticle = $this->handle(new Envelope($message, [new EnableFlushStamp()]));

            $dimensionContent = $this->contentManager->resolve($updatedArticle, [
                'locale' => $locale,
                'stage' => DimensionContentInterface::STAGE_DRAFT,
            ]);
            $normalized = $this->contentManager->normalize($dimensionContent);

            $result = [
                'success' => true,
                'uuid' => $updatedArticle->getUuid(),
                'data' => $this->compactContent($normalized, $this->detectBlockProperties($normalized)),
            ];

            $resolvedTemplate = \is_string($normalized['template'] ?? null) ? $normalized['template'] : null;

            $adminUrl = $this->adminLinkGenerator->generate('article', [
                'locale' => $locale,
                'uuid' => $updatedArticle->getUuid(),
                'group' => $this->articleGroupResolver->resolveByTemplate($resolvedTemplate),
            ]);
            if (null !== $adminUrl) {
                $result['admin_url'] = $adminUrl;
            }

            return $result;
        } catch (PermissionDeniedException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            return [
                'error' => \sprintf('Failed to update article %s: %s', $uuid, $e->getMessage()),
                'hint' => 'Verify the UUID exists (use sulu_article_get) and content fields match the template schema (use sulu_get_context). Pass content as a flat object: content={"article": "<p>...</p>"}.',
            ];
        }
    }
}
