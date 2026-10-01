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

use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversNothing;
use Sulu\Bundle\SecurityBundle\System\SystemStoreInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Tests\Application\TestBundle\ContentType\WidgetContentTypeExtension;
use Sulu\Mcp\Tests\Application\TestBundle\ContentType\WidgetMessageHandler;
use Sulu\Mcp\UserInterface\Mcp\Tool\Content\ContentDeleteTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\Content\ContentPublishTool;
use Sulu\Mcp\UserInterface\Mcp\Tool\GetContextTool;

/**
 * The generic tools reach a content type of another bundle only through its extension.
 * The "widgets" extension of the test bundle stands in for such a bundle.
 */
#[CoversNothing]
final class ContentTypeExtensionToolsTest extends FunctionalTestCase
{
    private const CONTEXT = 'sulu.mcp_test.widgets';

    private PermissionFixtureBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $container = self::getContainer();
        $container->get('mcp.server.sulu');

        $this->builder = new PermissionFixtureBuilder(
            $this->entityManager,
            $container->get('sulu_security.mask_converter'),
            $container->get('security.token_storage'),
            $container->get(SystemStoreInterface::class),
        );
    }

    public function testViewOnlyRoleSeesTheReadToolsButNotTheWriteTools(): void
    {
        $this->authenticateWith([PermissionTypes::VIEW => true]);

        $tools = $this->toolCatalogue();

        self::assertTrue($tools['sulu_block_list']['available']);
        self::assertFalse($tools['sulu_content_publish']['available']);
        self::assertFalse($tools['sulu_content_delete']['available']);
        self::assertNotEmpty($tools['sulu_content_publish']['reason']);
    }

    public function testRoleWithoutAPermissionOnTheExtensionContextSeesNoneOfTheGenericToolsAsAvailable(): void
    {
        $this->authenticateWith(null);

        $tools = $this->toolCatalogue();

        foreach (['sulu_block_list', 'sulu_content_publish', 'sulu_content_delete'] as $name) {
            self::assertFalse($tools[$name]['available'], \sprintf('%s must be unavailable without a permission on the extension context.', $name));
        }
    }

    public function testEditorCanPublishAndDeleteAndTheExtensionBuildsTheMessages(): void
    {
        $this->authenticateWith([
            PermissionTypes::VIEW => true,
            PermissionTypes::EDIT => true,
            PermissionTypes::LIVE => true,
            PermissionTypes::DELETE => true,
        ]);

        $tools = $this->toolCatalogue();
        self::assertTrue($tools['sulu_content_publish']['available']);
        self::assertTrue($tools['sulu_content_delete']['available']);

        $published = $this->publishTool()->publishContent('widgets', 'widget-1', 'en');
        self::assertTrue($published['success'] ?? false, \json_encode($published));
        self::assertSame('widgets', $published['resourceKey']);

        $deleted = $this->deleteTool()->deleteContent('widgets', 'widget-1', 'en');
        self::assertTrue($deleted['success'] ?? false, \json_encode($deleted));

        $handled = self::getContainer()->get(WidgetMessageHandler::class)->handled;
        self::assertCount(2, $handled);
        self::assertSame(['transition', 'widget-1', 'publish'], [$handled[0]->kind, $handled[0]->uuid, $handled[0]->transition]);
        self::assertSame(['remove', 'widget-1'], [$handled[1]->kind, $handled[1]->uuid]);
    }

    public function testViewOnlyRoleIsRejectedWhenCallingAWriteToolDirectly(): void
    {
        $this->authenticateWith([PermissionTypes::VIEW => true]);

        try {
            $this->publishTool()->publishContent('widgets', 'widget-1', 'en');
            self::fail('Publishing without EDIT and LIVE on the extension context must be rejected.');
        } catch (ToolCallException) {
        }

        self::assertSame([], self::getContainer()->get(WidgetMessageHandler::class)->handled);
    }

    public function testViewOnlyRoleIsRejectedWhenCallingTheDeleteToolDirectly(): void
    {
        $this->authenticateWith([PermissionTypes::VIEW => true]);

        try {
            $this->deleteTool()->deleteContent('widgets', 'widget-1', 'en');
            self::fail('Deleting without DELETE on the extension context must be rejected.');
        } catch (ToolCallException) {
        }

        self::assertSame([], self::getContainer()->get(WidgetMessageHandler::class)->handled);
    }

    public function testAnUnknownEntityIsReportedAndSendsNoMessage(): void
    {
        $this->authenticateWith([PermissionTypes::VIEW => true, PermissionTypes::EDIT => true, PermissionTypes::LIVE => true]);

        $result = $this->publishTool()->publishContent('widgets', WidgetContentTypeExtension::UNKNOWN_UUID, 'en');

        self::assertStringContainsString('not found', $result['error'] ?? '');
        self::assertSame([], self::getContainer()->get(WidgetMessageHandler::class)->handled);
    }

    /**
     * @param array<string, bool>|null $mask null grants nothing on the extension context
     */
    private function authenticateWith(?array $mask): void
    {
        $role = $this->builder->role('WidgetRole', null === $mask
            ? ['sulu.settings.tags' => [PermissionTypes::VIEW => true]]
            : [self::CONTEXT => $mask, WidgetContentTypeExtension::OBJECT_CONTEXT => $mask]);
        $this->builder->authenticate($this->builder->user('widget-user', $role));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function toolCatalogue(): array
    {
        /** @var GetContextTool $tool */
        $tool = self::getContainer()->get(GetContextTool::class);

        return \array_column($tool->getContext('en')['tools'], null, 'name');
    }

    private function publishTool(): ContentPublishTool
    {
        return self::getContainer()->get(ContentPublishTool::class);
    }

    private function deleteTool(): ContentDeleteTool
    {
        return self::getContainer()->get(ContentDeleteTool::class);
    }
}
