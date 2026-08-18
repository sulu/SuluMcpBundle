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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\Contact;

use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Bundle\ContactBundle\Entity\AccountRepositoryInterface;
use Sulu\Bundle\ContactBundle\Entity\ContactRepositoryInterface;
use Sulu\Mcp\UserInterface\Mcp\Tool\Contact\ContactListTool;

#[CoversClass(ContactListTool::class)]
final class ContactListToolTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<ContactRepositoryInterface> */
    private ObjectProphecy $contactRepository;
    /** @var ObjectProphecy<AccountRepositoryInterface> */
    private ObjectProphecy $accountRepository;
    private ContactListTool $tool;

    protected function setUp(): void
    {
        $this->contactRepository = $this->prophesize(ContactRepositoryInterface::class);
        $this->accountRepository = $this->prophesize(AccountRepositoryInterface::class);
        $this->tool = new ContactListTool($this->contactRepository->reveal(), $this->accountRepository->reveal());
    }

    public function testListContactsReturnsContacts(): void
    {
        // findGetAll returns arrays, not objects (getArrayResult)
        $contact = [
            'id' => 1,
            'firstName' => 'John',
            'lastName' => 'Doe',
        ];

        $this->contactRepository->findGetAll(Argument::cetera())->willReturn([$contact]);

        $result = $this->tool->listContacts('contact', 1, 20);

        $this->assertSame('contact', $result['type']);
        $this->assertCount(1, $result['items']);
        $this->assertSame(1, $result['items'][0]['id']);
        $this->assertSame('John', $result['items'][0]['firstName']);
        $this->assertSame('Doe', $result['items'][0]['lastName']);
    }

    public function testListAccountsReturnsAccounts(): void
    {
        $this->accountRepository->findAllSelect(Argument::cetera())->willReturn([['id' => 1, 'name' => 'Acme Corp']]);

        $result = $this->tool->listContacts('account', 1, 20);

        $this->assertSame('account', $result['type']);
        $this->assertCount(1, $result['items']);
        $this->assertSame('Acme Corp', $result['items'][0]['name']);
    }

    public function testListContactsCalculatesOffsetFromPage(): void
    {
        $this->contactRepository->findGetAll(5, 5, [], [])->shouldBeCalledOnce()
            ->willReturn([]);

        $this->tool->listContacts('contact', 2, 5);
    }

    public function testListAccountsCalculatesOffsetFromPage(): void
    {
        $accounts = [
            ['id' => 1, 'name' => 'A'],
            ['id' => 2, 'name' => 'B'],
            ['id' => 3, 'name' => 'C'],
            ['id' => 4, 'name' => 'D'],
            ['id' => 5, 'name' => 'E'],
            ['id' => 6, 'name' => 'F'],
        ];
        $this->accountRepository->findAllSelect(Argument::cetera())->willReturn($accounts);

        $result = $this->tool->listContacts('account', 2, 2);

        $this->assertCount(2, $result['items']);
        $this->assertSame('C', $result['items'][0]['name']);
        $this->assertSame('D', $result['items'][1]['name']);
    }

    public function testListContactsReturnsErrorOnException(): void
    {
        $this->contactRepository->findGetAll(Argument::cetera())->willThrow(new \RuntimeException('Bundle not installed'));

        $result = $this->tool->listContacts();

        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('hint', $result);
    }

    public function testMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(ContactListTool::class, 'listContacts');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes);
        $this->assertSame('sulu_contact_list', $attributes[0]->newInstance()->name);
    }
}
