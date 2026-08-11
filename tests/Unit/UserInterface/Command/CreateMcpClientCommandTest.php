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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Command;

use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\ClientInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sulu\Mcp\UserInterface\Command\CreateMcpClientCommand;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(CreateMcpClientCommand::class)]
final class CreateMcpClientCommandTest extends TestCase
{
    private ClientManagerInterface&MockObject $clientManager;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->clientManager = $this->createMock(ClientManagerInterface::class);
        $command = new CreateMcpClientCommand($this->clientManager, 'https://sulu.example.com', '/admin/_mcp');
        $this->tester = new CommandTester($command);
    }

    public function testInteractiveChatgptRegistersCallbackInSameCommand(): void
    {
        $savedRedirectUris = $this->captureSavedRedirectUris(2);

        $this->tester->setInputs(['chatgpt', 'https://chatgpt.com/aip/c-9999/oauth/callback']);
        $exitCode = $this->tester->execute(['name' => 'ChatGPT']);

        self::assertSame(0, $exitCode);
        self::assertSame(
            [
                [],
                ['https://chatgpt.com/aip/c-9999/oauth/callback'],
            ],
            $savedRedirectUris(),
        );
        self::assertStringNotContainsString('sulu:mcp:update-client', $this->tester->getDisplay());
    }

    public function testNonInteractiveChatgptWithoutRedirectUriFails(): void
    {
        $this->clientManager->expects($this->never())->method('save');

        $exitCode = $this->tester->execute(
            ['name' => 'ChatGPT', '--client' => 'chatgpt'],
            ['interactive' => false],
        );

        self::assertSame(2, $exitCode);
        self::assertStringContainsString('--redirect-uri', $this->tester->getDisplay());
    }

    public function testExplicitOptionsSkipPrompts(): void
    {
        $captured = $this->captureSavedClient();

        $exitCode = $this->tester->execute([
            'name' => 'ChatGPT Prod',
            '--client' => 'chatgpt',
            '--redirect-uri' => 'https://chatgpt.com/aip/c-9999/oauth/callback',
        ]);

        self::assertSame(0, $exitCode);
        self::assertSame(
            ['https://chatgpt.com/aip/c-9999/oauth/callback'],
            $this->stringValues($captured()->getRedirectUris()),
        );
    }

    public function testNonInteractiveDefaultsToClaudeCallback(): void
    {
        $captured = $this->captureSavedClient();

        $exitCode = $this->tester->execute(['name' => 'Claude.ai'], ['interactive' => false]);

        self::assertSame(0, $exitCode);
        self::assertSame(
            ['https://claude.ai/api/mcp/auth_callback'],
            $this->stringValues($captured()->getRedirectUris()),
        );
    }

    public function testNonInteractiveCoworkWithoutRedirectUriFails(): void
    {
        $this->clientManager->expects($this->never())->method('save');

        $exitCode = $this->tester->execute(
            ['name' => 'Cowork', '--client' => 'claude-cowork'],
            ['interactive' => false],
        );

        self::assertSame(2, $exitCode);
    }

    public function testUnknownClientFails(): void
    {
        $this->clientManager->expects($this->never())->method('save');

        $exitCode = $this->tester->execute(
            ['name' => 'X', '--client' => 'bogus'],
            ['interactive' => false],
        );

        self::assertSame(2, $exitCode);
    }

    /**
     * @return \Closure(): ?ClientInterface
     */
    private function captureSavedClient(): \Closure
    {
        $client = null;
        $this->clientManager->expects($this->once())
            ->method('save')
            ->willReturnCallback(static function (ClientInterface $saved) use (&$client): void {
                $client = $saved;
            });

        return static function () use (&$client): ?ClientInterface {
            return $client;
        };
    }

    /**
     * @return \Closure(): list<list<string>>
     */
    private function captureSavedRedirectUris(int $expectedSaves): \Closure
    {
        $redirectUris = [];
        $this->clientManager->expects($this->exactly($expectedSaves))
            ->method('save')
            ->willReturnCallback(function (ClientInterface $saved) use (&$redirectUris): void {
                $redirectUris[] = $this->stringValues($saved->getRedirectUris());
            });

        return static function () use (&$redirectUris): array {
            return $redirectUris;
        };
    }

    /**
     * @param list<\Stringable> $values
     *
     * @return list<string>
     */
    private function stringValues(array $values): array
    {
        return array_map(static fn (\Stringable $value): string => (string) $value, $values);
    }
}
