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

use Mcp\Capability\Discovery\DiscoveryState;
use Mcp\Capability\Registry\PromptReference;
use Mcp\Capability\Registry\ResourceReference;
use Mcp\Capability\Registry\ResourceTemplateReference;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Capability\RegistryInterface;
use Mcp\Exception\InvalidCursorException;
use Mcp\Schema\Page;
use Mcp\Schema\Prompt;
use Mcp\Schema\Resource;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Tool;
use Sulu\Mcp\Application\Security\ToolVisibilityResolver;

/**
 * Hides tools disabled via `dangerous_tools.*`, and (in `getTools()`) tools the
 * current user's role does not grant. Needed because Mcp also registers tools by
 * runtime attribute discovery, which re-adds anything DangerousToolsPass pruned
 * from the service locator -- those then fail with ArgumentCountError on call.
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

    public function registerTool(Tool $tool, callable|array|string $handler, bool $isManual = false): void
    {
        if (\in_array($tool->name, $this->disabledToolNames, true)) {
            return;
        }

        $this->inner->registerTool($tool, $handler, $isManual);
    }

    public function registerResource(Resource $resource, callable|array|string $handler, bool $isManual = false): void
    {
        $this->inner->registerResource($resource, $handler, $isManual);
    }

    public function registerResourceTemplate(
        ResourceTemplate $template,
        callable|array|string $handler,
        array $completionProviders = [],
        bool $isManual = false,
    ): void {
        $this->inner->registerResourceTemplate($template, $handler, $completionProviders, $isManual);
    }

    public function registerPrompt(
        Prompt $prompt,
        callable|array|string $handler,
        array $completionProviders = [],
        bool $isManual = false,
    ): void {
        $this->inner->registerPrompt($prompt, $handler, $completionProviders, $isManual);
    }

    public function clear(): void
    {
        $this->inner->clear();
    }

    public function getDiscoveryState(): DiscoveryState
    {
        return $this->inner->getDiscoveryState();
    }

    public function setDiscoveryState(DiscoveryState $state): void
    {
        if ([] === $this->disabledToolNames) {
            $this->inner->setDiscoveryState($state);

            return;
        }

        /** @var array<string, ToolReference> $tools */
        $tools = $state->getTools();
        foreach ($this->disabledToolNames as $disabled) {
            unset($tools[$disabled]);
        }

        $this->inner->setDiscoveryState(new DiscoveryState(
            tools: $tools,
            resources: $state->getResources(),
            prompts: $state->getPrompts(),
            resourceTemplates: $state->getResourceTemplates(),
        ));
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
            if ($this->visibilityResolver->isVisible((string) $name)) {
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
            $decodedCursor = base64_decode($cursor, true);

            if (false === $decodedCursor || !is_numeric($decodedCursor)) {
                throw new InvalidCursorException($cursor);
            }

            $offset = (int) $decodedCursor;

            if ($offset < 0 || $offset > \count($items)) {
                throw new InvalidCursorException($cursor);
            }
        }

        return array_values(\array_slice($items, $offset, $limit));
    }

    /**
     * Replicates {@see \Mcp\Capability\Registry::calculateNextCursor()} over the
     * filtered item count.
     */
    private function calculateNextCursor(int $totalItems, ?string $currentCursor, int $limit): ?string
    {
        $currentOffset = 0;

        if (null !== $currentCursor) {
            $decodedCursor = base64_decode($currentCursor, true);
            if (false !== $decodedCursor && is_numeric($decodedCursor)) {
                $currentOffset = (int) $decodedCursor;
            }
        }

        $nextOffset = $currentOffset + $limit;

        if ($nextOffset < $totalItems) {
            return base64_encode((string) $nextOffset);
        }

        return null;
    }
}
