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

namespace Sulu\Bundle\McpBundle\Tests\Application;

use League\Bundle\OAuth2ServerBundle\LeagueOAuth2ServerBundle;
use Sulu\Article\Infrastructure\Symfony\HttpKernel\SuluArticleBundle;
use Sulu\Bundle\McpBundle\SuluMcpBundle;
use Sulu\Bundle\McpBundle\Tests\Application\TestBundle\TestBundle;
use Sulu\Bundle\TestBundle\Kernel\SuluTestKernel;
use Sulu\Snippet\Infrastructure\Symfony\HttpKernel\SuluSnippetBundle;
use Symfony\AI\McpBundle\McpBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Loader\LoaderInterface;

/**
 * AppKernel for the bundle's functional tests.
 */
class Kernel extends SuluTestKernel
{
    public function registerBundles(): iterable
    {
        $bundles = [...parent::registerBundles()];

        $bundles[] = new SuluArticleBundle();
        $bundles[] = new SuluSnippetBundle();
        $bundles[] = new McpBundle();
        $bundles[] = new LeagueOAuth2ServerBundle();
        $bundles[] = new SuluMcpBundle();
        $bundles[] = new TestBundle();

        if (self::CONTEXT_WEBSITE === $this->getContext()) {
            $bundles[] = new SecurityBundle();
        }

        return $bundles;
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        parent::registerContainerConfiguration($loader);

        $loader->load(__DIR__.'/config/config_'.$this->getContext().'.yml');
    }
}

// Needed so Symfony's default App\Kernel lookups resolve to this kernel
\class_alias(Kernel::class, 'App\\Kernel');
