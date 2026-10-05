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

namespace Sulu\Mcp\Tests\Unit\Infrastructure\Sulu\Content;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormGroup;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Infrastructure\Sulu\Content\SnippetContentTypeExtension;
use Sulu\Mcp\Infrastructure\Sulu\Security\SnippetSecurityContextResolver;
use Sulu\Mcp\Tests\Application\TestBundle\Metadata\TestGroupProvider;
use Sulu\Snippet\Domain\Model\Snippet;
use Sulu\Snippet\Domain\Model\SnippetDimensionContent;
use Sulu\Snippet\Domain\Model\SnippetDimensionContentInterface;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;

#[CoversClass(SnippetContentTypeExtension::class)]
final class SnippetContentTypeExtensionTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<EntityRepository<SnippetDimensionContentInterface>> */
    private ObjectProphecy $dimensionRepository;

    private SnippetContentTypeExtension $extension;

    protected function setUp(): void
    {
        $this->dimensionRepository = $this->prophesize(EntityRepository::class);
        $this->extension = $this->extension(true);
    }

    public function testTheLoadedLocalesTemplateDecidesTheGroup(): void
    {
        $snippet = new Snippet('uuid-1');
        $dimensionContent = new SnippetDimensionContent($snippet);
        $dimensionContent->setLocale('en');
        $dimensionContent->setTemplateKey('promo');
        $snippet->addDimensionContent($dimensionContent);

        self::assertSame('sulu.snippet.snippets_marketing', $this->extension->getSecurity($snippet, 'en')->context);
    }

    public function testAGhostTakesTheGroupOfTheLocaleItIsAGhostOf(): void
    {
        $snippet = $this->ghostSnippet();
        $source = new SnippetDimensionContent($snippet);
        $source->setTemplateKey('promo');
        $this->dimensionRepository->findOneBy(['snippet' => $snippet, 'locale' => 'de', 'stage' => DimensionContentInterface::STAGE_DRAFT])->willReturn($source);

        self::assertSame('sulu.snippet.snippets_marketing', $this->extension->getSecurity($snippet, 'en')->context);
    }

    public function testALoadedSourceLocaleIsNotQueried(): void
    {
        $snippet = $this->ghostSnippet();
        $source = new SnippetDimensionContent($snippet);
        $source->setLocale('de');
        $source->setTemplateKey('promo');
        $snippet->addDimensionContent($source);
        $this->dimensionRepository->findOneBy(Argument::cetera())->willReturn(null);

        self::assertSame('sulu.snippet.snippets_marketing', $this->extension->getSecurity($snippet, 'en')->context);
    }

    public function testASnippetWithoutAnySourceContentFailsClosed(): void
    {
        $snippet = $this->ghostSnippet();
        $this->dimensionRepository->findOneBy(Argument::cetera())->willReturn(null);

        self::assertSame('', $this->extension->getSecurity($snippet, 'en')->context);
    }

    public function testEveryGroupContextMakesTheTypeVisible(): void
    {
        self::assertSame(['sulu.snippet.snippets', 'sulu.snippet.snippets_marketing'], $this->extension->getViewSecurityContexts());
    }

    public function testACoreWithoutGroupContextsUsesTheBaseContext(): void
    {
        $extension = $this->extension(false);
        $snippet = new Snippet('uuid-1');
        $dimensionContent = new SnippetDimensionContent($snippet);
        $dimensionContent->setLocale('en');
        $dimensionContent->setTemplateKey('promo');
        $snippet->addDimensionContent($dimensionContent);

        self::assertSame('sulu.snippet.snippets', $extension->getSecurity($snippet, 'en')->context);
        self::assertSame(['sulu.snippet.snippets'], $extension->getViewSecurityContexts());
    }

    private function extension(bool $groupContexts): SnippetContentTypeExtension
    {
        $entityManager = $this->prophesize(EntityManagerInterface::class);
        $entityManager->getRepository(SnippetDimensionContentInterface::class)->willReturn($this->dimensionRepository->reveal());

        return new SnippetContentTypeExtension(
            $this->prophesize(SnippetRepositoryInterface::class)->reveal(),
            new SnippetSecurityContextResolver(new TestGroupProvider([
                (new FormGroup('default', 'Default'))->withTemplate('default'),
                (new FormGroup('marketing', 'Marketing'))->withTemplate('promo'),
            ]), $groupContexts),
            $entityManager->reveal(),
        );
    }

    private function ghostSnippet(): Snippet
    {
        $snippet = new Snippet('uuid-1');
        $unlocalized = new SnippetDimensionContent($snippet);
        $unlocalized->setGhostLocale('de');
        $unlocalized->addAvailableLocale('de');
        $snippet->addDimensionContent($unlocalized);

        return $snippet;
    }
}
