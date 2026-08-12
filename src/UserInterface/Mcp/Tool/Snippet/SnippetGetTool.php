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
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Application\Content\ContentNormalizerTrait;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;
use Sulu\Snippet\Domain\Exception\SnippetNotFoundException;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;

/**
 * @internal
 */
class SnippetGetTool
{
    use ContentNormalizerTrait;

    public function __construct(
        private readonly SnippetRepositoryInterface $snippetRepository,
        private readonly ContentManagerInterface $contentManager,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'sulu_snippet_get',
        description: 'Get a snippet by UUID. Snippets are reusable content blocks (e.g., contact info, footer content) shared across pages. Returns full content data. Snippets are global — not scoped to a webspace.',
    )]
    #[RequiresPermission(requirements: [
        new PermissionRequirement('sulu.snippet.snippets', PermissionTypes::VIEW),
    ])]
    public function getSnippet(string $locale, string $uuid): array
    {
        try {
            $snippet = $this->snippetRepository->getOneBy(
                [
                    'uuid' => $uuid,
                    'locale' => $locale,
                    'stage' => DimensionContentInterface::STAGE_DRAFT,
                ],
                [
                    SnippetRepositoryInterface::GROUP_SELECT_SNIPPET_ADMIN => true,
                ],
            );

            $dimensionContent = $this->contentManager->resolve($snippet, [
                'locale' => $locale,
                'stage' => DimensionContentInterface::STAGE_DRAFT,
            ]);

            $normalized = $this->contentManager->normalize($dimensionContent);

            return [
                'uuid' => $snippet->getUuid(),
                'locale' => $locale,
                'data' => $this->compactContent($normalized, $this->detectBlockProperties($normalized)),
            ];
        } catch (SnippetNotFoundException) {
            return [
                'error' => 'Snippet not found: ' . $uuid,
                'hint' => 'Verify the UUID and locale. Use sulu_snippet_list to find snippets.',
            ];
        }
    }
}
