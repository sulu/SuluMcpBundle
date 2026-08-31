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

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Bundle\MediaBundle\Entity\Collection;
use Sulu\Bundle\SecurityBundle\System\SystemStoreInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Infrastructure\Mcp\PermissionAwareCallToolHandler;

/**
 * Vertical smoke over the real compiled permission map and
 * PermissionAwareCallToolHandler, covering what stubbed-checker/unit tests
 * cannot reach. Each floor denial is paired with a positive control, so the
 * deny is attributable to the missing permission, not a dead fixture.
 */
#[CoversClass(PermissionAwareCallToolHandler::class)]
final class PermissionHandlerSmokeTest extends FunctionalTestCase
{
    use ProphecyTrait;

    private function handler(): PermissionAwareCallToolHandler
    {
        return self::getContainer()->get(PermissionAwareCallToolHandler::class);
    }

    /**
     * @param array<string, array<string, bool>> $contextMasks
     */
    private function grantRole(string $name, array $contextMasks, string $username): void
    {
        $container = self::getContainer();
        $builder = new PermissionFixtureBuilder(
            $this->entityManager,
            $container->get('sulu_security.mask_converter'),
            $container->get('security.token_storage'),
            $container->get(SystemStoreInterface::class),
        );
        $role = $builder->role($name, $contextMasks);
        $user = $builder->user($username, $role);
        $builder->authenticate($user);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function callRequest(string $name, array $arguments): CallToolRequest
    {
        return CallToolRequest::fromArray([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ]);
    }

    private function session(): SessionInterface
    {
        // CallToolHandler only stashes the session into $arguments['_session'];
        // no method is ever invoked on it along these paths.
        return $this->prophesize(SessionInterface::class)->reveal();
    }

    /**
     * @param Response<CallToolResult> $response
     */
    private function textOf(Response $response): string
    {
        $result = $response->result;
        $first = $result->content[0] ?? null;
        self::assertInstanceOf(TextContent::class, $first);
        self::assertIsString($first->text);

        return $first->text;
    }

    /**
     * Asserts the call passed the permission preflight: the handler only puts
     * "Permission denied" inside a Response, never an Error, so an Error here
     * already proves the gate cleared and failed downstream instead.
     *
     * @param Response<CallToolResult>|Error $response
     */
    private function assertNotDeniedAtPreflight(Response|Error $response): void
    {
        if ($response instanceof Error) {
            self::assertStringNotContainsString('Permission denied', $response->message);

            return;
        }

        self::assertStringNotContainsString('Permission denied', $this->textOf($response));
    }

    /**
     * Fail-closed for a tool name absent from the compiled permission map and
     * not allowlisted: denied before any registry lookup or permission check,
     * so no role/authentication fixture is needed here. Ported from the
     * former Integration suite's PermissionEnforcementTest.
     */
    public function testUndeclaredNonAllowlistedToolIsDeniedFailClosed(): void
    {
        $response = $this->handler()->handle(
            $this->callRequest('sulu_mystery_tool', []),
            $this->session(),
        );

        self::assertInstanceOf(Response::class, $response);
        self::assertTrue($response->result->isError);
        self::assertStringContainsString('Permission denied', $this->textOf($response));
    }

    /** Read floor is VIEW -- EDIT without VIEW must not read (Sulu maps GET to VIEW). */
    public function testEditWithoutViewIsDeniedPageList(): void
    {
        $this->grantRole('EditNoView', [
            'sulu.webspaces.website' => [PermissionTypes::EDIT => true, PermissionTypes::VIEW => false],
        ], 'edit-no-view');

        $response = $this->handler()->handle(
            $this->callRequest('sulu_page_list', ['webspace' => 'website', 'locale' => 'en']),
            $this->session(),
        );

        self::assertInstanceOf(Response::class, $response);
        self::assertTrue($response->result->isError);
        self::assertStringContainsString('Permission denied', $this->textOf($response));
    }

    /**
     * Positive control for the read-floor case above: a VIEW-only role must NOT
     * be denied a read, proving the deny above is attributable to the missing VIEW.
     */
    public function testViewGrantedRoleIsNotDeniedPageList(): void
    {
        $this->grantRole('Viewer', [
            'sulu.webspaces.website' => [PermissionTypes::VIEW => true],
        ], 'viewer');

        $response = $this->handler()->handle(
            $this->callRequest('sulu_page_list', ['webspace' => 'website', 'locale' => 'en']),
            $this->session(),
        );

        $this->assertNotDeniedAtPreflight($response);
    }

    /** EDIT-without-LIVE -- content_publish denied. */
    public function testEditWithoutLiveIsDeniedPublish(): void
    {
        $this->grantRole('EditNoLive', [
            'sulu.webspaces.website' => [PermissionTypes::EDIT => true, PermissionTypes::LIVE => false],
        ], 'edit-no-live');

        $response = $this->handler()->handle(
            $this->callRequest('sulu_content_publish', ['type' => 'page', 'uuid' => 'irrelevant', 'locale' => 'en']),
            $this->session(),
        );

        self::assertInstanceOf(Response::class, $response);
        self::assertTrue($response->result->isError);
        self::assertStringContainsString('Permission denied', $this->textOf($response));

        // Positive control: same role HAS EDIT, so sulu_page_update must not be
        // denied -- isolates the publish deny above to the missing LIVE.
        $updateResponse = $this->handler()->handle(
            $this->callRequest('sulu_page_update', ['uuid' => 'irrelevant', 'locale' => 'en', 'title' => 'x']),
            $this->session(),
        );

        $this->assertNotDeniedAtPreflight($updateResponse);
    }

    /** LIVE-without-EDIT -- page_update denied (the intentional-divergence floor, EDIT side). */
    public function testLiveWithoutEditIsDeniedPageUpdate(): void
    {
        $this->grantRole('LiveNoEdit', [
            'sulu.webspaces.website' => [
                PermissionTypes::LIVE => true, PermissionTypes::VIEW => true, PermissionTypes::EDIT => false,
            ],
        ], 'live-no-edit');

        $response = $this->handler()->handle(
            $this->callRequest('sulu_page_update', ['uuid' => 'irrelevant', 'locale' => 'en', 'title' => 'x']),
            $this->session(),
        );

        self::assertInstanceOf(Response::class, $response);
        self::assertTrue($response->result->isError);
        self::assertStringContainsString('Permission denied', $this->textOf($response));

        // Positive control: same role HAS VIEW, so sulu_page_list must not be
        // denied -- isolates the page_update deny above to the missing EDIT.
        $listResponse = $this->handler()->handle(
            $this->callRequest('sulu_page_list', ['webspace' => 'website', 'locale' => 'en']),
            $this->session(),
        );

        $this->assertNotDeniedAtPreflight($listResponse);
    }

    /**
     * contextArgument substitution (page_create): #context# <- `webspace` arg.
     * Grant EDIT on 'website' only.
     */
    public function testPageCreateContextArgumentSubstitution(): void
    {
        // Populates the registry lazily so the allow-branch reaches PageCreateTool's body.
        self::getContainer()->get('mcp.server.sulu');

        $this->grantRole('WebsiteCreator', [
            'sulu.webspaces.website' => [PermissionTypes::EDIT => true, PermissionTypes::ADD => true],
        ], 'website-creator');

        // Wrong webspace substituted in -> denied, message names the substituted context.
        $denied = $this->handler()->handle(
            $this->callRequest('sulu_page_create', [
                'webspace' => 'intranet', 'locale' => 'en', 'template' => 'default',
                'title' => 'x', 'parentId' => 'irrelevant',
            ]),
            $this->session(),
        );
        self::assertInstanceOf(Response::class, $denied);
        self::assertTrue($denied->result->isError);
        self::assertStringContainsString('sulu.webspaces.intranet', $this->textOf($denied));

        // Correct webspace -> preflight passes, fails downstream (no page table in schema).
        $allowed = $this->handler()->handle(
            $this->callRequest('sulu_page_create', [
                'webspace' => 'website', 'locale' => 'en', 'template' => 'default',
                'title' => 'x', 'parentId' => 'irrelevant',
            ]),
            $this->session(),
        );
        self::assertInstanceOf(Response::class, $allowed);
        $text = $this->textOf($allowed);
        self::assertStringNotContainsString('your Sulu role does not grant', $text);
        self::assertStringNotContainsString('no accessible security context', $text);
        self::assertStringContainsString('Failed to create page', $text);
    }

    /**
     * contextResolver dispatch (contact_list): `type` -> sulu.contact.people |
     * sulu.contact.organizations. Grant EDIT on people only.
     */
    public function testContactListContextResolverDispatch(): void
    {
        // Populates the registry lazily so the allow-branch reaches ContactListTool's body.
        self::getContainer()->get('mcp.server.sulu');

        $this->grantRole('PeopleEditor', [
            'sulu.contact.people' => [PermissionTypes::VIEW => true],
        ], 'people-editor');

        $peopleAllowed = $this->handler()->handle(
            $this->callRequest('sulu_contact_list', ['type' => 'contact']),
            $this->session(),
        );
        self::assertInstanceOf(Response::class, $peopleAllowed);
        $text = $this->textOf($peopleAllowed);
        self::assertStringNotContainsString('your Sulu role does not grant', $text);
        // ContactBundle's tables are present against the real schema, so the call
        // succeeds for real rather than hitting ContactListTool's missing-bundle catch.
        self::assertStringContainsString('"type": "contact"', $text);

        $organizationsDenied = $this->handler()->handle(
            $this->callRequest('sulu_contact_list', ['type' => 'account']),
            $this->session(),
        );
        self::assertInstanceOf(Response::class, $organizationsDenied);
        self::assertTrue($organizationsDenied->result->isError);
        self::assertStringContainsString('sulu.contact.organizations', $this->textOf($organizationsDenied));
    }

    /**
     * Multi-requirement atomicity: content_delete requires EDIT AND DELETE on
     * ONE candidate context -- granting each on a DIFFERENT candidate must
     * still be denied (coarseDenial() doesn't split across candidates).
     */
    public function testContentDeleteRequiresBothPermissionsOnSameCandidate(): void
    {
        $this->grantRole('SplitGrant', [
            'sulu.webspaces.website' => [PermissionTypes::EDIT => true, PermissionTypes::DELETE => false],
            'sulu.snippet.snippets' => [PermissionTypes::EDIT => false, PermissionTypes::DELETE => true],
        ], 'split-grant');

        $response = $this->handler()->handle(
            $this->callRequest('sulu_content_delete', ['type' => 'page', 'uuid' => 'irrelevant', 'locale' => 'en']),
            $this->session(),
        );

        self::assertInstanceOf(Response::class, $response);
        self::assertTrue(
            $response->result->isError,
            'EDIT on one candidate and DELETE on a different candidate must not combine into an allow.',
        );
    }

    /**
     * Positive control for the atomicity test above: granting BOTH EDIT and
     * DELETE on the SAME candidate must NOT be denied, proving the split-grant
     * deny above is attributable to splitting across candidates.
     */
    public function testContentDeleteBothPermissionsOnSameCandidateIsNotDenied(): void
    {
        $this->grantRole('SameCandidateGrant', [
            'sulu.webspaces.website' => [PermissionTypes::EDIT => true, PermissionTypes::DELETE => true],
        ], 'same-candidate-grant');

        $response = $this->handler()->handle(
            $this->callRequest('sulu_content_delete', ['type' => 'page', 'uuid' => 'irrelevant', 'locale' => 'en']),
            $this->session(),
        );

        $this->assertNotDeniedAtPreflight($response);
    }

    /**
     * Explicit-collectionId deny for media_list: MediaListTool
     * checks collectionId permission BEFORE loading media rows, so this is
     * assertable end-to-end with only an AccessControl deny row, no content.
     */
    public function testMediaListDeniedCollectionIdIsRejectedWithoutContent(): void
    {
        self::getContainer()->get('mcp.server.sulu');

        $container = self::getContainer();
        $builder = new PermissionFixtureBuilder(
            $this->entityManager,
            $container->get('sulu_security.mask_converter'),
            $container->get('security.token_storage'),
            $container->get(SystemStoreInterface::class),
        );
        // Also grants VIEW on system_collections: MediaListTool short-circuits
        // isSystemCollection() (unavailable in this security-only schema) once the
        // caller has that VIEW, isolating the assertion to the per-collection ACL.
        $role = $builder->role('MediaListEditor', [
            'sulu.media.collections' => [PermissionTypes::EDIT => true, PermissionTypes::VIEW => true],
            'sulu.media.system_collections' => [PermissionTypes::VIEW => true],
        ]);
        $builder->objectAcl(Collection::class, 99, $role, [
            PermissionTypes::VIEW => false, PermissionTypes::EDIT => true,
        ]);
        $user = $builder->user('media-list-editor', $role);
        $builder->authenticate($user);

        $response = $this->handler()->handle(
            $this->callRequest('sulu_media_list', ['locale' => 'en', 'collectionId' => 99]),
            $this->session(),
        );

        self::assertInstanceOf(Response::class, $response);
        self::assertTrue($response->result->isError);
        self::assertStringContainsString('sulu.media.collections', $this->textOf($response));
    }

    /**
     * Positive control for the deny above: querying a DIFFERENT collectionId
     * with no AccessControl row must NOT be denied, proving the deny above is
     * attributable to the object ACL row, not a dead fixture.
     */
    public function testMediaListAllowedCollectionIdIsNotDeniedAtObjectAcl(): void
    {
        self::getContainer()->get('mcp.server.sulu');

        $container = self::getContainer();
        $builder = new PermissionFixtureBuilder(
            $this->entityManager,
            $container->get('sulu_security.mask_converter'),
            $container->get('security.token_storage'),
            $container->get(SystemStoreInterface::class),
        );
        // Also grants VIEW on system_collections: MediaListTool short-circuits
        // isSystemCollection() (unavailable in this security-only schema) once the
        // caller has that VIEW, isolating the assertion to the per-collection ACL.
        $role = $builder->role('MediaListEditor', [
            'sulu.media.collections' => [PermissionTypes::EDIT => true, PermissionTypes::VIEW => true],
            'sulu.media.system_collections' => [PermissionTypes::VIEW => true],
        ]);
        $builder->objectAcl(Collection::class, 99, $role, [
            PermissionTypes::VIEW => false, PermissionTypes::EDIT => true,
        ]);
        $user = $builder->user('media-list-editor', $role);
        $builder->authenticate($user);

        $response = $this->handler()->handle(
            $this->callRequest('sulu_media_list', ['locale' => 'en', 'collectionId' => 100]),
            $this->session(),
        );

        $this->assertNotDeniedAtPreflight($response);
    }
}
