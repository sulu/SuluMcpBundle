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

namespace Sulu\Mcp\UserInterface\Mcp\Tool\Snippet;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Sulu\Bundle\AdminBundle\Application\BlockIdGenerator\BlockIdGeneratorInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Application\AdminLink\AdminLinkGeneratorInterface;
use Sulu\Mcp\Application\Content\BlockDataNormalizerTrait;
use Sulu\Mcp\Application\Content\BlockDataValidator;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Snippet\Application\Message\CreateSnippetMessage;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @internal
 */
class SnippetCreateTool
{
    use HandleTrait;
    use BlockDataNormalizerTrait;

    public function __construct(
        MessageBusInterface $messageBus,
        private readonly ContentManagerInterface $contentManager,
        private readonly BlockDataValidator $blockDataValidator,
        private readonly BlockIdGeneratorInterface $blockIdGenerator,
        private readonly AdminLinkGeneratorInterface $adminLinkGenerator,
    ) {
        $this->messageBus = $messageBus;
    }

    /**
     * @param array<string, mixed>|null $content
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'sulu_snippet_create',
        title: 'Create Snippet',
        description: 'Create a new snippet (draft). Snippets are reusable global content blocks (e.g. contact info, footer text) — they are not scoped to a webspace. Workflow: 1) Call sulu_get_context to discover snippet templates and their fields. 2) Choose a template key and pass its field values in "content" as a flat object: content={"body": "<p>HTML here</p>"}. Content may also include a full "blocks" tree (nested blocks allowed), e.g. content={"blocks": [{"type": "text", "content": "<p>…</p>"}]} — block _ids are assigned automatically and unknown block fields are rejected before saving. The "title" is a separate parameter — do not repeat it in content. The snippet is created as a draft — call sulu_content_publish (type: snippet) afterward to make it live.',
    )]
    #[RequiresPermission(requirements: [
        new PermissionRequirement('sulu.snippet.snippets', PermissionTypes::EDIT),
        new PermissionRequirement('sulu.snippet.snippets', PermissionTypes::ADD),
    ])]
    public function createSnippet(
        string $locale,
        string $template,
        string $title,
        #[Schema(type: 'object', description: 'Template field values as key-value pairs, e.g. {"body": "<p>HTML content</p>"}', additionalProperties: true)]
        ?array $content = null,
    ): array {
        try {
            $normalizedContent = null !== $content ? self::normalizeContent($content) : [];

            if ($validationError = $this->blockDataValidator->validateContentTree($normalizedContent, 'snippet', $template)) {
                return $validationError;
            }

            $normalizedContent = $this->assignBlockIds($normalizedContent, $this->blockIdGenerator);

            $data = \array_merge($normalizedContent, [
                'locale' => $locale,
                'template' => $template,
                'title' => $title,
            ]);

            $data = $this->stringifyKeys($data);

            $message = new CreateSnippetMessage($data);

            /** @var SnippetInterface $snippet */
            $snippet = $this->handle(new Envelope($message, [new EnableFlushStamp()]));

            $dimensionContent = $this->contentManager->resolve($snippet, [
                'locale' => $locale,
                'stage' => DimensionContentInterface::STAGE_DRAFT,
            ]);
            $normalized = $this->contentManager->normalize($dimensionContent);

            $result = [
                'success' => true,
                'uuid' => $snippet->getUuid(),
                'data' => $normalized,
            ];

            $adminUrl = $this->adminLinkGenerator->generate('snippet', [
                'locale' => $locale,
                'uuid' => $snippet->getUuid(),
            ]);
            if (null !== $adminUrl) {
                $result['admin_url'] = $adminUrl;
            }

            return $result;
        } catch (\Throwable $e) {
            return [
                'error' => \sprintf('Failed to create snippet "%s": %s', $title, $e->getMessage()),
                'hint' => 'Verify the template key exists (use sulu_get_context) and content fields match the template schema.',
            ];
        }
    }
}
