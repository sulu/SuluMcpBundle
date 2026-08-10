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

namespace Sulu\Bundle\McpBundle\Tests\Unit\Capabilities\Tool\Taxonomy;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\CategoryBundle\Category\CategoryManagerInterface;
use Sulu\Bundle\CategoryBundle\Entity\CategoryInterface;
use Sulu\Bundle\McpBundle\AdminLink\AdminLinkGenerator;
use Sulu\Bundle\McpBundle\AdminLink\Provider\CategoryAdminLinkProvider;
use Sulu\Bundle\McpBundle\Capabilities\Tool\Taxonomy\CategoryCreateTool;
use Sulu\Bundle\McpBundle\Tests\Application\TestBundle\Admin\TestViewRegistry;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[CoversClass(CategoryCreateTool::class)]
final class CategoryCreateToolTest extends TestCase
{
    private CategoryManagerInterface&MockObject $categoryManager;
    private TokenStorageInterface&MockObject $tokenStorage;
    private CategoryCreateTool $tool;

    protected function setUp(): void
    {
        $this->categoryManager = $this->createMock(CategoryManagerInterface::class);
        $this->tokenStorage = $this->createMock(TokenStorageInterface::class);

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturn('https://example.com/admin/');
        $adminLinkGenerator = new AdminLinkGenerator($router, [new CategoryAdminLinkProvider(new TestViewRegistry())]);

        $this->tool = new CategoryCreateTool($this->categoryManager, $this->tokenStorage, $adminLinkGenerator);
    }

    public function testCreateCategoryReturnsSuccess(): void
    {
        $this->mockAuthenticatedUser(1);

        $category = $this->createMock(CategoryInterface::class);
        $category->method('getId')->willReturn(10);
        $category->method('getKey')->willReturn('technology');

        $this->categoryManager->expects($this->once())
            ->method('save')
            ->with(['name' => 'Technology', 'locale' => 'en', 'key' => 'technology'], 1, 'en')
            ->willReturn($category);

        $result = $this->tool->createCategory('en', 'Technology', 'technology');

        $this->assertTrue($result['success']);
        $this->assertSame(10, $result['id']);
        $this->assertSame('Technology', $result['name']);
        $this->assertSame('technology', $result['key']);
        $this->assertSame('https://example.com/admin/#/categories/en/10', $result['admin_url']);
    }

    public function testCreateCategoryWithParentId(): void
    {
        $this->mockAuthenticatedUser(1);

        $category = $this->createMock(CategoryInterface::class);
        $category->method('getId')->willReturn(11);
        $category->method('getKey')->willReturn('php');

        $this->categoryManager->expects($this->once())
            ->method('save')
            ->with(['name' => 'PHP', 'locale' => 'en', 'parent' => 10], 1, 'en')
            ->willReturn($category);

        $result = $this->tool->createCategory('en', 'PHP', null, 10);

        $this->assertTrue($result['success']);
    }

    public function testCreateCategoryReturnsErrorWhenNoUser(): void
    {
        $this->tokenStorage->method('getToken')->willReturn(null);

        $result = $this->tool->createCategory('en', 'Test');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('No authenticated user', $result['error']);
        $this->assertTrue(\array_key_exists('hint', $result));
        $this->assertIsString($result['hint']);
        $this->assertNotEmpty($result['hint']);
    }

    public function testCreateCategoryReturnsHintOnSaveFailure(): void
    {
        $this->mockAuthenticatedUser(1);

        $this->categoryManager->method('save')->willThrowException(new \RuntimeException('Duplicate key'));

        $result = $this->tool->createCategory('en', 'Duplicate');

        $this->assertArrayHasKey('error', $result);
        $this->assertTrue(\array_key_exists('hint', $result));
        $this->assertIsString($result['hint']);
        $this->assertNotEmpty($result['hint']);
    }

    public function testMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(CategoryCreateTool::class, 'createCategory');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes);
        $this->assertSame('sulu_category_create', $attributes[0]->newInstance()->name);
    }

    public function testParentIdParameterHasSchemaAttribute(): void
    {
        $reflection = new \ReflectionMethod(CategoryCreateTool::class, 'createCategory');
        $parameter = $reflection->getParameters()[3];
        $this->assertSame('parentId', $parameter->getName());

        $attributes = $parameter->getAttributes(Schema::class);
        $this->assertCount(1, $attributes);

        $schema = $attributes[0]->newInstance();
        $this->assertStringContainsString('Integer', $schema->description);
        $this->assertStringContainsString('NOT a UUID', $schema->description);
    }

    private function mockAuthenticatedUser(int $userId): void
    {
        $user = new class($userId) implements UserInterface {
            public function __construct(private readonly int $id)
            {
            }

            public function getId(): int
            {
                return $this->id;
            }

            public function getRoles(): array
            {
                return [];
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return 'admin';
            }
        };

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $this->tokenStorage->method('getToken')->willReturn($token);
    }
}
