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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\AdminLink;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Bundle\AdminBundle\Admin\View\ResourceViewUrlGeneratorInterface;
use Sulu\Bundle\AdminBundle\Exception\ResourceViewNotFoundException;
use Sulu\Bundle\AdminBundle\Exception\ViewNotFoundException;
use Sulu\Bundle\AdminBundle\Exception\ViewParameterNotFoundException;
use Sulu\Mcp\Application\Content\ContentTypeExtensionRegistry;
use Sulu\Mcp\Tests\Unit\Fixture\FakeContentTypeExtension;
use Sulu\Mcp\Tests\Unit\Fixture\FakeToolPermissionChecker;
use Sulu\Mcp\UserInterface\Mcp\Tool\AdminLink\AdminLinkGenerateTool;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[CoversClass(AdminLinkGenerateTool::class)]
final class AdminLinkGenerateToolTest extends TestCase
{
    use ProphecyTrait;

    private const RESOURCES = [
        'pages' => [
            'views' => ['detail' => 'sulu_page.page_edit_form'],
            'security_context' => 'sulu.webspaces.#webspace#',
            'security_class' => 'Sulu\\Page\\Domain\\Model\\Page',
        ],
        'tags' => ['views' => ['detail' => 'sulu_tag.edit_form']],
        'widgets' => ['views' => ['detail' => 'app.widget_edit']],
        'pages_versions' => [],
    ];

    /** @var ObjectProphecy<ResourceViewUrlGeneratorInterface> */
    private ObjectProphecy $generator;

    private FakeToolPermissionChecker $permissionChecker;

    protected function setUp(): void
    {
        if (!\interface_exists(ResourceViewUrlGeneratorInterface::class)) {
            self::markTestSkipped('Needs sulu/sulu 3.1 or later.');
        }

        $this->generator = $this->prophesize(ResourceViewUrlGeneratorInterface::class);
        $this->permissionChecker = FakeToolPermissionChecker::grantingAll();
    }

    public function testReturnsTheAdminUrlOfTheDetailView(): void
    {
        $this->generator
            ->generate('tags', 'detail', ['id' => '7', 'locale' => 'de'], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('https://example.org/admin/#/de/tags/7');

        $result = $this->tool()->generateAdminLink('tags', '7', 'de');

        self::assertSame('https://example.org/admin/#/de/tags/7', $result['admin_url']);
        self::assertTrue($result['success']);
    }

    public function testPassesTheWebspaceAndChecksViewOnTheObjectOfAPage(): void
    {
        $this->generator
            ->generate('pages', 'detail', ['id' => 'abc', 'locale' => 'en', 'webspace' => 'sulu'], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('https://example.org/admin/#/sulu/pages/en/abc/details');

        $result = $this->tool()->generateAdminLink('pages', 'abc', 'en', 'sulu');

        self::assertSame('https://example.org/admin/#/sulu/pages/en/abc/details', $result['admin_url']);
        $call = $this->permissionChecker->calls()[0];
        self::assertSame('sulu.webspaces.sulu', $call['context']);
        self::assertSame(['view'], $call['permissions']);
        self::assertSame('abc', $call['objectId']);
    }

    public function testAPageWithoutWebspaceIsRefusedBeforeAnyPermissionCheck(): void
    {
        $result = $this->tool()->generateAdminLink('pages', 'abc', 'en');

        self::assertStringContainsString('"webspace"', $result['error']);
        self::assertSame([], $this->permissionChecker->calls());
        $this->generator->generate(Argument::cetera())->shouldNotHaveBeenCalled();
    }

    public function testUnknownResourceKeyListsTheKeysWithAView(): void
    {
        $result = $this->tool()->generateAdminLink('nope', '1', 'en');

        self::assertSame('No admin view exists for resource key "nope".', $result['error']);
        self::assertSame('Use one of: pages, tags, widgets.', $result['hint']);
    }

    public function testResourceKeyThatTheGeneratorRejectsGivesTheSameError(): void
    {
        $this->generator->generate('tags', 'detail', Argument::cetera())->willThrow(new ResourceViewNotFoundException('tags', 'detail'));

        $result = $this->tool()->generateAdminLink('tags', '1', 'en');

        self::assertStringContainsString('No admin view exists', $result['error']);
    }

    public function testViewThatIsNotAvailableGivesAnError(): void
    {
        $this->generator->generate('tags', 'detail', Argument::cetera())->willThrow(new ViewNotFoundException('sulu_tag.edit_form'));

        $result = $this->tool()->generateAdminLink('tags', '1', 'en');

        self::assertStringContainsString('is not available', $result['error']);
    }

    public function testMissingViewParameterIsNamed(): void
    {
        $this->generator->generate('tags', 'detail', Argument::cetera())->willThrow(new ViewParameterNotFoundException('group', 'view'));

        $result = $this->tool()->generateAdminLink('tags', '1', 'en');

        self::assertStringContainsString('"group"', $result['error']);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function emptyArguments(): iterable
    {
        yield 'locale' => ['tags', '1', ''];
        yield 'resource id' => ['tags', '', 'en'];
        yield 'resource key' => ['', '1', 'en'];
    }

    #[DataProvider('emptyArguments')]
    public function testEmptyArgumentsAreRefused(string $resourceKey, string $resourceId, string $locale): void
    {
        $result = $this->tool()->generateAdminLink($resourceKey, $resourceId, $locale);

        self::assertStringContainsString('required', $result['error']);
        $this->generator->generate(Argument::cetera())->shouldNotHaveBeenCalled();
    }

    public function testMissingViewPermissionOnADeclaredContextIsDenied(): void
    {
        $this->permissionChecker->denyContext('sulu.webspaces.sulu');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('sulu.webspaces.sulu');

        try {
            $this->tool()->generateAdminLink('pages', 'abc', 'en', 'sulu');
        } finally {
            $this->generator->generate(Argument::cetera())->shouldNotHaveBeenCalled();
        }
    }

    public function testMissingViewPermissionOnAContentTypeExtensionIsDenied(): void
    {
        $this->permissionChecker->denyContext('sulu.widget.widgets');

        $this->expectException(ToolCallException::class);

        $this->tool()->generateAdminLink('widgets', '1', 'en');
    }

    public function testViewPermissionOnAContentTypeExtensionLetsTheLinkThrough(): void
    {
        $this->generator->generate('widgets', 'detail', Argument::cetera())->willReturn('https://example.org/admin/#/w');

        $result = $this->tool()->generateAdminLink('widgets', '1', 'en');

        self::assertSame('https://example.org/admin/#/w', $result['admin_url']);
    }

    public function testToolIsReadOnlyAndNamed(): void
    {
        $attribute = (new \ReflectionMethod(AdminLinkGenerateTool::class, 'generateAdminLink'))
            ->getAttributes(McpTool::class)[0]->newInstance();

        self::assertSame('sulu_admin_link_generate', $attribute->name);
        self::assertTrue($attribute->annotations?->readOnlyHint);
        self::assertStringContainsString('Never build an admin URL yourself', (string) $attribute->description);
    }

    private function tool(): AdminLinkGenerateTool
    {
        return new AdminLinkGenerateTool(
            $this->generator->reveal(),
            $this->permissionChecker,
            new ContentTypeExtensionRegistry([new FakeContentTypeExtension()]),
            self::RESOURCES,
        );
    }
}
