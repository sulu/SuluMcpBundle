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

namespace Sulu\Mcp\Application\Content;

use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;

/**
 * Navigation contexts are declared per webspace in its XML, under <navigation><contexts>;
 * Sulu itself stores undeclared keys without error.
 *
 * @internal
 */
trait NavigationContextTrait
{
    /**
     * @return list<string>
     */
    private function navigationContextKeys(WebspaceManagerInterface $webspaceManager, string $webspaceKey): array
    {
        $navigation = $webspaceManager->findWebspaceByKey($webspaceKey)?->getNavigation();

        if (null === $navigation) {
            return [];
        }

        return \array_values($navigation->getContextKeys());
    }

    /**
     * @param list<string> $navigationContexts
     *
     * @return array{error: string, hint: string}|null the error to return to the caller, or null when every context is declared
     */
    private function validateNavigationContexts(
        WebspaceManagerInterface $webspaceManager,
        string $webspaceKey,
        array $navigationContexts,
    ): ?array {
        $declared = $this->navigationContextKeys($webspaceManager, $webspaceKey);
        $unknown = \array_values(\array_diff($navigationContexts, $declared));

        if ([] === $unknown) {
            return null;
        }

        return [
            'error' => \sprintf(
                'Unknown navigation context(s) for webspace "%s": %s.',
                $webspaceKey,
                \implode(', ', $unknown),
            ),
            'hint' => [] === $declared
                ? 'This webspace declares none, they are added under <navigation><contexts> in its XML.'
                : \sprintf('Declared contexts: %s.', \implode(', ', $declared)),
        ];
    }
}
