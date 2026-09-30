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

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Sulu\Mcp\Infrastructure\Agent\ContentTypeToolFactory;
use Sulu\Mcp\UserInterface\Agent\Tool\ContentSearchTool;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Component\DependencyInjection\ContainerInterface;

/*
 * Imported by SuluMcpBundle only when symfony/ai-agent is installed: these classes carry its
 * #[AsTool] attribute, which does not resolve otherwise. Tagged manually (mirroring
 * SuluProductBundle's registerAiTool()) rather than relying on AiBundle's own autoconfiguration,
 * since a project can have symfony/ai-agent installed without registering that bundle.
 */
return static function(ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure();

    // Inert without AiBundle: there is no ai.tool_factory to decorate.
    $services->set(ContentTypeToolFactory::class)
        ->decorate('ai.tool_factory', null, 0, ContainerInterface::IGNORE_ON_INVALID_REFERENCE)
        ->arg('$inner', service('.inner'));

    foreach ([ContentSearchTool::class] as $toolClass) {
        $attribute = (new \ReflectionClass($toolClass))->getAttributes(AsTool::class)[0]->newInstance();

        $services->set($toolClass)
            ->tag('ai.tool', [
                'name' => $attribute->name,
                'description' => $attribute->description,
                'method' => $attribute->method,
            ]);
    }
};
