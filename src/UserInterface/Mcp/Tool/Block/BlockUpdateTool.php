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
        description: 'Update a single block by its _id. Pass blockData as a flat object mapping the block-type\'s template field names to new values, e.g. blockData={"title": "New heading"}. Only the keys you pass are changed; other fields are preserved. Unknown keys are rejected against the block type\'s schema; the internal {name, value} storage shape is rejected too. Use sulu_page_get, sulu_article_get, or sulu_snippet_get to find block _id values (returned in block summaries), and sulu_block_list to read full content before updating. The entity must be re-published after updating blocks.',
    )]
    #[RequiresPermission(
        requirements: [new PermissionRequirement('#context#', PermissionTypes::EDIT)],
        objectResolved: true,
        discoveryContexts: ['sulu.snippet.snippets', ArticleSecurityContextResolver::ANY_ARTICLE_GROUP_CONTEXT, WebspacePermissionResolver::ANY_WEBSPACE_CONTEXT],
    )]
    public function updateBlock(
        string $type,
        string $uuid,
        string $locale,
        string $blockId,
        #[Schema(type: 'object', description: 'Changed block field values as a flat object, e.g. {"content": "<p>Updated</p>"}', additionalProperties: true)]
        array $blockData,
    ): array {
        if (!$this->contentTypeResolver->supports($type)) {
            return ['error' => \sprintf('Unsupported content type "%s". Use "page", "article" or "snippet".', $type)];
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

            // Find the block by _id anywhere in the block tree (including nested blocks)
            $found = $this->findBlockPath($currentData, $blockId);

            if (null === $found) {
                return [
                    'error' => \sprintf('Block with _id "%s" not found in %s %s.', $blockId, $type, $uuid),
                    'hint' => 'Use sulu_page_get, sulu_article_get, or sulu_snippet_get to see block summaries with _id values.',
                ];
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
            if (null !== $blockType && $validationError = $this->blockDataValidator->validate($type, $templateKey, $blockType, $blockData)) {
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

            return [
                'success' => true,
                'uuid' => $uuid,
                'blockId' => $blockId,
                'blockProperty' => $foundProperty,
                'blockPath' => $foundIndices,
            ];
        } catch (PermissionDeniedException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            return [
                'error' => \sprintf('Failed to update block "%s" in %s %s: %s', $blockId, $type, $uuid, $e->getMessage()),
                'hint' => 'Verify the UUID exists and the block _id is correct (use sulu_page_get, sulu_article_get, or sulu_snippet_get to check).',
            ];
        }
    }
}
