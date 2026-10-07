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

namespace Sulu\Mcp\UserInterface\Mcp\Tool\AdminLink;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Sulu\Bundle\AdminBundle\Admin\View\ResourceViewUrlGeneratorInterface;
use Sulu\Bundle\AdminBundle\Exception\ResourceViewNotFoundException;
use Sulu\Bundle\AdminBundle\Exception\ViewNotFoundException;
use Sulu\Bundle\AdminBundle\Exception\ViewParameterNotFoundException;
use Sulu\Bundle\CategoryBundle\Admin\CategoryAdmin;
use Sulu\Bundle\ContactBundle\Admin\ContactAdmin;
use Sulu\Bundle\MediaBundle\Admin\MediaAdmin;
use Sulu\Bundle\TagBundle\Admin\TagAdmin;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Application\Content\ContentTypeExtensionRegistry;
use Sulu\Mcp\Application\Content\ContentTypeSchemaExpander;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\Infrastructure\Sulu\Security\SnippetSecurityContextResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @internal
 */
class AdminLinkGenerateTool
{
    /**
     * @param array<string, array{views?: array<string, string>, security_context?: string, security_class?: class-string}> $resources the `sulu_admin.resources` parameter
     */
    public function __construct(
        private readonly ResourceViewUrlGeneratorInterface $resourceViewUrlGenerator,
        private readonly ToolPermissionCheckerInterface $permissionChecker,
        private readonly ContentTypeExtensionRegistry $extensionRegistry,
        private readonly array $resources,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'sulu_admin_link_generate',
        title: 'Generate Admin Link',
        description: 'Generate the absolute URL of a resource\'s edit view in the Sulu admin. Use this tool for every admin link. Never build an admin URL yourself: the route differs per resource and a guessed URL opens nothing. Pass the `resourceKey` and the id of the resource, which is the uuid or numeric id other tools return: "pages" takes a page uuid, "articles" an article uuid, "snippets" a snippet uuid, "products" a product uuid, "media" a media id, "tags" and "categories" their ids, "contacts" and "accounts" their ids. Resource keys with an admin edit view: {adminLinkResourceKeys}. The `locale` is required. Pages also need `webspace`. Returns `admin_url`. Returns an error when the resource key has no admin view or you may not view the resource. Read-only. It does not check that the resource exists.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    #[RequiresPermission(
        requirements: [new PermissionRequirement('#context#', PermissionTypes::VIEW)],
        objectResolved: true,
        discoveryContexts: [
            WebspacePermissionResolver::ANY_WEBSPACE_CONTEXT,
            ArticleSecurityContextResolver::ANY_ARTICLE_GROUP_CONTEXT,
            SnippetSecurityContextResolver::ANY_SNIPPET_GROUP_CONTEXT,
            ContentTypeExtensionRegistry::ANY_EXTENSION_CONTEXT,
            MediaAdmin::SECURITY_CONTEXT,
            TagAdmin::SECURITY_CONTEXT,
            CategoryAdmin::SECURITY_CONTEXT,
            ContactAdmin::CONTACT_SECURITY_CONTEXT,
            ContactAdmin::ACCOUNT_SECURITY_CONTEXT,
        ],
    )]
    public function generateAdminLink(
        #[Schema(description: 'The resourceKey of the resource: {adminLinkResourceKeys}.', enum: [ContentTypeSchemaExpander::ADMIN_LINK_RESOURCE_KEYS])]
        string $resourceKey,
        string $resourceId,
        string $locale,
        #[Schema(description: 'Webspace key. Required for pages, ignored by most other resources.')]
        ?string $webspace = null,
    ): array {
        if ('' === $resourceKey || '' === $resourceId || '' === $locale) {
            return [
                'error' => 'The parameters "resourceKey", "resourceId" and "locale" are required and must not be empty.',
                'hint' => 'Pass the resource key, the id of the resource and a locale such as "en".',
            ];
        }

        $webspace = '' === $webspace ? null : $webspace;

        if (!isset($this->resources[$resourceKey]['views']['detail'])) {
            return [
                'error' => \sprintf('No admin view exists for resource key "%s".', $resourceKey),
                'hint' => \sprintf('Use one of: %s.', \implode(', ', $this->detailResourceKeys()) ?: 'none'),
            ];
        }

        $context = $this->securityContext($resourceKey, $webspace);
        if (false === $context) {
            return [
                'error' => \sprintf('Resource key "%s" needs the "webspace" parameter.', $resourceKey),
                'hint' => 'Pass the webspace key of the resource. Use sulu_ping to list the webspaces.',
            ];
        }

        try {
            $this->assertMayView($resourceKey, $resourceId, $locale, $context);
        } catch (PermissionDeniedException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        }

        $viewParameters = ['id' => $resourceId, 'locale' => $locale];
        if (null !== $webspace) {
            $viewParameters['webspace'] = $webspace;
        }

        try {
            $url = $this->resourceViewUrlGenerator->generate(
                $resourceKey,
                'detail',
                $viewParameters,
                UrlGeneratorInterface::ABSOLUTE_URL,
            );
        } catch (ResourceViewNotFoundException) {
            return [
                'error' => \sprintf('No admin view exists for resource key "%s".', $resourceKey),
                'hint' => \sprintf('Use one of: %s.', \implode(', ', $this->detailResourceKeys()) ?: 'none'),
            ];
        } catch (ViewNotFoundException) {
            return [
                'error' => \sprintf('The admin edit view of resource key "%s" is not available.', $resourceKey),
                'hint' => 'The view is not registered or the current user may not open it.',
            ];
        } catch (ViewParameterNotFoundException $e) {
            return [
                'error' => \sprintf('The admin view of "%s" needs the parameter "%s".', $resourceKey, $e->getParameter()),
                'hint' => 'webspace' === $e->getParameter()
                    ? 'Pass the "webspace" parameter. Use sulu_ping to list the webspaces.'
                    : 'Check the resource key and id.',
            ];
        }

        return [
            'success' => true,
            'admin_url' => $url,
            'resourceKey' => $resourceKey,
            'resourceId' => $resourceId,
            'locale' => $locale,
        ];
    }

    /**
     * The declared view context of the resource, with a webspace placeholder filled. Null when
     * the resource declares none, false when it needs a webspace that was not given.
     */
    private function securityContext(string $resourceKey, ?string $webspace): string|false|null
    {
        $context = $this->resources[$resourceKey]['security_context'] ?? null;
        if (!\is_string($context)) {
            return null;
        }

        if (!\str_contains($context, '#webspace#')) {
            return $context;
        }

        return null === $webspace ? false : \str_replace('#webspace#', $webspace, $context);
    }

    /**
     * Resources without a declared context have no per-resource permission to check, so a
     * content type extension's view contexts decide. Resources with neither (tags, categories,
     * contacts) are gated at the tool level by `discoveryContexts`.
     *
     * @throws PermissionDeniedException
     */
    private function assertMayView(string $resourceKey, string $resourceId, string $locale, ?string $context): void
    {
        if (null !== $context) {
            $securityClass = $this->resources[$resourceKey]['security_class'] ?? null;
            $this->permissionChecker->check(
                $context,
                PermissionTypes::VIEW,
                $locale,
                $securityClass,
                null !== $securityClass ? $resourceId : null,
            );

            return;
        }

        $contexts = $this->extensionRegistry->find($resourceKey)?->getViewSecurityContexts() ?? [];
        if ([] === $contexts) {
            return;
        }

        foreach ($contexts as $candidate) {
            if ($this->permissionChecker->has($candidate, PermissionTypes::VIEW, $locale)) {
                return;
            }
        }

        throw new PermissionDeniedException(\implode(', ', $contexts), PermissionTypes::VIEW, $locale);
    }

    /**
     * @return list<string>
     */
    private function detailResourceKeys(): array
    {
        $keys = [];
        foreach ($this->resources as $resourceKey => $resource) {
            if (isset($resource['views']['detail'])) {
                $keys[] = (string) $resourceKey;
            }
        }
        \sort($keys);

        return $keys;
    }
}
