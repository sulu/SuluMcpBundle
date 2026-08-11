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

namespace Sulu\Mcp\UserInterface\Mcp\Tool\Contact;

use Mcp\Capability\Attribute\McpTool;
use Sulu\Bundle\ContactBundle\Entity\AccountRepositoryInterface;
use Sulu\Bundle\ContactBundle\Entity\ContactRepositoryInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;

/**
 * @internal
 */
class ContactListTool
{
    public function __construct(
        private readonly ContactRepositoryInterface $contactRepository,
        private readonly AccountRepositoryInterface $accountRepository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'sulu_contact_list',
        description: 'List contacts or accounts. Set type="contact" for people or type="account" for organizations. Returns basic info (id, name). Contacts and accounts are used for author attribution and organizational references in content.',
    )]
    #[RequiresPermission(
        requirements: [new PermissionRequirement('#context#', PermissionTypes::VIEW)],
        contextResolver: 'sulu_mcp.contact_context_resolver',
    )]
    public function listContacts(string $type = 'contact', int $page = 1, int $limit = 20): array
    {
        $offset = ($page - 1) * $limit;

        try {
            if ('account' === $type) {
                $items = $this->accountRepository->findAllSelect(['id', 'name']);
                $results = [];
                foreach (\array_slice($items, $offset, $limit) as $item) {
                    $results[] = [
                        'id' => $item['id'] ?? null,
                        'name' => $item['name'] ?? null,
                    ];
                }

                return ['items' => $results, 'type' => 'account'];
            }

            $items = $this->contactRepository->findGetAll($limit, $offset, [], []);
            $results = [];
            foreach ($items as $contact) {
                $results[] = [
                    'id' => $contact['id'] ?? null,
                    'firstName' => $contact['firstName'] ?? null,
                    'lastName' => $contact['lastName'] ?? null,
                ];
            }

            return ['items' => $results, 'type' => 'contact'];
        } catch (\Throwable $e) {
            return [
                'error' => \sprintf('Failed to list %ss: %s', $type, $e->getMessage()),
                'hint' => 'The ContactBundle may not be installed in this Sulu installation.',
            ];
        }
    }
}
