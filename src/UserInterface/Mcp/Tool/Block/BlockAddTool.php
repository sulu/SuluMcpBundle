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
use Sulu\Mcp\Application\Content\BlockDataNormalizerTrait;
use Sulu\Mcp\Application\Content\BlockDataValidator;
use Sulu\Mcp\Application\Content\ContentLocaleTrait;
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
    use ContentLocaleTrait;

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
        title: 'Add Block',
        description: 'Add a content block to a page, article, or snippet. Pass "type" ("page", "article", "snippet", or "product" when SuluProductBundle is installed) and the entity "uuid". Blocks are typed components (e.g. "text", "image", "quote") defined by the project. Workflow: 1) Call sulu_get_context to see available block types and their fields. 2) Find the block property name in the template (e.g. "blocks" or "content"). 3) Pass blockType, blockProperty, and blockData as a flat object mapping the block-type\'s template field names to values, e.g. blockData={"title": "Heading", "description": "<p>Body</p>"}. Unknown keys are rejected against the template schema; the internal {name, value} storage shape is rejected too. The block is appended or inserted at `position` (0-based). To add a block inside another, pass parentBlockId with the parent\'s _id. The entity must be re-published after adding blocks.',
    )]
    #[RequiresPermission(
        requirements: [new PermissionRequirement('#context#', PermissionTypes::EDIT)],
        objectResolved: true,
        discoveryContexts: ['sulu.snippet.snippets', 'sulu.product.products', ArticleSecurityContextResolver::ANY_ARTICLE_GROUP_CONTEXT, WebspacePermissionResolver::ANY_WEBSPACE_CONTEXT],
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
                return ['error' => \sprintf('Unsupported content type "%s". Supported: %s.', $type, \implode(', ', $this->contentTypeResolver->supportedTypes()))];
            }

            $entity = $this->contentTypeResolver->loadDraft($type, $uuid, $locale, loadGhost: true);
            if (null === $entity) {
                return ['error' => \sprintf('%s not found: %s', \ucfirst($type), $uuid)];
            }

            $dimensionContent = $this->contentManager->resolve($entity, [ // @phpstan-ignore argument.type, argument.templateType (upstream generic is invariant; loadDraft() returns a bare object)
                'locale' => $locale,
                'stage' => DimensionContentInterface::STAGE_DRAFT,
            ]);

            $context = $this->contentSecurityContextResolver->forEntityInLocale(
                $type,
                $entity,
                $dimensionContent,
                $locale,
            );
            $this->permissionChecker->check(
                $context,
                PermissionTypes::EDIT,
                $locale,
                'page' === $type ? Page::class : null,
                'page' === $type ? $uuid : null,
            );

            if ($missingTranslation = self::missingBlockTranslationError($dimensionContent, $type, $uuid, $locale)) {
                return $missingTranslation;
            }

            $currentData = $this->contentManager->normalize($dimensionContent);

            /** @var list<array<string, mixed>> $blocks */
            $blocks = $currentData[$blockProperty] ?? [];

            // Normalize blockData: [{"content": "..."}] -> {"content": "..."} or pass through if already flat
            $blockData = $this->normalizeBlockData($blockData);

            // Nested insert: locate the parent before validating, so the new block is
            // validated against the type its target property actually declares.
            $parentPath = null;
            if (null !== $parentBlockId) {
                $parentPath = $this->findBlockPath($currentData, $parentBlockId);
                if (null === $parentPath) {
                    return [
                        'error' => \sprintf('Parent block with _id "%s" not found in %s %s.', $parentBlockId, $type, $uuid),
                        'hint' => 'Use sulu_page_get, sulu_article_get, or sulu_snippet_get to see block summaries with _id values.',
                    ];
                }
            }

            $templateKey = isset($currentData['template']) && \is_string($currentData['template'])
                ? $currentData['template']
                : null;
            $nestedProperty = null !== $parentPath
                ? $this->nestedTargetProperty($currentData, $type, $templateKey, $blockType, $parentPath)
                : null;
            $blockPath = $this->newBlockTypePath($currentData, $blockProperty, $blockType, $parentPath, $nestedProperty);
            if ($validationError = $this->blockDataValidator->validate($type, $templateKey, $blockType, $blockPath, $blockData)) {
                return $validationError;
            }

            $newBlock = $this->stringifyKeys($this->assignBlockIds(\array_merge(['type' => $blockType], $blockData), $this->blockIdGenerator));

            if (null !== $parentPath) {
                $result = $this->insertBlockAtPath($blocks, $parentPath['indices'], $newBlock, $position, $nestedProperty);
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

    /**
     * The property of the parent block the added block belongs in. Asks the template
     * metadata which of the parent's block properties declares $blockType, because the
     * current content cannot tell an empty list from an absent one, which is exactly
     * the state a card list is in before its first item is added. Falls back to the
     * first populated list only when the metadata gives no single answer.
     *
     * @param array<string, mixed> $currentData
     * @param array{property: string, indices: list<int>} $parentPath
     */
    private function nestedTargetProperty(
        array $currentData,
        string $type,
        ?string $templateKey,
        string $blockType,
        array $parentPath,
    ): ?string {
        $parentChain = $this->blockTypePath($currentData, $parentPath['property'], $parentPath['indices']);

        $resolved = $this->blockDataValidator->resolveBlockProperty($type, $templateKey, $parentChain, $blockType);
        if (null !== $resolved) {
            return $resolved;
        }

        /** @var list<array<string, mixed>> $parentBlocks */
        $parentBlocks = $currentData[$parentPath['property']];

        return $this->findNestedBlockKey($this->getBlockAtPath($parentBlocks, $parentPath['indices']));
    }

    /**
     * The (block property, block type) chain the added block will sit at: the target
     * property of the entity, or $nestedProperty inside the parent block.
     *
     * @param array<string, mixed> $currentData
     * @param array{property: string, indices: list<int>}|null $parentPath
     *
     * @return list<array{property: string, type: string}>
     */
    private function newBlockTypePath(
        array $currentData,
        string $blockProperty,
        string $blockType,
        ?array $parentPath,
        ?string $nestedProperty,
    ): array {
        if (null === $parentPath) {
            return [['property' => $blockProperty, 'type' => $blockType]];
        }

        $parentChain = $this->blockTypePath($currentData, $parentPath['property'], $parentPath['indices']);
        if ([] === $parentChain || null === $nestedProperty) {
            return [];
        }

        return [...$parentChain, ['property' => $nestedProperty, 'type' => $blockType]];
    }
}
