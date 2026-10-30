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
use Mcp\Schema\ToolAnnotations;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Application\Content\BlockDataNormalizerTrait;
use Sulu\Mcp\Application\Content\ContentLocaleTrait;
use Sulu\Mcp\Application\Content\ContentNormalizerTrait;
use Sulu\Mcp\Application\Content\ContentTypeExtensionRegistry;
use Sulu\Mcp\Application\Content\ContentTypeResolver;
use Sulu\Mcp\Application\Content\ContentTypeSchemaExpander;
use Sulu\Mcp\Application\Error\ThrowableMessage;
use Sulu\Mcp\Application\Security\ContentSecurityContextResolver;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\Domain\Security\DangerousTool;
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
class BlockRemoveTool
{
    use HandleTrait;
    use ContentNormalizerTrait;
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
        description: 'Remove a block from a content entity, by its 0-based index OR its _id (blockId). Pass "resourceKey" (one of {resourceKeys}) and the entity "uuid". Provide EITHER "blockIndex" (0-based) OR "blockId" (the block _id value). Prefer blockId — ids do not shift as blocks are added/removed, and only blockId reaches nested blocks (blockProperty is still the top-level property). Call sulu_block_list (or sulu_page_get / sulu_article_get / sulu_snippet_get) first to see the current blocks array and identify which block to remove. The blockProperty must match the template property name that holds blocks. Remaining blocks shift down to fill the gap. The entity must be re-published after removing blocks.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false),
    )]
    #[DangerousTool('block_remove')]
    #[RequiresPermission(
        requirements: [new PermissionRequirement('#context#', PermissionTypes::EDIT)],
        objectResolved: true,
        discoveryContexts: [ContentTypeExtensionRegistry::ANY_EXTENSION_CONTEXT, ArticleSecurityContextResolver::ANY_ARTICLE_GROUP_CONTEXT, WebspacePermissionResolver::ANY_WEBSPACE_CONTEXT],
    )]
    public function removeBlock(
        #[Schema(description: 'The resourceKey of the content type: {resourceKeys}.', enum: [ContentTypeSchemaExpander::RESOURCE_KEYS])]
        string $resourceKey,
        string $uuid,
        string $locale,
        string $blockProperty,
        #[Schema(type: 'integer', description: '0-based index of the block to remove, e.g. 2. Provide this OR blockId.')]
        ?int $blockIndex = null,
        #[Schema(type: 'string', description: 'The block\'s _id value, e.g. "c6c22b89". Provide this OR blockIndex. Prefer blockId — ids do not shift, and only blockId addresses nested blocks.')]
        ?string $blockId = null,
    ): array {
        try {
            if (!$this->contentTypeResolver->supports($resourceKey)) {
                return ['error' => \sprintf('Unsupported content type "%s". Supported: %s.', $resourceKey, \implode(', ', $this->contentTypeResolver->supportedResourceKeys()))];
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

            $entity = $this->contentTypeResolver->loadDraft($resourceKey, $uuid, $locale, loadGhost: true);
            if (null === $entity) {
                return ['error' => \sprintf('%s not found: %s', \ucfirst($resourceKey), $uuid)];
            }

            $dimensionContent = $this->contentManager->resolve($entity, [ // @phpstan-ignore argument.type, argument.templateType (upstream generic is invariant; loadDraft() returns a bare object)
                'locale' => $locale,
                'stage' => DimensionContentInterface::STAGE_DRAFT,
            ]);

            $security = $this->contentSecurityContextResolver->forEntity($resourceKey, $entity, $locale);
            $this->permissionChecker->check(
                $security->context,
                PermissionTypes::EDIT,
                $locale,
                $security->aclObjectType,
                null !== $security->aclObjectType ? $uuid : null,
            );

            if ($missingTranslation = self::missingBlockTranslationError($dimensionContent, $resourceKey, $uuid, $locale)) {
                return $missingTranslation;
            }

            $currentData = $this->contentManager->normalize($dimensionContent);

            /** @var list<array<string, mixed>> $blocks */
            $blocks = $currentData[$blockProperty] ?? [];

            if (null !== $blockId) {
                $nestedRemoval = $this->removeBlockById($blocks, $blockId);
                if (null === $nestedRemoval) {
                    return [
                        'error' => \sprintf('Block _id "%s" not found in %s %s, property "%s".', $blockId, $resourceKey, $uuid, $blockProperty),
                        'hint' => 'Use sulu_block_list to see the current block _id values. blockProperty must be the top-level property that holds the block, also for nested blocks.',
                    ];
                }
                $blocks = $nestedRemoval['blocks'];
                $blockIndex = $nestedRemoval['removedIndex'];
                $blockCount = $nestedRemoval['siblingCount'];
            } else {
                if ($blockIndex < 0 || $blockIndex >= \count($blocks)) {
                    return [
                        'error' => \sprintf(
                            'Block index %d out of range. %s has %d block(s) (valid indices: 0-%d).',
                            $blockIndex,
                            \ucfirst($resourceKey),
                            \count($blocks),
                            \max(0, \count($blocks) - 1),
                        ),
                    ];
                }

                \array_splice($blocks, $blockIndex, 1);
                $blockCount = \count($blocks);
            }

            $data = [
                'locale' => $locale,
                'template' => $currentData['template'] ?? null,
                'title' => $currentData['title'] ?? null,
                $blockProperty => $blocks,
            ];

            // Ensure all array keys are strings (Sulu's MetadataResolver requires string keys)
            $data = $this->stringifyKeys($data);

            $message = $this->contentTypeResolver->createModifyMessage($resourceKey, $uuid, $data);

            $this->handle(new Envelope($message, [new EnableFlushStamp()]));

            return [
                'success' => true,
                'uuid' => $uuid,
                'resourceKey' => $resourceKey,
                'removedIndex' => $blockIndex,
                'blockCount' => $blockCount,
            ];
        } catch (PermissionDeniedException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            return [
                'error' => \sprintf('Failed to remove block from %s %s: %s', $resourceKey, $uuid, ThrowableMessage::of($e)),
                'hint' => 'Use sulu_block_list to see current blocks and their indices and _id values before removing.',
            ];
        }
    }
}
