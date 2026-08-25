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
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Application\Content\BlockDataNormalizerTrait;
use Sulu\Mcp\Application\Content\ContentLocaleTrait;
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
class BlockRemoveTool
{
    use HandleTrait;
    use BlockDataNormalizerTrait;
    use ContentLocaleTrait;

    public function __construct(
        MessageBusInterface $messageBus,
        private readonly ContentTypeResolver $contentTypeResolver,
        private readonly ContentManagerInterface $contentManager,
        private readonly ToolPermissionCheckerInterface $permissionChecker,
        private readonly ContentSecurityContextResolver $contentSecurityContextResolver,
    ) {
        $this->messageBus = $messageBus;
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'sulu_block_remove',
        title: 'Remove Block',
        description: 'Remove a block from a page, article or snippet by its 0-based index OR its _id (blockId). Pass "type" ("page", "article" or "snippet") and the entity "uuid". Provide EITHER "blockIndex" (0-based) OR "blockId" (the block _id value). Prefer blockId — it is robust because ids do not shift as blocks are added/removed. Call sulu_block_list (or sulu_page_get / sulu_article_get / sulu_snippet_get) first to see the current blocks array and identify which block to remove. The blockProperty must match the template property name that holds blocks. Remaining blocks shift down to fill the gap. The entity must be re-published after removing blocks.',
    )]
    #[RequiresPermission(
        requirements: [new PermissionRequirement('#context#', PermissionTypes::EDIT)],
        objectResolved: true,
        discoveryContexts: ['sulu.snippet.snippets', ArticleSecurityContextResolver::ANY_ARTICLE_GROUP_CONTEXT, WebspacePermissionResolver::ANY_WEBSPACE_CONTEXT],
    )]
    public function removeBlock(
        string $type,
        string $uuid,
        string $locale,
        string $blockProperty,
        #[Schema(type: 'integer', description: '0-based index of the block to remove, e.g. 2. Provide this OR blockId.')]
        ?int $blockIndex = null,
        #[Schema(type: 'string', description: 'The block\'s _id value, e.g. "c6c22b89". Provide this OR blockIndex. Prefer blockId — ids do not shift as blocks are added/removed.')]
        ?string $blockId = null,
    ): array {
        try {
            if (!$this->contentTypeResolver->supports($type)) {
                return ['error' => \sprintf('Unsupported content type "%s". Use "page", "article" or "snippet".', $type)];
            }

            if (null === $blockIndex && null === $blockId) {
                return [
                    'error' => 'Provide either blockIndex (0-based) or blockId (the block _id value).',
                    'hint' => 'e.g. blockIndex=2 or blockId="c6c22b89". Use sulu_block_list to see indices and _id values.',
                ];
            }
            if (null !== $blockIndex && null !== $blockId) {
                return ['error' => 'Provide either blockIndex or blockId, not both.'];
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

            if (null !== $blockId) {
                $resolvedIndex = $this->resolveBlockIndexById($blockId, $blocks);
                if (null === $resolvedIndex) {
                    return [
                        'error' => \sprintf('Block _id "%s" not found in %s %s.', $blockId, $type, $uuid),
                        'hint' => 'Use sulu_block_list to see the current block _id values.',
                    ];
                }
                $blockIndex = $resolvedIndex;
            }

            if ($blockIndex < 0 || $blockIndex >= \count($blocks)) {
                return [
                    'error' => \sprintf(
                        'Block index %d out of range. %s has %d block(s) (valid indices: 0-%d).',
                        $blockIndex,
                        \ucfirst($type),
                        \count($blocks),
                        \max(0, \count($blocks) - 1),
                    ),
                ];
            }

            \array_splice($blocks, $blockIndex, 1);

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
                'removedIndex' => $blockIndex,
                'blockCount' => \count($blocks),
            ];
        } catch (PermissionDeniedException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            return [
                'error' => \sprintf('Failed to remove block from %s %s: %s', $type, $uuid, $e->getMessage()),
                'hint' => 'Use sulu_block_list to see current blocks and their indices and _id values before removing.',
            ];
        }
    }

    /**
     * Resolve a block _id to its current 0-based index.
     * Returns null when the id cannot be found.
     *
     * @param list<array<string, mixed>> $blocks
     */
    private function resolveBlockIndexById(string $blockId, array $blocks): ?int
    {
        foreach ($blocks as $index => $block) {
            if (isset($block['_id']) && $block['_id'] === $blockId) {
                return $index;
            }
        }

        return null;
    }
}
