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
use Sulu\Bundle\MediaBundle\Entity\Collection;
use Sulu\Bundle\MediaBundle\Entity\MediaInterface;
use Sulu\Bundle\MediaBundle\Media\Exception\MediaNotFoundException;
use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;
use Sulu\Bundle\TagBundle\Admin\TagAdmin;
use Sulu\Component\Media\SystemCollections\SystemCollectionManagerInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Application\AdminLink\AdminLinkResourceResolverInterface;
use Sulu\Mcp\Application\Content\ContentTypeExtensionRegistry;
use Sulu\Mcp\Application\Content\ContentTypeSchemaExpander;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\Infrastructure\Sulu\Security\SnippetSecurityContextResolver;
use Sulu\Page\Domain\Exception\PageNotFoundException;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @internal
 */
class AdminLinkGenerateTool
{
    /**
     * @param array<string, array{views?: array<string, string>, security_context?: string, security_class?: class-string}> $resources the `sulu_admin.resources` parameter
     * @param iterable<AdminLinkResourceResolverInterface> $resolvers
     */
    public function __construct(
        private readonly ResourceViewUrlGeneratorInterface $resourceViewUrlGenerator,
        private readonly ToolPermissionCheckerInterface $permissionChecker,
        private readonly ContentTypeExtensionRegistry $extensionRegistry,
        private readonly MediaManagerInterface $mediaManager,
        private readonly PageRepositoryInterface $pageRepository,
        private readonly array $resources,
        private readonly iterable $resolvers = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'sulu_admin_link_generate',
        title: 'Generate Admin Link',
        description: 'Generate the absolute URL of a resource\'s edit view in the Sulu admin. Use this tool for every admin link. Never build an admin URL yourself: the route differs per resource and a guessed URL opens nothing. Pass the `resourceKey` and the id of the resource, which is the uuid or numeric id other tools return: "pages" takes a page uuid, "articles" an article uuid, "snippets" a snippet uuid, "products" a product uuid, "media" a media id, "tags" and "categories" their ids, "contacts" and "accounts" their ids. Resource keys with an admin edit view: {adminLinkResourceKeys}. The `locale` is required. Returns `admin_url`. An id that is edited inside another resource, such as a product variant, gets the link of that resource. Returns an error when the resource key has no admin view or you may not view the resource. Read-only. Pages, articles, snippets and media are looked up for the permission check, so a missing one is an error.',
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
        string|int $resourceId,
        string $locale,
        #[Schema(description: 'Optional. Pages use the webspace they are stored in.')]
        ?string $webspace = null,
    ): array {
        $resourceId = (string) $resourceId;

        if ('' === $resourceKey || '' === $resourceId || '' === $locale) {
            return [
                'error' => 'The parameters "resourceKey", "resourceId" and "locale" are required and must not be empty.',
                'hint' => 'Pass the resource key, the id of the resource and a locale such as "en".',
            ];
        }

        $webspace = '' === $webspace ? null : $webspace;

        // Resolvers only know keys that have an admin view.
        if (!\array_key_exists($resourceKey, $this->resources)) {
            return $this->noViewError($resourceKey);
        }

        // A page belongs to the webspace it is stored in, not to the one the caller names.
        if ('pages' === $resourceKey) {
            $webspace = $this->pageWebspace($resourceId, $locale);
            if (!\is_string($webspace)) {
                return $webspace;
            }
        }

        if (null !== $error = $this->authorize($resourceKey, $resourceId, $locale, $webspace)) {
            return $error;
        }

        $pathSuffix = '';
        $requestedKey = $resourceKey;
        $requestedId = $resourceId;
        foreach ($this->resolvers as $resolver) {
            if (null !== $target = $resolver->resolve($resourceKey, $resourceId, $locale)) {
                [$resourceKey, $resourceId, $pathSuffix] = [$target->resourceKey, $target->resourceId, $target->pathSuffix];

                break;
            }
        }

        if (!isset($this->resources[$resourceKey]['views']['detail'])) {
            return $this->noViewError($resourceKey);
        }

        if ($requestedKey !== $resourceKey || $requestedId !== $resourceId) {
            if ('pages' === $resourceKey) {
                $webspace = $this->pageWebspace($resourceId, $locale);
                if (!\is_string($webspace)) {
                    return $webspace;
                }
            }

            if (null !== $error = $this->authorize($resourceKey, $resourceId, $locale, $webspace)) {
                return $error;
            }
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
            return $this->noViewError($resourceKey);
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

        $result = [
            'success' => true,
            'admin_url' => $url . $pathSuffix,
            'resourceKey' => $resourceKey,
            'resourceId' => $resourceId,
            'locale' => $locale,
        ];
        if ($requestedId !== $resourceId) {
            $result['note'] = \sprintf('"%s" has no admin view of its own, so the link opens "%s" of "%s".', $requestedId, $resourceId, $resourceKey);
        }

        return $result;
    }

    /**
     * @return string|array<string, mixed> the webspace key of the page, an error result when it does not exist
     */
    private function pageWebspace(string $uuid, string $locale): string|array
    {
        try {
            return $this->pageRepository->getOneBy(
                [
                    'uuid' => $uuid,
                    'locale' => $locale,
                    'stage' => DimensionContentInterface::STAGE_DRAFT,
                    // The admin opens a page without content in this locale, so it has a link too.
                    'loadGhost' => true,
                ],
                [PageRepositoryInterface::GROUP_SELECT_PAGE_ADMIN => true],
            )->getWebspaceKey();
        } catch (PageNotFoundException) {
            return [
                'error' => 'Page not found: ' . $uuid,
                'hint' => 'Verify the UUID and locale. Use sulu_page_list or sulu_content_search to find pages.',
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function noViewError(string $resourceKey): array
    {
        return [
            'error' => \sprintf('No admin view exists for resource key "%s".', $resourceKey),
            'hint' => \sprintf('Use one of: %s.', \implode(', ', ContentTypeSchemaExpander::detailResourceKeys($this->resources)) ?: 'none'),
        ];
    }

    /**
     * Null when the user may view the resource, an error result when the check cannot run.
     *
     * @return array<string, mixed>|null
     *
     * @throws ToolCallException
     */
    private function authorize(string $resourceKey, string $resourceId, string $locale, ?string $webspace): ?array
    {
        $context = $this->securityContext($resourceKey, $webspace);
        if (false === $context) {
            return [
                'error' => \sprintf('Resource key "%s" needs the "webspace" parameter.', $resourceKey),
                'hint' => 'Pass the webspace key of the resource. Use sulu_ping to list the webspaces.',
            ];
        }

        try {
            return $this->assertMayView($resourceKey, $resourceId, $locale, $context);
        } catch (PermissionDeniedException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        }
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
     * `sulu_admin.resources` only holds the base context of a resource, so the context comes from
     * the entity where it depends on one: the collection of a media and the group of an article or
     * snippet. Resources with neither a declared context nor a content type extension (tags,
     * categories, contacts) have no per-resource permission to check here. The `discoveryContexts`
     * gate lets a user through who may view any one of the listed contexts, so it does not gate
     * these resources. What does is Sulu's view registry. It registers the edit views of tags,
     * contacts, accounts and roles only for users with the EDIT permission, so a user with view
     * only gets "not available" from the generator. Categories register theirs for VIEW, so view
     * is enough for a link there.
     *
     * @return array<string, mixed>|null an error result when the entity was not found
     *
     * @throws PermissionDeniedException
     */
    private function assertMayView(string $resourceKey, string $resourceId, string $locale, ?string $context): ?array
    {
        if ('media' === $resourceKey && null !== $context) {
            return $this->assertMayViewMedia($resourceId, $locale, $context);
        }

        $securityClass = $this->resources[$resourceKey]['security_class'] ?? null;
        if (null !== $context && null !== $securityClass) {
            $this->permissionChecker->check($context, PermissionTypes::VIEW, $locale, $securityClass, $resourceId);

            return null;
        }

        $extension = $this->extensionRegistry->find($resourceKey);
        if (null !== $extension) {
            $aggregate = $extension->loadDraft($resourceId, $locale, true);
            if (null === $aggregate) {
                return [
                    'error' => \sprintf('The resource "%s" of type "%s" was not found.', $resourceId, $resourceKey),
                    'hint' => 'Verify the id and locale.',
                ];
            }

            $this->permissionChecker->check($extension->getSecurity($aggregate, $locale)->context, PermissionTypes::VIEW, $locale);

            return null;
        }

        if (null !== $context) {
            $this->permissionChecker->check($context, PermissionTypes::VIEW, $locale);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws PermissionDeniedException
     */
    private function assertMayViewMedia(string $resourceId, string $locale, string $context): ?array
    {
        // Before any lookup, so a user without media access cannot tell existing ids from missing ones.
        $this->permissionChecker->check($context, PermissionTypes::VIEW, $locale);

        if (!\ctype_digit($resourceId)) {
            return [
                'error' => \sprintf('The media id "%s" is not a number.', $resourceId),
                'hint' => 'Pass the numeric id other media tools return.',
            ];
        }

        try {
            $media = $this->mediaManager->getById((int) $resourceId, $locale);
        } catch (MediaNotFoundException) {
            return [
                'error' => 'Media not found: ' . $resourceId,
                'hint' => 'Verify the id. Use sulu_media_list to find media.',
            ];
        }

        // getEntity() has no return type at all, Media always wraps a MediaInterface
        /** @var MediaInterface $entity */
        $entity = $media->getEntity();
        $collection = $entity->getCollection();
        if (SystemCollectionManagerInterface::COLLECTION_TYPE === $collection->getType()->getKey()) {
            $this->permissionChecker->check('sulu.media.system_collections', PermissionTypes::VIEW, $locale);
        }

        $this->permissionChecker->check($context, PermissionTypes::VIEW, $locale, Collection::class, $collection->getId());

        return null;
    }
}
