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

namespace Sulu\Mcp\UserInterface\Command;

use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @internal
 */
#[AsCommand(
    name: 'sulu:mcp:create-client',
    description: 'Create an OAuth2 client for MCP connections (Claude.ai, ChatGPT, etc.)',
)]
class CreateMcpClientCommand extends Command
{
    private const CLAUDE_CALLBACK_URI = 'https://claude.ai/api/mcp/auth_callback';

    /**
     * @var array<string, string>
     */
    private const CLIENTS = [
        'claude-ai' => 'Claude.ai',
        'claude-cowork' => 'Claude Cowork',
        'chatgpt' => 'ChatGPT',
    ];

    public function __construct(
        private readonly ClientManagerInterface $clientManager,
        private readonly string $serverUrl,
        private readonly string $mcpPath = '/admin/mcp',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Client name (e.g. "Claude.ai Production")')
            ->addOption('client', null, InputOption::VALUE_REQUIRED, 'Target MCP client: ' . \implode(', ', \array_keys(self::CLIENTS)))
            ->addOption('redirect-uri', null, InputOption::VALUE_REQUIRED, 'OAuth callback URI (overrides the per-client prompt)')
            ->addOption('scope', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'OAuth scopes', ['mcp:tools', 'mcp:resources'])
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $name = $input->getArgument('name');
        if (!\is_string($name)) {
            $io->error('Client name must be a string.');

            return Command::INVALID;
        }

        $scopeOption = $input->getOption('scope');
        // getOption() only declares `mixed`; VALUE_IS_ARRAY guarantees an array here
        $scopes = \is_array($scopeOption)
            ? \array_values(\array_filter($scopeOption, static fn ($s): bool => \is_string($s) && '' !== $s))
            : [];

        $client = $this->resolveClient($input, $io);
        if (null === $client) {
            $io->error(\sprintf('Unknown client. Use one of: %s.', \implode(', ', \array_keys(self::CLIENTS))));

            return Command::INVALID;
        }

        $redirectUri = $this->resolveRedirectUri($input, $io, $client);
        $requiresCallbackAfterCreation = null === $redirectUri && 'chatgpt' === $client && $input->isInteractive();

        if (null === $redirectUri && !$requiresCallbackAfterCreation) {
            $io->error('A redirect URI is required for this client. Pass --redirect-uri or run the command interactively.');

            return Command::INVALID;
        }

        $identifier = \bin2hex(\random_bytes(16));
        $secret = \bin2hex(\random_bytes(32));

        $oauthClient = new Client($name, $identifier, $secret);
        if (null !== $redirectUri) {
            $oauthClient->setRedirectUris(new RedirectUri($redirectUri));
        }
        $oauthClient->setGrants(new Grant('authorization_code'), new Grant('refresh_token'));
        $oauthClient->setScopes(...\array_map(static fn (string $s) => new Scope($s), $scopes));
        $oauthClient->setActive(true);

        $this->clientManager->save($oauthClient);

        $io->success('MCP OAuth client created.');

        $io->table(
            ['Setting', 'Value'],
            [
                ['Name', $name],
                ['Client ID', $identifier],
                ['Client Secret', $secret],
                ['Redirect URI', $redirectUri ?? '(paste the callback URL below)'],
                ['Grant Types', 'authorization_code, refresh_token'],
                ['Scopes', \implode(', ', $scopes)],
            ],
        );

        $this->printSetupInstructions($io, $client, $name, $identifier, $secret, $requiresCallbackAfterCreation);

        if ($requiresCallbackAfterCreation) {
            $redirectUri = $this->askRedirectUri(
                $io,
                'Paste the callback URL ChatGPT shows after saving the connector',
            );
            $oauthClient->setRedirectUris(new RedirectUri($redirectUri));
            $this->clientManager->save($oauthClient);

            $io->success('ChatGPT callback URL registered on this OAuth client.');
        }

        $io->warning('Save the Client Secret now — it cannot be retrieved later.');

        return Command::SUCCESS;
    }

    private function resolveClient(InputInterface $input, SymfonyStyle $io): ?string
    {
        $client = $input->getOption('client');

        if (null !== $client) {
            return \is_string($client) && isset(self::CLIENTS[$client]) ? $client : null;
        }

        if (!$input->isInteractive()) {
            return 'claude-ai';
        }

        $chosen = $io->choice('Which MCP client is this connector for?', \array_keys(self::CLIENTS), 'claude-ai');

        // choice() only declares `mixed`; a non-multiselect call always returns one choice
        return \is_string($chosen) && isset(self::CLIENTS[$chosen]) ? $chosen : null;
    }

    /**
     * @return non-empty-string|null
     */
    private function resolveRedirectUri(InputInterface $input, SymfonyStyle $io, string $client): ?string
    {
        $explicit = $input->getOption('redirect-uri');
        if (\is_string($explicit) && '' !== $explicit) {
            return $explicit;
        }

        if ('claude-ai' === $client) {
            return self::CLAUDE_CALLBACK_URI;
        }

        // ChatGPT only reveals its callback URL once the connector is saved, so create
        // the client first and prompt for the URL afterwards.
        if ('chatgpt' === $client) {
            return null;
        }

        if (!$input->isInteractive()) {
            return null;
        }

        return $this->askRedirectUri(
            $io,
            \sprintf('Paste the redirect URI that %s shows you', self::CLIENTS[$client]),
        );
    }

    /**
     * @return non-empty-string
     */
    private function askRedirectUri(SymfonyStyle $io, string $question): string
    {
        $answer = $io->ask(
            $question,
            null,
            static function(mixed $value): string {
                $value = \is_string($value) ? \trim($value) : '';
                if ('' === $value) {
                    throw new \RuntimeException('Redirect URI is required.');
                }

                return $value;
            },
        );

        // ask() only declares `mixed`; the validator above guarantees a non-empty string
        if (!\is_string($answer) || '' === $answer) {
            throw new \RuntimeException('Redirect URI is required.');
        }

        return $answer;
    }

    private function printSetupInstructions(SymfonyStyle $io, string $client, string $name, string $identifier, string $secret, bool $requiresCallbackAfterCreation): void
    {
        $mcpUrl = \rtrim($this->serverUrl, '/') . $this->mcpPath;
        $label = self::CLIENTS[$client];

        $io->section(\sprintf('%s Setup', $label));

        if ('chatgpt' === $client) {
            $io->listing([
                'Go to ChatGPT → Settings → Connectors → Create (custom connector)',
                \sprintf('Name: %s', $name),
                \sprintf('MCP Server URL: %s', $mcpUrl),
                \sprintf('OAuth Client ID: %s', $identifier),
                \sprintf('OAuth Client Secret: %s', $secret),
            ]);

            if ($requiresCallbackAfterCreation) {
                $io->text('Save the connector in ChatGPT. When ChatGPT shows its callback URL, paste it here to finish registration.');
            }

            return;
        }

        $io->listing([
            \sprintf('Go to %s → Settings → Connectors → Add Custom Connector', $label),
            \sprintf('Name: %s', $name),
            \sprintf('Remote MCP Server URL: %s', $mcpUrl),
            \sprintf('OAuth Client ID: %s', $identifier),
            \sprintf('OAuth Client Secret: %s', $secret),
        ]);
    }
}
