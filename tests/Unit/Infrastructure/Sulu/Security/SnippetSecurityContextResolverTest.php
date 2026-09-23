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

namespace Sulu\Mcp\Tests\Unit\Infrastructure\Sulu\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormGroup;
use Sulu\Mcp\Infrastructure\Sulu\Security\SnippetSecurityContextResolver;
use Sulu\Mcp\Tests\Application\TestBundle\Metadata\TestGroupProvider;

#[CoversClass(SnippetSecurityContextResolver::class)]
final class SnippetSecurityContextResolverTest extends TestCase
{
    public function testDefaultGroupYieldsBaseContext(): void
    {
        $groupProvider = new TestGroupProvider([
            (new FormGroup('default', 'Default'))->withTemplate('default'),
        ]);
        $resolver = new SnippetSecurityContextResolver($groupProvider, true);

        self::assertSame('sulu.snippet.snippets', $resolver->forTemplateKey('default'));
    }

    public function testNamedGroupYieldsSuffixedContext(): void
    {
        $groupProvider = new TestGroupProvider([
            (new FormGroup('default', 'Default'))->withTemplate('default'),
            (new FormGroup('blog', 'Blog'))->withTemplate('blog_snippet'),
        ]);
        $resolver = new SnippetSecurityContextResolver($groupProvider, true);

        self::assertSame('sulu.snippet.snippets_blog', $resolver->forTemplateKey('blog_snippet'));
    }

    public function testUnmatchedTemplateInMultiGroupInstallYieldsUnresolvableContext(): void
    {
        $groupProvider = new TestGroupProvider([
            (new FormGroup('default', 'Default'))->withTemplate('default'),
            (new FormGroup('blog', 'Blog'))->withTemplate('blog_snippet'),
        ]);
        $resolver = new SnippetSecurityContextResolver($groupProvider, true);

        self::assertSame('', $resolver->forTemplateKey('orphaned_template'));
    }

    public function testCandidatesYieldsOnlyBaseContextForSingleGroup(): void
    {
        $groupProvider = new TestGroupProvider([
            (new FormGroup('default', 'Default'))->withTemplate('default'),
        ]);
        $resolver = new SnippetSecurityContextResolver($groupProvider, true);

        self::assertSame(['sulu.snippet.snippets'], $resolver->candidates());
    }

    public function testCandidatesYieldsBaseAndPerGroupContexts(): void
    {
        $groupProvider = new TestGroupProvider([
            (new FormGroup('default', 'Default'))->withTemplate('default'),
            (new FormGroup('blog', 'Blog'))->withTemplate('blog_snippet'),
        ]);
        $resolver = new SnippetSecurityContextResolver($groupProvider, true);

        self::assertSame(['sulu.snippet.snippets', 'sulu.snippet.snippets_blog'], $resolver->candidates());
    }

    /**
     * Sulu 3.0 parses `<group>` on snippet templates but registers no group context,
     * so a grouped template must not resolve to a context no role can be granted.
     */
    public function testWithoutCoreGroupContextsEveryTemplateYieldsBaseContext(): void
    {
        $groupProvider = new TestGroupProvider([
            (new FormGroup('default', 'Default'))->withTemplate('default'),
            (new FormGroup('blog', 'Blog'))->withTemplate('blog_snippet'),
        ]);
        $resolver = new SnippetSecurityContextResolver($groupProvider, false);

        self::assertSame('sulu.snippet.snippets', $resolver->forTemplateKey('blog_snippet'));
        self::assertSame('sulu.snippet.snippets', $resolver->forTemplateKey('orphaned_template'));
        self::assertSame('sulu.snippet.snippets', $resolver->resolve(['template' => 'blog_snippet']));
        self::assertSame(['sulu.snippet.snippets'], $resolver->candidates());
    }
}
