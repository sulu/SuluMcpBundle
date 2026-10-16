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
use Sulu\Mcp\Infrastructure\League\Manager\CimdClientManager;
use Sulu\Mcp\Infrastructure\League\Manager\CimdClientRecords;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @internal
 */
#[AsCommand(
    name: 'sulu:mcp:cleanup-clients',
    description: 'Remove OAuth clients that were added by a metadata URL and have not been refreshed for 30 days',
)]
class CleanupCimdClientsCommand extends Command
{
    /**
     * @param (\Closure(): int)|null $now
     */
    public function __construct(
        private readonly ClientManagerInterface $clientManager,
        private readonly CimdClientRecords $records,
        private readonly ?\Closure $now = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List the clients without removing them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $now = null === $this->now ? \time() : ($this->now)();
        $before = (new \DateTimeImmutable())->setTimestamp($now - CimdClientManager::STORED_LIFETIME);

        $records = $this->records->findFetchedBefore($before);
        foreach ($records as $record) {
            $io->writeln(\sprintf('%s %s (last fetched %s)', $dryRun ? 'Would remove' : 'Removing', $record->getUrl(), $record->getLastFetchedAt()->format('Y-m-d')));

            if ($dryRun) {
                continue;
            }

            $client = $this->clientManager->find($record->getIdentifier());
            if (null !== $client) {
                $this->clientManager->remove($client);
            }
            $this->records->remove($record->getIdentifier());
        }

        $io->success(\sprintf('%d client(s) %s.', \count($records), $dryRun ? 'would be removed' : 'removed'));

        return Command::SUCCESS;
    }
}
