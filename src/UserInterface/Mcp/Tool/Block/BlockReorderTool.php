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
use Sulu\Content\Domain\Model\TemplateInterface;
use Sulu\Mcp\Application\Content\BlockDataNormalizerTrait;
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
class BlockReorderTool
{
    use HandleTrait;
    use BlockDataNormalizerTrait;

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
     * @param list<int>|null $newOrder
     * @param array<int, mixed>|null $blockIds
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'sulu_block_reorder',
        description: 'Reorder blocks on a page, article, or snippet. Pass "type" ("page", "article" or "snippet") and the entity "uuid", plus EITHER "newOrder" (every current 0-based index exactly once, e.g. [2,0,1]) OR "blockIds" (the block _id values in the desired order, e.g. ["c6c22b89","76541424"]). Prefer blockIds — it is robust because ids do not shift as blocks are added/removed. Get the current order/ids from sulu_block_list (or sulu_page_get / sulu_article_get / sulu_snippet_get). The entity must be re-published after reordering.',
    )]
    #[RequiresPermission(
        requirements: [new PermissionRequirement('#context#', PermissionTypes::EDIT)],
        objectResolved: true,
        discoveryContexts: ['sulu.snippet.snippets', ArticleSecurityContextResolver::ANY_ARTICLE_GROUP_CONTEXT, WebspacePermissionResolver::ANY_WEBSPACE_CONTEXT],
    )]
    public function reorderBlocks(
        string $type,
        string $uuid,
        string $locale,
        string $blockProperty,
        #[Schema(type: 'array', description: 'New block order as 0-based indices, e.g. [2, 0, 1]. Provide this OR blockIds.', items: ['type' => 'integer'])]
        ?array $newOrder = null,
        #[Schema(type: 'array', description: 'Block _id values in the desired order, e.g. ["c6c22b89", "76541424"]. Provide this OR newOrder.', items: ['type' => 'string'])]
        ?array $blockIds = null,
    ): array {
        try {
            if (!$this->contentTypeResolver->supports($type)) {
                return ['error' => \sprintf('Unsupported content type "%s". Use "page", "article" or "snippet".', $type)];
            }

            if (null === $newOrder && null === $blockIds) {
                return [
                    'error' => 'Provide either newOrder (0-based indices) or blockIds (ordered list of block _id values).',
                    'hint' => 'e.g. newOrder=[2,0,1] or blockIds=["<id-c>","<id-a>","<id-b>"].',
                ];
            }
            if (null !== $newOrder && null !== $blockIds) {
                return ['error' => 'Provide either newOrder or blockIds, not both.'];
            }

            $normalizedNewOrder = null;
            if (null !== $newOrder) {
                $normalizedNewOrder = $this->normalizeBlockOrder($newOrder);
                if (null === $normalizedNewOrder) {
                    return [
                        'error' => 'newOrder must contain only integer indices.',
                        'hint' => 'Pass every current block index exactly once, e.g. [2, 0, 1].',
                    ];
                }
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

            if (null !== $normalizedNewOrder) {
                $order = $normalizedNewOrder;
            } else {
                $order = $this->resolveBlockIdOrder($blockIds, $blocks);
                if (\is_string($order)) {
                    return [
                        'error' => \sprintf('Block _id "%s" not found in %s %s.', $order, $type, $uuid),
                        'hint' => 'Use sulu_block_list to see the current block _id values.',
                    ];
                }
            }

            if (\count($order) !== \count($blocks)) {
                return [
                    'error' => \sprintf('Order length (%d) does not match block count (%d).', \count($order), \count($blocks)),
                ];
            }

            $sorted = $order;
            \sort($sorted);
            if ($sorted !== \range(0, \count($blocks) - 1)) {
                // Echo back what the caller passed (block ids or indices), not the resolved indices.
                $supplied = $normalizedNewOrder ?? $blockIds;

                return [
                    'error' => 'The order must reference each block from 0 to '
                        . (\count($blocks) - 1)
                        . ' exactly once. Got: [' . \implode(', ', \array_map(static fn (mixed $v): string => \is_scalar($v) ? (string) $v : \gettype($v), $supplied)) . ']',
                ];
            }

            $reordered = \array_map(
                static fn (int $i): array => $blocks[$i],
                $order,
            );

            $data = [
                'locale' => $locale,
                'template' => $currentData['template'] ?? null,
                'title' => $currentData['title'] ?? null,
                $blockProperty => $reordered,
            ];

            // Ensure all array keys are strings (Sulu's MetadataResolver requires string keys)
            $data = $this->stringifyKeys($data);

            $message = $this->contentTypeResolver->createModifyMessage($type, $uuid, $data);

            $this->handle(new Envelope($message, [new EnableFlushStamp()]));

            return [
                'success' => true,
                'uuid' => $uuid,
                'blockCount' => \count($reordered),
                'order' => $order,
            ];
        } catch (PermissionDeniedException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            return [
                'error' => \sprintf('Failed to reorder blocks on %s %s: %s', $type, $uuid, $e->getMessage()),
                'hint' => 'Use sulu_block_list to see current blocks. Provide newOrder or blockIds covering every block exactly once.',
            ];
        }
    }

    /**
     * Resolve an ordered list of block _id values to their current 0-based indices.
     * Returns the offending id as a string when one cannot be found.
     *
     * @param array<int, mixed> $blockIds
     * @param list<array<string, mixed>> $blocks
     *
     * @return list<int>|string
     */
    private function resolveBlockIdOrder(array $blockIds, array $blocks): array|string
    {
        $idToIndex = [];
        foreach ($blocks as $index => $block) {
            if (isset($block['_id']) && \is_string($block['_id'])) {
                $idToIndex[$block['_id']] = $index;
            }
        }

        $order = [];
        foreach ($blockIds as $id) {
            if (!\is_string($id) || !isset($idToIndex[$id])) {
                return \is_string($id) ? $id : \get_debug_type($id);
            }
            $order[] = $idToIndex[$id];
        }

        return $order;
    }
}
