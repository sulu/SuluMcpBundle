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

namespace Sulu\Mcp\UserInterface\Mcp\Tool\Content;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\TemplateInterface;
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
class ContentUnpublishTool
{
    use HandleTrait;

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
        name: 'sulu_content_unpublish',
        title: 'Unpublish Content',
        description: 'Unpublish a live page, article, or snippet — removes it from the website but keeps the draft. Set "type" to "page", "article", "snippet", or "product" when SuluProductBundle is installed. The content is preserved and can be re-published later with sulu_content_publish. Use this to take content offline without deleting it.',
    )]
    #[RequiresPermission(
        requirements: [
            new PermissionRequirement('#context#', PermissionTypes::EDIT),
            new PermissionRequirement('#context#', PermissionTypes::LIVE),
        ],
        objectResolved: true,
        discoveryContexts: ['sulu.snippet.snippets', 'sulu.product.products', ArticleSecurityContextResolver::ANY_ARTICLE_GROUP_CONTEXT, WebspacePermissionResolver::ANY_WEBSPACE_CONTEXT],
    )]
    public function unpublishContent(string $type, string $uuid, string $locale): array
    {
        if (!$this->contentTypeResolver->supports($type)) {
            return [
                'error' => \sprintf('Unsupported content type "%s".', $type),
                'hint' => \sprintf('Supported types: %s.', \implode(', ', $this->contentTypeResolver->supportedTypes())),
            ];
        }

        try {
            $entity = $this->contentTypeResolver->loadForTransition($type, $uuid, $locale);
            if (null === $entity) {
                return [
                    'error' => \sprintf('%s not found: %s', \ucfirst($type), $uuid),
                    'hint' => \sprintf('Verify the UUID exists (use sulu_%s_get).', $type),
                ];
            }

            $dimensionContent = 'article' === $type
                ? $this->contentManager->resolve($entity, ['locale' => $locale, 'stage' => DimensionContentInterface::STAGE_DRAFT]) // @phpstan-ignore argument.type, argument.templateType (upstream generic is invariant; loadForTransition() returns a bare object)
                : null;
            $context = $this->contentSecurityContextResolver->forEntity(
                $type,
                $entity,
                $dimensionContent instanceof TemplateInterface ? $dimensionContent : null,
            );

            $this->permissionChecker->check(
                $context,
                [PermissionTypes::EDIT, PermissionTypes::LIVE],
                $locale,
                'page' === $type ? Page::class : null,
                'page' === $type ? $uuid : null,
            );

            $message = $this->contentTypeResolver->createTransitionMessage($type, $uuid, $locale, 'unpublish');

            $this->handle(new Envelope($message, [new EnableFlushStamp()]));

            return [
                'success' => true,
                'type' => $type,
                'uuid' => $uuid,
                'action' => 'unpublished',
                'locale' => $locale,
            ];
        } catch (PermissionDeniedException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            return [
                'error' => \sprintf('Failed to unpublish %s %s: %s', $type, $uuid, $e->getMessage()),
                'hint' => \sprintf('Verify the content exists and is currently published (use sulu_%s_get to check workflowPlace).', $type),
            ];
        }
    }
}
