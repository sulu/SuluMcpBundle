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

namespace Sulu\Bundle\McpBundle\Tests\Application\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Sulu\Bundle\AdminBundle\Application\BlockIdGenerator\BlockIdGeneratorInterface;
use Sulu\Content\Domain\Model\WorkflowInterface;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Page\Application\Message\ApplyWorkflowTransitionPageMessage;
use Sulu\Page\Application\Message\CreatePageMessage;
use Sulu\Page\Application\Message\ModifyPageMessage;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

class PageFixtures extends Fixture
{
    public const BLOG_PAGE_REFERENCE = 'page-blog';
    public const MUSIC_PAGE_REFERENCE = 'page-music';

    public function __construct(
        #[Autowire(service: 'sulu_message_bus')]
        private readonly MessageBusInterface $messageBus,
        private readonly PageRepositoryInterface $pageRepository,
        private readonly BlockIdGeneratorInterface $blockIdGenerator,
    ) {
    }

    /**
     * Recursively inject a generated _id into every block-shaped array in $data.
     *
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private function injectBlockIds(array $data): array
    {
        foreach ($data as $key => $value) {
            if (\is_array($value)) {
                $data[$key] = $this->injectBlockIds($value);
            }
        }

        if (isset($data['type']) && \is_string($data['type']) && !isset($data['_id'])) {
            $data['_id'] = $this->blockIdGenerator->generateId();
        }

        return $data;
    }

    public function load(ObjectManager $manager): void
    {
        $homepage = $this->findOrCreateHomepage();
        $homepageUuid = $homepage->getUuid();

        foreach ($this->getChildPagesData() as $pageData) {
            $referenceName = $pageData['_reference'] ?? null;
            unset($pageData['_reference']);
            $this->createAndPublishPage($homepageUuid, $pageData, \is_string($referenceName) ? $referenceName : null);
        }
    }

    private function findOrCreateHomepage(): PageInterface
    {
        $homepage = $this->pageRepository->findOneBy([
            'parentId' => null,
        ]);

        if ($homepage instanceof PageInterface) {
            $this->addHomepageBlocks($homepage);

            return $homepage;
        }

        // Homepage not yet initialized (fixtures may run before sulu:page:initialize)
        $homepageData = $this->injectBlockIds([
            'locale' => 'en',
            'title' => 'Welcome to Sulu MCP',
            'template' => 'homepage',
            'url' => '/',
            'article' => '<p>This is a demo site powered by Sulu CMS with AI content management via the Model Context Protocol.</p>',
            'blocks' => [
                ['type' => 'heading', 'title' => 'AI-Powered Content Management'],
                ['type' => 'text', 'content' => '<p>Sulu MCP connects AI assistants like Claude and ChatGPT directly to your content management system. Create, edit, and publish content using natural language — while respecting your brand guidelines and content structure.</p>'],
                ['type' => 'quote', 'text' => '<p>The future of content management is conversational. AI assistants should understand your brand, not just execute commands.</p>', 'attribution' => 'Sulu MCP Team'],
                ['type' => 'heading', 'title' => 'Getting Started'],
                ['type' => 'text', 'content' => '<p>Connect your AI assistant to the MCP endpoint, authenticate with your Sulu credentials, and start managing content through conversation. All operations respect your existing roles and permissions.</p>'],
            ],
        ]);
        $envelope = $this->messageBus->dispatch(
            new Envelope(
                new CreatePageMessage(
                    webspaceKey: 'website',
                    parentId: 'homepage',
                    data: $homepageData,
                ),
                [new EnableFlushStamp()],
            ),
        );

        /** @var HandledStamp[] $handledStamps */
        $handledStamps = $envelope->all(HandledStamp::class);

        /** @var Page $page */
        $page = $handledStamps[0]->getResult();

        $this->messageBus->dispatch(
            new Envelope(
                new ApplyWorkflowTransitionPageMessage(
                    identifier: ['uuid' => $page->getUuid()],
                    locale: 'en',
                    transitionName: WorkflowInterface::WORKFLOW_TRANSITION_PUBLISH,
                ),
                [new EnableFlushStamp()],
            ),
        );

        return $page;
    }

    private function addHomepageBlocks(PageInterface $homepage): void
    {
        $data = $this->injectBlockIds([
            'locale' => 'en',
            'title' => 'Welcome to Sulu MCP',
            'template' => 'homepage',
            'article' => '<p>This is a demo site powered by Sulu CMS with AI content management via the Model Context Protocol.</p>',
            'blocks' => [
                ['type' => 'heading', 'title' => 'AI-Powered Content Management'],
                ['type' => 'text', 'content' => '<p>Sulu MCP connects AI assistants like Claude and ChatGPT directly to your content management system. Create, edit, and publish content using natural language — while respecting your brand guidelines and content structure.</p>'],
                ['type' => 'quote', 'text' => '<p>The future of content management is conversational. AI assistants should understand your brand, not just execute commands.</p>', 'attribution' => 'Sulu MCP Team'],
                ['type' => 'heading', 'title' => 'Getting Started'],
                ['type' => 'text', 'content' => '<p>Connect your AI assistant to the MCP endpoint, authenticate with your Sulu credentials, and start managing content through conversation. All operations respect your existing roles and permissions.</p>'],
            ],
        ]);

        $this->messageBus->dispatch(
            new Envelope(
                new ModifyPageMessage(
                    identifier: ['uuid' => $homepage->getUuid()],
                    data: $data,
                ),
                [new EnableFlushStamp()],
            ),
        );

        $this->messageBus->dispatch(
            new Envelope(
                new ApplyWorkflowTransitionPageMessage(
                    identifier: ['uuid' => $homepage->getUuid()],
                    locale: 'en',
                    transitionName: WorkflowInterface::WORKFLOW_TRANSITION_PUBLISH,
                ),
                [new EnableFlushStamp()],
            ),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createAndPublishPage(string $parentId, array $data, ?string $referenceName = null): void
    {
        /** @var array<string, mixed> $data */
        $data = $this->injectBlockIds($data);
        $envelope = $this->messageBus->dispatch(
            new Envelope(
                new CreatePageMessage(
                    webspaceKey: 'website',
                    parentId: $parentId,
                    data: $data,
                ),
                [new EnableFlushStamp()],
            ),
        );

        /** @var HandledStamp[] $handledStamps */
        $handledStamps = $envelope->all(HandledStamp::class);

        /** @var Page $page */
        $page = $handledStamps[0]->getResult();

        $this->messageBus->dispatch(
            new Envelope(
                new ApplyWorkflowTransitionPageMessage(
                    identifier: ['uuid' => $page->getUuid()],
                    locale: 'en',
                    transitionName: WorkflowInterface::WORKFLOW_TRANSITION_PUBLISH,
                ),
                [new EnableFlushStamp()],
            ),
        );

        if (null !== $referenceName) {
            $this->addReference($referenceName, $page);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getChildPagesData(): array
    {
        return [
            [
                'locale' => 'en',
                'title' => 'About Us',
                'template' => 'default',
                'url' => '/about',
                'navigationContexts' => ['main'],
                'article' => '<p>Learn more about our company and mission.</p>',
                'blocks' => [
                    [
                        'type' => 'section',
                        'title' => 'Our Story',
                        'blocks' => [
                            ['type' => 'text', 'content' => '<p>Founded in 2020, we set out to make content management smarter. Our team believes AI should empower content creators, not replace them.</p>'],
                            ['type' => 'quote', 'text' => '<p>The best content management is invisible — it gets out of the way and lets creators create.</p>', 'attribution' => 'Our Founder'],
                            ['type' => 'text', 'content' => '<p>Today we serve hundreds of organizations worldwide, helping them publish content faster and more consistently.</p>'],
                        ],
                    ],
                    [
                        'type' => 'section',
                        'title' => 'Our Values',
                        'blocks' => [
                            ['type' => 'heading', 'title' => 'Human-First AI'],
                            ['type' => 'text', 'content' => '<p>We build tools that amplify human creativity rather than replace it. Every AI feature we ship is designed with editorial control in mind.</p>'],
                            ['type' => 'heading', 'title' => 'Open Standards'],
                            ['type' => 'text', 'content' => '<p>We bet on open protocols like MCP instead of proprietary lock-in. Your content, your integrations, your choice.</p>'],
                        ],
                    ],
                ],
            ],
            [
                'locale' => 'en',
                'title' => 'Our Services',
                'template' => 'default',
                'url' => '/services',
                'navigationContexts' => ['main'],
                'article' => '',
                'blocks' => [
                    [
                        'type' => 'section',
                        'title' => 'Content Strategy',
                        'blocks' => [
                            ['type' => 'text', 'content' => '<p>Our content strategists help you define your voice, plan your editorial calendar, and measure impact.</p>'],
                            ['type' => 'quote', 'text' => '<p>Strategy without execution is daydreaming. Execution without strategy is a nightmare.</p>', 'attribution' => 'Content Strategy Team'],
                        ],
                    ],
                    [
                        'type' => 'section',
                        'title' => 'AI Integration',
                        'blocks' => [
                            ['type' => 'text', 'content' => '<p>We connect AI assistants directly to your CMS so they can create, edit, and publish on-brand content.</p>'],
                            ['type' => 'text', 'content' => '<p>All integrations use the Model Context Protocol (MCP), giving your AI full awareness of your templates, blocks, and content guidelines.</p>'],
                        ],
                    ],
                    [
                        'type' => 'section',
                        'title' => 'Training & Onboarding',
                        'blocks' => [
                            ['type' => 'text', 'content' => '<p>We run hands-on workshops to help your editorial team get the most out of AI-assisted content workflows.</p>'],
                        ],
                    ],
                ],
            ],
            [
                '_reference' => self::BLOG_PAGE_REFERENCE,
                'locale' => 'en',
                'title' => 'Blog',
                'template' => 'default',
                'url' => '/blog',
                'navigationContexts' => ['main'],
                'article' => '<p>Latest news and insights from our team.</p>',
                'blocks' => [
                    ['type' => 'article_list', 'title' => 'Recent Articles', 'articles' => ['provider' => 'articles', 'limitResult' => 10, 'sortBy' => 'published', 'sortMethod' => 'desc']],
                ],
            ],
            [
                '_reference' => self::MUSIC_PAGE_REFERENCE,
                'locale' => 'en',
                'title' => 'Music Artists',
                'template' => 'default',
                'url' => '/music',
                'navigationContexts' => ['main'],
                'article' => '<p>Profiles of artists who shaped the history of recorded music.</p>',
                'blocks' => [
                    ['type' => 'article_list', 'title' => 'Artist Profiles', 'articles' => ['provider' => 'articles', 'limitResult' => 20, 'sortBy' => 'published', 'sortMethod' => 'desc']],
                ],
            ],
            [
                'locale' => 'en',
                'title' => 'Contact',
                'template' => 'default',
                'url' => '/contact',
                'navigationContexts' => ['main'],
                'article' => '<p>Get in touch with our team.</p>',
                'blocks' => [
                    ['type' => 'heading', 'title' => 'Reach Out'],
                    ['type' => 'text', 'content' => '<p>Whether you have questions about our platform, need help with integration, or want to discuss a partnership, we would love to hear from you.</p>'],
                    ['type' => 'quote', 'text' => '<p>We typically respond within 24 hours on business days.</p>', 'attribution' => 'Support Team'],
                ],
            ],
        ];
    }
}
