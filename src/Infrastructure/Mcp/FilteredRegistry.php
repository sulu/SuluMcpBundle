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

use Mcp\Capability\Registry\PromptReference;
use Mcp\Capability\Registry\ResourceReference;
use Mcp\Capability\Registry\ResourceTemplateReference;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Capability\RegistryInterface;
use Mcp\Exception\InvalidCursorException;
use Mcp\Schema\Page;
use Mcp\Schema\Prompt;
use Mcp\Schema\ResourceDefinition;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Tool;
use Sulu\Mcp\Application\Security\ToolVisibilityResolver;

/**
 * Hides tools disabled via `dangerous_tools.*`, and (in `getTools()`) tools the
 * current user's role does not grant.
 *
 * `getTool()` stays unfiltered by permission, so calling a hidden tool yields a
 * permission denial rather than a fabricated "not found".
 *
 * @internal
 */
final readonly class FilteredRegistry implements RegistryInterface
{
    /**
     * @param list<string> $disabledToolNames tool names that must not appear in the registry
     */
    public function __construct(
        private RegistryInterface $inner,
        private ToolVisibilityResolver $visibilityResolver,
        private array $disabledToolNames = [],
    ) {
    }

    public function registerTool(Tool $tool, callable|array|string $handler): ToolReference
    {
        if (\in_array($tool->name, $this->disabledToolNames, true)) {
            // Detached: the reference is handed back to satisfy the contract but
            // never stored, so the tool stays unlistable and uncallable. Only
            // DiscoveryLoader inspects the return value, and it is not in use here.
            return new ToolReference($tool, $handler);
        }

        return $this->inner->registerTool($tool, $handler);
    }

    public function registerResource(ResourceDefinition $resource, callable|array|string $handler): ResourceReference
    {
        return $this->inner->registerResource($resource, $handler);
    }

    public function registerResourceTemplate(
        ResourceTemplate $template,
        callable|array|string $handler,
        array $completionProviders = [],
    ): ResourceTemplateReference {
        return $this->inner->registerResourceTemplate($template, $handler, $completionProviders);
    }

    public function registerPrompt(
        Prompt $prompt,
        callable|array|string $handler,
        array $completionProviders = [],
    ): PromptReference {
        return $this->inner->registerPrompt($prompt, $handler, $completionProviders);
    }

    public function unregisterTool(string $name): void
    {
        $this->inner->unregisterTool($name);
    }

    public function unregisterResource(string $uri): void
    {
        $this->inner->unregisterResource($uri);
    }

    public function unregisterResourceTemplate(string $uriTemplate): void
    {
        $this->inner->unregisterResourceTemplate($uriTemplate);
    }

    public function unregisterPrompt(string $name): void
    {
        $this->inner->unregisterPrompt($name);
    }

    public function hasTool(string $name): bool
    {
        return $this->inner->hasTool($name);
    }

    public function hasResource(string $uri): bool
    {
        return $this->inner->hasResource($uri);
    }

    public function hasResourceTemplate(string $uriTemplate): bool
    {
        return $this->inner->hasResourceTemplate($uriTemplate);
    }

    public function hasPrompt(string $name): bool
    {
        return $this->inner->hasPrompt($name);
    }

    public function hasTools(): bool
    {
        return $this->inner->hasTools();
    }

    public function getTools(?int $limit = null, ?string $cursor = null): Page
    {
        /** @var array<string, Tool> $tools */
        $tools = [];
        foreach ($this->inner->getTools(null, null)->references as $name => $tool) {
            // getTools() only ever populates $references with Tool instances here
            if ($tool instanceof Tool && $this->visibilityResolver->isVisible((string) $name)) {
                $tools[$name] = $tool;
            }
        }

        if (null === $limit) {
            return new Page($tools, null);
        }

        $paginatedTools = $this->paginateResults($tools, $limit, $cursor);
        $nextCursor = $this->calculateNextCursor(\count($tools), $cursor, $limit);

        return new Page($paginatedTools, $nextCursor);
    }

    public function getTool(string $name): ToolReference
    {
        return $this->inner->getTool($name);
    }

    public function hasResources(): bool
    {
        return $this->inner->hasResources();
    }

    public function getResources(?int $limit = null, ?string $cursor = null): Page
    {
        return $this->inner->getResources($limit, $cursor);
    }

    public function getResource(string $uri, bool $includeTemplates = true): ResourceReference|ResourceTemplateReference
    {
        return $this->inner->getResource($uri, $includeTemplates);
    }

    public function hasResourceTemplates(): bool
    {
        return $this->inner->hasResourceTemplates();
    }

    public function getResourceTemplates(?int $limit = null, ?string $cursor = null): Page
    {
        return $this->inner->getResourceTemplates($limit, $cursor);
    }

    public function getResourceTemplate(string $uriTemplate): ResourceTemplateReference
    {
        return $this->inner->getResourceTemplate($uriTemplate);
    }

    public function hasPrompts(): bool
    {
        return $this->inner->hasPrompts();
    }

    public function getPrompts(?int $limit = null, ?string $cursor = null): Page
    {
        return $this->inner->getPrompts($limit, $cursor);
    }

    public function getPrompt(string $name): PromptReference
    {
        return $this->inner->getPrompt($name);
    }

    /**
     * Replicates {@see \Mcp\Capability\Registry::paginateResults()}, since the
     * inner registry only paginates its own unfiltered set.
     *
     * @param array<int|string, Tool> $items
     *
     * @return array<int|string, Tool>
     *
     * @throws InvalidCursorException When cursor is invalid (MCP error code -32602)
     */
    private function paginateResults(array $items, int $limit, ?string $cursor = null): array
    {
        $offset = 0;
        if (null !== $cursor) {
            $decodedCursor = \base64_decode($cursor, true);

            if (false === $decodedCursor || !\is_numeric($decodedCursor)) {
                throw new InvalidCursorException($cursor);
            }

            $offset = (int) $decodedCursor;

            if ($offset < 0 || $offset > \count($items)) {
                throw new InvalidCursorException($cursor);
            }
        }

        return \array_values(\array_slice($items, $offset, $limit));
    }

    /**
     * Replicates {@see \Mcp\Capability\Registry::calculateNextCursor()} over the
     * filtered item count.
     */
    private function calculateNextCursor(int $totalItems, ?string $currentCursor, int $limit): ?string
    {
        $currentOffset = 0;

        if (null !== $currentCursor) {
            $decodedCursor = \base64_decode($currentCursor, true);
            if (false !== $decodedCursor && \is_numeric($decodedCursor)) {
                $currentOffset = (int) $decodedCursor;
            }
        }

        $nextOffset = $currentOffset + $limit;

        if ($nextOffset < $totalItems) {
            return \base64_encode((string) $nextOffset);
        }

        return null;
    }
}
