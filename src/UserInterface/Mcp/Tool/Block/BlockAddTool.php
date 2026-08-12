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

namespace Sulu\Mcp\UserInterface\Mcp\Tool\Block;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Sulu\Bundle\AdminBundle\Application\BlockIdGenerator\BlockIdGeneratorInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\TemplateInterface;
use Sulu\Mcp\Application\Content\BlockDataNormalizerTrait;
use Sulu\Mcp\Application\Content\BlockDataValidator;
use Sulu\Mcp\Application\Content\ContentNormalizerTrait;
use Sulu\Mcp\Application\Content\ContentTypeResolver;
use Sulu\Mcp\Application\Security\ContentSecurityContextResolver;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Page\Domain\Model\Page;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @internal
 */
class BlockAddTool
{
    use HandleTrait;
    use BlockDataNormalizerTrait;
    use ContentNormalizerTrait;

    public function __construct(
        MessageBusInterface $messageBus,
        private readonly ContentTypeResolver $contentTypeResolver,
        private readonly ContentManagerInterface $contentManager,
        private readonly BlockIdGeneratorInterface $blockIdGenerator,
        private readonly BlockDataValidator $blockDataValidator,
        private readonly ToolPermissionCheckerInterface $permissionChecker,
        private readonly ContentSecurityContextResolver $contentSecurityContextResolver,
    ) {
        $this->messageBus = $messageBus;
    }

    /**
     * @param array<string, mixed> $blockData
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'sulu_block_add',
        description: 'Add a content block to a page, article, or snippet. Pass "type" ("page", "article" or "snippet") and the entity "uuid". Blocks are typed components (e.g. "text", "image", "quote") defined by the project. Workflow: 1) Call sulu_get_context to see available block types and their fields. 2) Find the block property name in the template (e.g. "blocks" or "content"). 3) Pass blockType, blockProperty, and blockData as a flat object mapping the block-type\'s template field names to values, e.g. blockData={"title": "Heading", "description": "<p>Body</p>"}. Unknown keys are rejected against the template schema; the internal {name, value} storage shape is rejected too. The block is appended or inserted at `position` (0-based). To add a block inside another, pass parentBlockId with the parent\'s _id. The entity must be re-published after adding blocks.',
    )]
    #[RequiresPermission(
        requirements: [new PermissionRequirement('#context#', PermissionTypes::EDIT)],
        objectResolved: true,
        discoveryContexts: ['sulu.snippet.snippets', ArticleSecurityContextResolver::ANY_ARTICLE_GROUP_CONTEXT, WebspacePermissionResolver::ANY_WEBSPACE_CONTEXT],
    )]
    public function addBlock(
        string $type,
        string $uuid,
        string $locale,
        string $blockType,
        string $blockProperty,
        #[Schema(type: 'object', description: 'Block field values as a flat object, e.g. {"title": "Heading", "description": "<p>Body</p>"}', additionalProperties: true)]
        array $blockData = [],
        ?int $position = null,
        ?string $parentBlockId = null,
    ): array {
        try {
            if (!$this->contentTypeResolver->supports($type)) {
                return ['error' => \sprintf('Unsupported content type "%s". Use "page", "article" or "snippet".', $type)];
            }

            $entity = $this->contentTypeResolver->loadDraft($type, $uuid, $locale);
            if (null === $entity) {
                return ['error' => \sprintf('%s not found: %s', \ucfirst($type), $uuid)];
            }

            $dimensionContent = $this->contentManager->resolve($entity, [ // @phpstan-ignore argument.type, argument.templateType (upstream generic is invariant; loadDraft() returns a bare object)
                'locale' => $locale,
                'stage' => DimensionContentInterface::STAGE_DRAFT,
            ]);

            $context = $this->contentSecurityContextResolver->forEntity(
                $type,
                $entity,
                $dimensionContent instanceof TemplateInterface ? $dimensionContent : null,
            );
            $this->permissionChecker->check(
                $context,
                PermissionTypes::EDIT,
                $locale,
                'page' === $type ? Page::class : null,
                'page' === $type ? $uuid : null,
            );

            $currentData = $this->contentManager->normalize($dimensionContent);

            /** @var list<array<string, mixed>> $blocks */
            $blocks = $currentData[$blockProperty] ?? [];

            // Normalize blockData: [{"content": "..."}] -> {"content": "..."} or pass through if already flat
            $blockData = $this->normalizeBlockData($blockData);

            $templateKey = isset($currentData['template']) && \is_string($currentData['template'])
                ? $currentData['template']
                : null;
            if ($validationError = $this->blockDataValidator->validate($type, $templateKey, $blockType, $blockData)) {
                return $validationError;
            }

            $newBlock = $this->stringifyKeys($this->assignBlockIds(\array_merge(['type' => $blockType], $blockData), $this->blockIdGenerator));

            if (null !== $parentBlockId) {
                // Nested insert: find the parent block and add inside it
                $parentPath = $this->findBlockPath($currentData, $parentBlockId);
                if (null === $parentPath) {
                    return [
                        'error' => \sprintf('Parent block with _id "%s" not found in %s %s.', $parentBlockId, $type, $uuid),
                        'hint' => 'Use sulu_page_get, sulu_article_get, or sulu_snippet_get to see block summaries with _id values.',
                    ];
                }
                $result = $this->insertBlockAtPath($blocks, $parentPath['indices'], $newBlock, $position);
                if (null === $result) {
                    return ['error' => \sprintf('Could not insert block into parent "%s" — no nested block list found.', $parentBlockId)];
                }
                $blocks = $result['blocks'];
                $addedAt = $result['addedAt'];
            } elseif (null !== $position && $position >= 0 && $position <= \count($blocks)) {
                \array_splice($blocks, $position, 0, [$newBlock]);
                $addedAt = $position;
            } else {
                $blocks[] = $newBlock;
                $addedAt = \count($blocks) - 1;
            }

            $data = [
                'locale' => $locale,
                'template' => $currentData['template'] ?? null,
                'title' => $currentData['title'] ?? null,
                $blockProperty => $blocks,
            ];

            // Ensure all array keys are strings (Sulu's MetadataResolver requires string keys)
            $data = $this->stringifyKeys($data);

            $message = $this->contentTypeResolver->createModifyMessage($type, $uuid, $data);

            $this->handle(new Envelope($message, [new EnableFlushStamp()]));

            return [
                'success' => true,
                'uuid' => $uuid,
                'blockId' => $newBlock['_id'] ?? null,
                'blockType' => $blockType,
                'blockCount' => \count($blocks),
                'addedAt' => $addedAt,
            ];
        } catch (PermissionDeniedException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            return [
                'error' => \sprintf('Failed to add %s block to %s %s: %s', $blockType, $type, $uuid, $e->getMessage()),
                'hint' => 'Verify the UUID exists (use sulu_page_get, sulu_article_get, or sulu_snippet_get), the blockProperty matches a block field in the template, and blockType is a valid block type (use sulu_get_context to see available types).',
            ];
        }
    }
}
