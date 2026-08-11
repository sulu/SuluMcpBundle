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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Controller\Admin;

use League\Bundle\OAuth2ServerBundle\Event\AuthorizationRequestResolveEvent;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sulu\Mcp\Domain\Model\OAuthConsentRequest;
use Sulu\Mcp\Infrastructure\Symfony\Security\OAuthConsentStore;
use Sulu\Mcp\UserInterface\Controller\Admin\OAuthConsentController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(OAuthConsentController::class)]
#[CoversClass(OAuthConsentRequest::class)]
#[CoversClass(OAuthConsentStore::class)]
final class OAuthConsentControllerTest extends TestCase
{
    private OAuthConsentStore $store;
    private OAuthConsentController $controller;

    protected function setUp(): void
    {
        $this->store = new OAuthConsentStore();

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => match ($id) {
                'mcp:tools' => 'Use MCP tools',
                'mcp:resources' => 'Read MCP resources',
                default => $id,
            }
        );

        $security = $this->createMock(Security::class);

        $this->controller = new OAuthConsentController($this->store, $translator, $security);
    }

    public function testDetailsReturnsConsentMetadata(): void
    {
        $request = $this->request('/admin/mcp/authorize?response_type=code&client_id=client-1');
        $consentRequest = $this->store->create($request, $this->event(['mcp:tools']));

        $response = $this->controller->details($request, $consentRequest->getId());
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('client-1', $body['clientId']);
        self::assertSame('ChatGPT', $body['clientName']);
        self::assertSame('https://chatgpt.com/oauth/callback', $body['redirectUri']);
        self::assertSame([['id' => 'mcp:tools', 'label' => 'Use MCP tools']], $body['scopes']);
    }

    public function testDetailsReturnsNotFoundForMissingRequest(): void
    {
        $response = $this->controller->details($this->request('/admin/mcp/consent/missing'), 'missing');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('not_found', $this->json($response)['error']);
    }

    public function testDecisionStoresApprovalAndReturnsRedirectUrl(): void
    {
        $request = $this->request('/admin/mcp/authorize?response_type=code&client_id=client-1');
        $consentRequest = $this->store->create($request, $this->event(['mcp:tools']));

        $response = $this->controller->decision(
            $this->request('/admin/mcp/consent/'.$consentRequest->getId(), '{"approved": true}', $request->getSession()),
            $consentRequest->getId(),
        );
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($consentRequest->getContinuationUrl(), $body['redirectUrl']);
        self::assertTrue($this->store->get($request, $consentRequest->getId())?->getApproved());
    }

    public function testDecisionStoresDenial(): void
    {
        $request = $this->request('/admin/mcp/authorize?response_type=code&client_id=client-1');
        $consentRequest = $this->store->create($request, $this->event(['mcp:tools']));

        $response = $this->controller->decision(
            $this->request('/admin/mcp/consent/'.$consentRequest->getId(), '{"approved": false}', $request->getSession()),
            $consentRequest->getId(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($this->store->get($request, $consentRequest->getId())?->getApproved());
    }

    public function testDecisionRejectsInvalidPayload(): void
    {
        $request = $this->request('/admin/mcp/authorize?response_type=code&client_id=client-1');
        $consentRequest = $this->store->create($request, $this->event(['mcp:tools']));

        $response = $this->controller->decision(
            $this->request('/admin/mcp/consent/'.$consentRequest->getId(), '{"approved": "yes"}', $request->getSession()),
            $consentRequest->getId(),
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_request', $this->json($response)['error']);
        self::assertNull($this->store->get($request, $consentRequest->getId())?->getApproved());
    }

    public function testDecisionReturnsNotFoundForMissingRequest(): void
    {
        $response = $this->controller->decision(
            $this->request('/admin/mcp/consent/missing', '{"approved": true}'),
            'missing',
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('not_found', $this->json($response)['error']);
    }

    private function request(string $uri, ?string $content = null, ?Session $session = null): Request
    {
        $request = Request::create($uri, null === $content ? 'GET' : 'POST', [], [], [], [], $content);
        $request->setSession($session ?? new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function event(array $scopes): AuthorizationRequestResolveEvent
    {
        $authorizationRequest = $this->createMock(AuthorizationRequestInterface::class);
        $authorizationRequest->method('getRedirectUri')->willReturn('https://chatgpt.com/oauth/callback');
        $authorizationRequest->method('getState')->willReturn('state-1');

        return new AuthorizationRequestResolveEvent(
            $authorizationRequest,
            array_map(static fn (string $scope): Scope => new Scope($scope), $scopes),
            new Client('ChatGPT', 'client-1', 'secret'),
            new class implements UserInterface {
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
            },
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function json(JsonResponse $response): array
    {
        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);

        return $data;
    }
}
