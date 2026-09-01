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
class BlockUpdateTool
{
    use HandleTrait;
    use ContentNormalizerTrait;
    use BlockDataNormalizerTrait;
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
        name: 'sulu_block_update',
        title: 'Update Block',
        description: 'Update a single block, addressed by its _id (blockId) or by its 0-based index at the top level of a block property (blockIndex plus blockProperty, which may be left out when the entity has a single block property). Prefer blockId — ids do not shift as blocks are added or removed, and only blockId reaches nested blocks. Content that was not created through these tools carries no _id, and blockIndex is the way in. Pass blockData as a flat object mapping the block-type\'s template field names to new values, e.g. blockData={"title": "New heading"}. Only the keys you pass are changed; other fields are preserved. Unknown keys are rejected against the block type\'s schema; the internal {name, value} storage shape is rejected too. Use sulu_page_get, sulu_article_get, or sulu_snippet_get to find block _id values (returned in block summaries), and sulu_block_list to read full content before updating. The entity must be re-published after updating blocks.',
    )]
    #[RequiresPermission(
        requirements: [new PermissionRequirement('#context#', PermissionTypes::EDIT)],
        objectResolved: true,
        discoveryContexts: ['sulu.snippet.snippets', 'sulu.product.products', ArticleSecurityContextResolver::ANY_ARTICLE_GROUP_CONTEXT, WebspacePermissionResolver::ANY_WEBSPACE_CONTEXT],
    )]
    public function updateBlock(
        string $type,
        string $uuid,
        string $locale,
        #[Schema(type: 'object', description: 'Changed block field values as a flat object, e.g. {"content": "<p>Updated</p>"}', additionalProperties: true)]
        array $blockData,
        #[Schema(type: 'string', description: 'The block\'s _id value, e.g. "c6c22b89". Provide this OR blockIndex. Prefer blockId — ids do not shift, and only blockId addresses nested blocks.')]
        ?string $blockId = null,
        #[Schema(type: 'integer', description: '0-based index of the block at the top level of blockProperty, e.g. 2. Provide this OR blockId. The only way to address a block that carries no _id.')]
        ?int $blockIndex = null,
        #[Schema(type: 'string', description: 'Template property holding the blocks, e.g. "blocks". Only used with blockIndex, and only needed when the entity has more than one block property.')]
        ?string $blockProperty = null,
    ): array {
        if (!$this->contentTypeResolver->supports($type)) {
            return ['error' => \sprintf('Unsupported content type "%s". Supported: %s.', $type, \implode(', ', $this->contentTypeResolver->supportedTypes()))];
        }

        if (null === $blockId && null === $blockIndex) {
            return [
                'error' => 'Provide either blockId (the block _id value) or blockIndex (0-based).',
                'hint' => 'e.g. blockId="c6c22b89" or blockIndex=2. Use sulu_block_list to see indices and _id values.',
            ];
        }

        if (null !== $blockId && null !== $blockIndex) {
            return ['error' => 'Provide either blockId or blockIndex, not both.'];
        }

        try {
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

            if (null !== $blockId) {
                // Find the block by _id anywhere in the block tree (including nested blocks)
                $found = $this->findBlockPath($currentData, $blockId);

                if (null === $found) {
                    return [
                        'error' => \sprintf('Block with _id "%s" not found in %s %s.', $blockId, $type, $uuid),
                        'hint' => 'Use sulu_page_get, sulu_article_get, or sulu_snippet_get to see block summaries with _id values.',
                    ];
                }
            } else {
                $found = $this->resolveBlockPathByIndex($currentData, $blockProperty, $blockIndex);

                if (isset($found['error'])) {
                    return $found;
                }
            }

            $foundProperty = $found['property'];
            $foundIndices = $found['indices'];

            // Merge new data over existing block (partial update)
            $blockData = $this->normalizeBlockData($blockData);

            /** @var list<array<string, mixed>> $blocksAtFoundProperty */
            $blocksAtFoundProperty = $currentData[$foundProperty];
            $existingBlock = $this->getBlockAtPath($blocksAtFoundProperty, $foundIndices);
            $blockType = isset($existingBlock['type']) && \is_string($existingBlock['type'])
                ? $existingBlock['type']
                : null;
            $templateKey = isset($currentData['template']) && \is_string($currentData['template'])
                ? $currentData['template']
                : null;
            $blockPath = $this->blockTypePath($currentData, $foundProperty, $foundIndices);
            if (null !== $blockType && $validationError = $this->blockDataValidator->validate($type, $templateKey, $blockType, $blockPath, $blockData)) {
                return $validationError;
            }

            $blockData = $this->stringifyKeys($this->assignBlockIds($blockData, $this->blockIdGenerator));

            /** @var list<array<string, mixed>> $topLevelBlocks */
            $topLevelBlocks = $currentData[$foundProperty];
            $currentData[$foundProperty] = $this->setBlockAtPath($topLevelBlocks, $foundIndices, $blockData);

            $data = [
                'locale' => $locale,
                'template' => $currentData['template'] ?? null,
                'title' => $currentData['title'] ?? null,
                $foundProperty => $currentData[$foundProperty],
            ];

            $data = $this->stringifyKeys($data);

            $message = $this->contentTypeResolver->createModifyMessage($type, $uuid, $data);

            $this->handle(new Envelope($message, [new EnableFlushStamp()]));

            $result = [
                'success' => true,
                'uuid' => $uuid,
                'blockProperty' => $foundProperty,
                'blockPath' => $foundIndices,
            ];

            if (null !== $blockId) {
                $result['blockId'] = $blockId;
            }

            return $result;
        } catch (PermissionDeniedException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            return [
                'error' => \sprintf('Failed to update block "%s" in %s %s: %s', $blockId ?? '#' . $blockIndex, $type, $uuid, $e->getMessage()),
                'hint' => 'Verify the UUID exists and the block _id or index is correct (use sulu_block_list to check).',
            ];
        }
    }

    /**
     * The path shape {@see findBlockPath()} returns, or an error array when the property
     * is ambiguous, unknown, or the index is out of range.
     *
     * @param array<string, mixed> $data
     *
     * @return array{property: string, indices: list<int>}|array{error: string, hint?: string}
     */
    private function resolveBlockPathByIndex(array $data, ?string $blockProperty, int $blockIndex): array
    {
        $properties = $this->indexableBlockProperties($data);

        if (null === $blockProperty) {
            if (1 !== \count($properties)) {
                return [
                    'error' => [] === $properties
                        ? 'The entity has no block property to index into.'
                        : \sprintf('The entity has more than one block property (%s), so blockProperty is required with blockIndex.', \implode(', ', $properties)),
                    'hint' => 'Use sulu_block_list to see the block properties and their contents.',
                ];
            }

            $blockProperty = $properties[0];
        } elseif (!\in_array($blockProperty, $properties, true)) {
            return [
                'error' => \sprintf('"%s" is not a block property of this entity.', $blockProperty),
                'hint' => [] === $properties
                    ? 'The entity has no block property.'
                    : \sprintf('Block properties: %s.', \implode(', ', $properties)),
            ];
        }

        /** @var list<array<string, mixed>> $blocks */
        $blocks = $data[$blockProperty];

        if ($blockIndex < 0 || $blockIndex >= \count($blocks)) {
            return [
                'error' => \sprintf(
                    'Block index %d out of range. "%s" has %d block(s) (valid indices: 0-%d).',
                    $blockIndex,
                    $blockProperty,
                    \count($blocks),
                    \max(0, \count($blocks) - 1),
                ),
            ];
        }

        return ['property' => $blockProperty, 'indices' => [$blockIndex]];
    }

    /**
     * Block properties addressable by index.
     *
     * {@see ContentNormalizerTrait::detectBlockProperties()} requires an `_id`, which is
     * exactly what the content this addresses lacks -- ids come from these tools, not Sulu.
     *
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function indexableBlockProperties(array $data): array
    {
        $properties = [];

        foreach ($data as $key => $value) {
            if (!\is_array($value) || !\array_is_list($value) || [] === $value) {
                continue;
            }

            foreach ($value as $item) {
                if (\is_array($item) && isset($item['type'])) {
                    $properties[] = $key;

                    continue 2;
                }
            }
        }

        return $properties;
    }
}
