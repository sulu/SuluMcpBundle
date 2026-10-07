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

namespace Sulu\Mcp\Tests\Functional;

use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Tool;
use PHPUnit\Framework\Attributes\CoversNothing;
use Sulu\Bundle\AdminBundle\Admin\View\ResourceViewUrlGeneratorInterface;
use Sulu\Mcp\Application\Content\ContentTypeSchemaExpander;
use Sulu\Mcp\Infrastructure\Mcp\FilteredRegistry;

/**
 * sulu_admin_link_generate needs Sulu 3.1's ResourceViewUrlGenerator: present with it,
 * absent without it, and its enum lists the resource keys with a detail view.
 */
#[CoversNothing]
final class AdminLinkToolRegistrationTest extends FunctionalTestCase
{
    public function testToolIsRegisteredExactlyWhenTheSuluServiceExists(): void
    {
        $container = self::getContainer();
        $container->get('mcp.server.sulu');

        /** @var RegistryInterface $registry */
        $registry = $container->get(FilteredRegistry::class . '.inner');
        $tools = $registry->getTools()->references;

        self::assertSame(
            \interface_exists(ResourceViewUrlGeneratorInterface::class),
            isset($tools['sulu_admin_link_generate']),
        );
    }

    public function testResourceKeyEnumNamesResourcesWithoutAContentType(): void
    {
        if (!\interface_exists(ResourceViewUrlGeneratorInterface::class)) {
            self::markTestSkipped('Needs sulu/sulu 3.1 or later.');
        }

        $container = self::getContainer();
        $container->get('mcp.server.sulu');

        /** @var RegistryInterface $registry */
        $registry = $container->get(FilteredRegistry::class . '.inner');
        $tool = $registry->getTools()->references['sulu_admin_link_generate'];

        // FilteredRegistry expands at read time, which a tokenless request hides entirely.
        /** @var ContentTypeSchemaExpander $expander */
        $expander = $container->get(ContentTypeSchemaExpander::class);
        $enum = $expander->expandInputSchema($tool->inputSchema)['properties']['resourceKey']['enum'];
        self::assertContains('pages', $enum);
        self::assertContains('tags', $enum);
        self::assertStringContainsString('"tags"', (string) $expander->expandText($tool->description));
    }

    /**
     * Gemini accepts a type list only as a type and null, so a property such as `string|int` breaks every
     * chat that runs on it.
     */
    public function testNoToolDeclaresAUnionOfTwoRealTypes(): void
    {
        $container = self::getContainer();
        $container->get('mcp.server.sulu');

        /** @var RegistryInterface $registry */
        $registry = $container->get(FilteredRegistry::class . '.inner');

        $unions = [];
        foreach ($registry->getTools()->references as $name => $tool) {
            if (!$tool instanceof Tool) {
                continue;
            }

            $properties = $tool->inputSchema['properties'] ?? [];
            if (!\is_array($properties)) {
                continue;
            }

            foreach ($properties as $property => $schema) {
                $type = \is_array($schema) ? ($schema['type'] ?? null) : null;
                if (\is_array($type) && (2 !== \count($type) || !\in_array('null', $type, true))) {
                    $unions[] = $name . '.' . $property;
                }
            }
        }

        self::assertSame([], $unions);
    }
}
