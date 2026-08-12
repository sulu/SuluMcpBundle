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

namespace Sulu\Mcp\Infrastructure\Mcp;

use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Sulu\Mcp\Application\Security\ToolContextResolverInterface;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;

/**
 * Preflight-checks the compile-time permission map, then delegates to the SDK's
 * built-in CallToolHandler. Runs first because tagged handlers precede defaults,
 * and returns an isError result rather than throwing.
 *
 * @implements RequestHandlerInterface<CallToolResult>
 *
 * @internal
 */
final readonly class PermissionAwareCallToolHandler implements RequestHandlerInterface
{
    private CallToolHandler $inner;

    /**
     * @param array<string, array{name: string, requirements: list<array{context: string, permission: string}>, contextArgument: ?string, contextResolver: ?string, objectResolved: bool, discoveryContexts: list<string>}> $permissionMap
     * @param array<string, ToolContextResolverInterface> $contextResolvers
     * @param list<string> $allowlist
     */
    public function __construct(
        RegistryInterface $registry,
        ReferenceHandler $referenceHandler,
        private ToolPermissionCheckerInterface $permissionChecker,
        private WebspacePermissionResolver $webspacePermissionResolver,
        private ArticleSecurityContextResolver $articleContextResolver,
        private array $permissionMap,
        private array $contextResolvers,
        private array $allowlist,
        private LoggerInterface $logger = new NullLogger(),
    ) {
        $this->inner = new CallToolHandler($registry, $referenceHandler, $logger);
    }

    public function supports(Request $request): bool
    {
        return $request instanceof CallToolRequest;
    }

    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        \assert($request instanceof CallToolRequest);

        $name = $request->name;
        $arguments = $request->arguments;

        if (\in_array($name, $this->allowlist, true)) {
            return $this->inner->handle($request, $session);
        }

        try {
            $denial = $this->denialReason($name, $arguments);
        } catch (\Throwable $e) {
            $this->logger->error('MCP permission preflight failed; denying', ['tool' => $name, 'exception' => $e]);

            return new Response($request->getId(), CallToolResult::error([new TextContent('Permission denied: authorization check failed.')]));
        }
        if (null !== $denial) {
            return new Response($request->getId(), CallToolResult::error([new TextContent($denial)]));
        }

        return $this->inner->handle($request, $session);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function denialReason(string $name, array $arguments): ?string
    {
        $entry = $this->permissionMap[$name] ?? null;
        if (null === $entry) {
            return \sprintf('Permission denied: tool "%s" has no permission declaration and is not allowlisted.', $name);
        }

        if ([] === $entry['requirements']) {
            return \sprintf('Permission denied: tool "%s" declares no permission requirements.', $name);
        }

        $locale = \is_string($arguments['locale'] ?? null) ? $arguments['locale'] : null;

        if ($entry['objectResolved']) {
            return $this->coarseDenial($entry, $locale);
        }

        foreach ($entry['requirements'] as $requirement) {
            $context = $this->resolveContext($requirement['context'], $entry, $arguments);
            if (!$this->permissionChecker->has($context, $requirement['permission'], $locale)) {
                return $this->message($context, $requirement['permission'], $locale);
            }
        }

        return null;
    }

    /**
     * Coarse check for entity-derived tools; the authoritative object-level check
     * runs in-body. Fail-closed: one candidate must grant every requirement.
     *
     * @param array{requirements: list<array{context: string, permission: string}>, discoveryContexts: list<string>} $entry
     */
    private function coarseDenial(array $entry, ?string $locale): ?string
    {
        foreach ($entry['discoveryContexts'] as $candidate) {
            $allGranted = true;
            foreach ($entry['requirements'] as $requirement) {
                if (!$this->grants($candidate, $requirement['permission'], $locale)) {
                    $allGranted = false;
                    break;
                }
            }
            if ($allGranted) {
                return null;
            }
        }

        return 'Permission denied: no accessible security context grants the required permissions.';
    }

    private function grants(string $candidate, string $permission, ?string $locale): bool
    {
        return match ($candidate) {
            WebspacePermissionResolver::ANY_WEBSPACE_CONTEXT => [] !== $this->webspacePermissionResolver->permittedWebspaceKeys($permission, $locale),
            ArticleSecurityContextResolver::ANY_ARTICLE_GROUP_CONTEXT => $this->anyArticleGroupGrants($permission, $locale),
            default => $this->permissionChecker->has($candidate, $permission, $locale),
        };
    }

    /**
     * Expands the article-group sentinel, so a user holding only a non-default
     * group still passes.
     */
    private function anyArticleGroupGrants(string $permission, ?string $locale): bool
    {
        foreach ($this->articleContextResolver->candidates() as $context) {
            if ($this->permissionChecker->has($context, $permission, $locale)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{contextArgument: ?string, contextResolver: ?string} $entry
     * @param array<string, mixed> $arguments
     */
    private function resolveContext(string $template, array $entry, array $arguments): string
    {
        if (null !== $entry['contextResolver'] && isset($this->contextResolvers[$entry['contextResolver']])) {
            return $this->contextResolvers[$entry['contextResolver']]->resolve($arguments);
        }

        if (null !== $entry['contextArgument']) {
            $value = $arguments[$entry['contextArgument']] ?? '';

            return \str_replace('#context#', \is_string($value) ? $value : '', $template);
        }

        return $template;
    }

    /**
     * Reuses PermissionDeniedException's wording so preflight and in-body denials
     * read identically.
     */
    private function message(string $context, string $permission, ?string $locale): string
    {
        return (new PermissionDeniedException($context, $permission, $locale))->getMessage();
    }
}
