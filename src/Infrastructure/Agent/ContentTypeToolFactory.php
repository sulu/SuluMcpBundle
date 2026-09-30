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

namespace Sulu\Mcp\Infrastructure\Agent;

use Sulu\Mcp\Application\Content\ContentTypeSchemaExpander;
use Symfony\AI\Agent\Toolbox\ToolFactoryInterface;
use Symfony\AI\Platform\Contract\JsonSchema\Factory;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Agent-side counterpart of FilteredRegistry's expansion: fills the resourceKey placeholders on
 * every lookup, because the registered keys are only known at runtime.
 *
 * @phpstan-import-type JsonSchema from Factory
 *
 * @internal
 */
final readonly class ContentTypeToolFactory implements ToolFactoryInterface
{
    public function __construct(
        private ToolFactoryInterface $inner,
        private ContentTypeSchemaExpander $schemaExpander,
    ) {
    }

    public function getTool(object|string $reference): iterable
    {
        foreach ($this->inner->getTool($reference) as $tool) {
            yield $this->expand($tool);
        }
    }

    private function expand(Tool $tool): Tool
    {
        $description = $tool->getDescription();
        $parameters = $tool->getParameters();
        $expandedDescription = $this->schemaExpander->expandText($description) ?? $description;
        /** @var JsonSchema|null $expandedParameters */
        $expandedParameters = null === $parameters ? null : $this->schemaExpander->expandInputSchema($parameters);

        if ($expandedDescription === $description && $expandedParameters === $parameters) {
            return $tool;
        }

        return new Tool($tool->getReference(), $tool->getName(), $expandedDescription, $expandedParameters, $tool->getMetadata());
    }
}
