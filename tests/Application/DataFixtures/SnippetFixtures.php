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

namespace Sulu\Mcp\Tests\Application\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Sulu\Content\Domain\Model\WorkflowInterface;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Snippet\Application\Message\ApplyWorkflowTransitionSnippetMessage;
use Sulu\Snippet\Application\Message\CreateSnippetMessage;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

class SnippetFixtures extends Fixture
{
    public function __construct(
        #[Autowire(service: 'sulu_message_bus')]
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        foreach ($this->getSnippetsData() as $snippetData) {
            $this->createAndPublishSnippet($snippetData);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createAndPublishSnippet(array $data): void
    {
        $envelope = $this->messageBus->dispatch(
            new Envelope(
                new CreateSnippetMessage($data),
                [new EnableFlushStamp()],
            ),
        );

        /** @var HandledStamp[] $handledStamps */
        $handledStamps = $envelope->all(HandledStamp::class);

        /** @var SnippetInterface $snippet */
        $snippet = $handledStamps[0]->getResult();

        $this->messageBus->dispatch(
            new Envelope(
                new ApplyWorkflowTransitionSnippetMessage(
                    identifier: ['uuid' => $snippet->getUuid()],
                    locale: 'en',
                    transitionName: WorkflowInterface::WORKFLOW_TRANSITION_PUBLISH,
                ),
                [new EnableFlushStamp()],
            ),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getSnippetsData(): array
    {
        return [
            [
                'locale' => 'en',
                'template' => 'default',
                'title' => 'Footer',
                'description' => '<p>Sulu MCP — bringing AI-powered content management to Sulu CMS. Built with the Model Context Protocol so your editorial team can stay in control while letting AI handle the heavy lifting.</p>',
            ],
            [
                'locale' => 'en',
                'template' => 'default',
                'title' => 'Newsletter Signup',
                'description' => '<p>Subscribe to our monthly newsletter for product updates, tutorials, and the latest in AI-driven content workflows. We respect your inbox — no spam, ever.</p>',
            ],
            [
                'locale' => 'en',
                'template' => 'default',
                'title' => 'Office Address',
                'description' => '<p><strong>Sulu MCP HQ</strong><br>Sample Street 42<br>1010 Vienna, Austria<br><br>hello@sulu.io</p>',
            ],
            [
                'locale' => 'en',
                'template' => 'default',
                'title' => 'Privacy Notice',
                'description' => '<p>We take your privacy seriously. Sulu MCP processes only the content you explicitly share with the AI assistant, and never sends your data to third parties without your consent.</p>',
            ],
            [
                'locale' => 'en',
                'template' => 'default',
                'title' => 'Cookie Banner',
                'description' => '<p>This site uses essential cookies to keep you logged in and remember your preferences. We do not use tracking cookies. By using this site, you agree to our cookie policy.</p>',
            ],
        ];
    }
}
