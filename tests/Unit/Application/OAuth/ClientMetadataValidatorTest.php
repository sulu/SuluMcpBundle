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
namespace Sulu\Mcp\Tests\Unit\Application\OAuth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sulu\Mcp\Application\OAuth\ClientMetadata;
use Sulu\Mcp\Application\OAuth\ClientMetadataValidator;
use Sulu\Mcp\Application\OAuth\RedirectUriPolicy;
use Sulu\Mcp\Domain\Exception\ClientMetadataException;

#[CoversClass(ClientMetadataValidator::class)]
#[CoversClass(ClientMetadata::class)]
#[CoversClass(RedirectUriPolicy::class)]
final class ClientMetadataValidatorTest extends TestCase
{
    private const URL = 'https://client.example/oauth/metadata.json';

    public function testAcceptsAValidDocument(): void
    {
        $metadata = (new ClientMetadataValidator())->validate(self::URL, $this->document());

        self::assertSame(self::URL, $metadata->clientId);
        self::assertSame('Example Client', $metadata->clientName);
        self::assertSame(['https://client.example/callback', 'http://127.0.0.1:3000/cb'], $metadata->redirectUris);
    }

    public function testAcceptsExplicitPublicClientFields(): void
    {
        $document = $this->document([
            'token_endpoint_auth_method' => 'none',
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
        ]);

        self::assertSame('Example Client', (new ClientMetadataValidator())->validate(self::URL, $document)->clientName);
    }

    public function testTruncatesALongName(): void
    {
        $metadata = (new ClientMetadataValidator())->validate(self::URL, $this->document(['client_name' => \str_repeat('é', 300)]));

        self::assertSame(ClientMetadataValidator::MAX_NAME_LENGTH, \mb_strlen($metadata->clientName));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('provideInvalidDocuments')]
    public function testRejectsInvalidDocuments(array $overrides, string $message): void
    {
        $this->expectException(ClientMetadataException::class);
        $this->expectExceptionMessage($message);

        (new ClientMetadataValidator())->validate(self::URL, $this->document($overrides));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideInvalidDocuments(): iterable
    {
        yield 'client_id mismatch' => [['client_id' => 'https://evil.example/oauth/metadata.json'], 'does not match'];
        yield 'client_id differing by trailing slash' => [['client_id' => self::URL . '/'], 'does not match'];
        yield 'client_id differing by case' => [['client_id' => 'https://CLIENT.example/oauth/metadata.json'], 'does not match'];
        yield 'client_id missing' => [['client_id' => null], 'does not match'];
        yield 'client_name missing' => [['client_name' => null], 'client_name is required'];
        yield 'client_name blank' => [['client_name' => '  '], 'client_name is required'];
        yield 'client_name not a string' => [['client_name' => ['x']], 'client_name is required'];
        yield 'redirect_uris missing' => [['redirect_uris' => null], 'redirect_uris is required'];
        yield 'redirect_uris empty' => [['redirect_uris' => []], 'redirect_uris is required'];
        yield 'redirect_uris not a list' => [['redirect_uris' => 'https://client.example/cb'], 'redirect_uris is required'];
        yield 'redirect_uris with non string' => [['redirect_uris' => [42]], 'redirect_uris must be'];
        yield 'redirect_uri plain http' => [['redirect_uris' => ['http://client.example/cb']], 'redirect_uris must be'];
        yield 'redirect_uri with fragment' => [['redirect_uris' => ['https://client.example/cb#x']], 'redirect_uris must be'];
        yield 'redirect_uri javascript' => [['redirect_uris' => ['javascript:alert(1)']], 'redirect_uris must be'];
        yield 'redirect_uri without scheme' => [['redirect_uris' => ['client.example/cb']], 'redirect_uris must be'];
        yield 'too many redirect_uris' => [['redirect_uris' => \array_map(static fn (int $i): string => 'https://client.example/cb' . $i, \range(1, 11))], 'at most'];
        yield 'client_secret' => [['client_secret' => 'x'], 'only public clients'];
        yield 'client_secret_expires_at' => [['client_secret_expires_at' => 0], 'only public clients'];
        yield 'jwks_uri' => [['jwks_uri' => 'https://client.example/jwks'], 'only public clients'];
        yield 'client_secret_basic' => [['token_endpoint_auth_method' => 'client_secret_basic'], 'must be "none"'];
        yield 'private_key_jwt' => [['token_endpoint_auth_method' => 'private_key_jwt'], 'must be "none"'];
        yield 'implicit grant' => [['grant_types' => ['implicit']], 'grant_types'];
        yield 'client credentials' => [['grant_types' => ['authorization_code', 'client_credentials']], 'grant_types'];
        yield 'token response type' => [['response_types' => ['token']], 'response_types'];
    }

    #[DataProvider('provideInvalidJson')]
    public function testRejectsDocumentsThatAreNotAnObject(string $json): void
    {
        $this->expectException(ClientMetadataException::class);

        (new ClientMetadataValidator())->validate(self::URL, $json);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidJson(): iterable
    {
        yield 'syntax error' => ['{"client_id":'];
        yield 'empty' => [''];
        yield 'scalar' => ['"x"'];
        yield 'list' => ['[1,2]'];
        yield 'null' => ['null'];
    }

    /**
     * @param array<string, mixed> $overrides null removes the key
     */
    private function document(array $overrides = []): string
    {
        $document = \array_merge([
            'client_id' => self::URL,
            'client_name' => 'Example Client',
            'redirect_uris' => ['https://client.example/callback', 'http://127.0.0.1:3000/cb'],
        ], $overrides);

        return \json_encode(\array_filter($document, static fn (mixed $value): bool => null !== $value), \JSON_THROW_ON_ERROR);
    }
}
