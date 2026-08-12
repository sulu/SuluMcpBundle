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
use Sulu\Bundle\MediaBundle\Collection\Manager\CollectionManagerInterface;
use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class MediaFixtures extends Fixture
{
    public function __construct(
        #[Autowire(service: 'sulu_media.collection_manager')]
        private readonly CollectionManagerInterface $collectionManager,
        private readonly MediaManagerInterface $mediaManager,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $tmpDir = \sys_get_temp_dir() . '/sulu-mcp-fixture-media';
        $fs = new Filesystem();
        $fs->mkdir($tmpDir);

        try {
            foreach ($this->getCollectionsData() as $collectionData) {
                $items = $collectionData['items'];
                unset($collectionData['items']);

                $collection = $this->collectionManager->save($collectionData, null);
                $collectionId = $collection->getId();

                foreach ($items as $item) {
                    $this->uploadMedia($tmpDir, $collectionId, $item);
                }
            }
        } finally {
            $fs->remove($tmpDir);
        }
    }

    /**
     * @param array{title: string, description: string, color: array{int<0, 255>, int<0, 255>, int<0, 255>}, label: string, filename: string} $item
     */
    private function uploadMedia(string $tmpDir, int $collectionId, array $item): void
    {
        $filePath = $tmpDir . '/' . $item['filename'];
        $this->generatePlaceholderPng($filePath, $item['color'], $item['label']);

        $uploadedFile = new UploadedFile(
            path: $filePath,
            originalName: $item['filename'],
            mimeType: 'image/png',
            test: true,
        );

        $this->mediaManager->save(
            $uploadedFile,
            [
                'collection' => $collectionId,
                'locale' => 'en',
                'title' => $item['title'],
                'description' => $item['description'],
            ],
            null,
        );
    }

    /**
     * @param array{int<0, 255>, int<0, 255>, int<0, 255>} $rgb
     */
    private function generatePlaceholderPng(string $path, array $rgb, string $label): void
    {
        $width = 800;
        $height = 600;
        $image = \imagecreatetruecolor($width, $height);
        \assert(false !== $image);

        $bg = \imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
        $fg = \imagecolorallocate($image, 255, 255, 255);
        \assert(false !== $bg && false !== $fg);

        \imagefilledrectangle($image, 0, 0, $width, $height, $bg);

        $font = 5;
        $textWidth = \imagefontwidth($font) * \strlen($label);
        $textHeight = \imagefontheight($font);
        \imagestring($image, $font, (int) (($width - $textWidth) / 2), (int) (($height - $textHeight) / 2), $label, $fg);

        \imagepng($image, $path);
        \imagedestroy($image);
    }

    /**
     * @return list<array{locale: string, title: string, description: string, key: string, type: array{id: int}, items: list<array{title: string, description: string, color: array{int<0, 255>, int<0, 255>, int<0, 255>}, label: string, filename: string}>}>
     */
    private function getCollectionsData(): array
    {
        $defaultType = ['id' => 1];

        return [
            [
                'locale' => 'en',
                'title' => 'Hero Images',
                'description' => 'Large banner images used at the top of pages and articles.',
                'key' => 'demo-hero-images',
                'type' => $defaultType,
                'items' => [
                    ['title' => 'Sunset Skyline', 'description' => 'A warm orange skyline used for blog hero sections.', 'color' => [234, 88, 12], 'label' => 'SUNSET', 'filename' => 'hero-sunset.png'],
                    ['title' => 'Ocean Wave', 'description' => 'Cool blue tones for editorial articles about technology.', 'color' => [14, 116, 144], 'label' => 'OCEAN', 'filename' => 'hero-ocean.png'],
                    ['title' => 'Forest Canopy', 'description' => 'Deep green hero image for sustainability content.', 'color' => [22, 101, 52], 'label' => 'FOREST', 'filename' => 'hero-forest.png'],
                ],
            ],
            [
                'locale' => 'en',
                'title' => 'Article Illustrations',
                'description' => 'Inline illustrations for blog posts and music artist profiles.',
                'key' => 'demo-article-illustrations',
                'type' => $defaultType,
                'items' => [
                    ['title' => 'Vinyl Record', 'description' => 'Black vinyl illustration for music articles.', 'color' => [30, 30, 30], 'label' => 'VINYL', 'filename' => 'article-vinyl.png'],
                    ['title' => 'Microphone', 'description' => 'Studio microphone for audio-related posts.', 'color' => [120, 53, 15], 'label' => 'MIC', 'filename' => 'article-microphone.png'],
                    ['title' => 'Headphones', 'description' => 'Headphones illustration for podcast and listening guides.', 'color' => [88, 28, 135], 'label' => 'HEADPHONES', 'filename' => 'article-headphones.png'],
                    ['title' => 'Coffee Cup', 'description' => 'Latte illustration used for casual editorial pieces.', 'color' => [120, 113, 108], 'label' => 'COFFEE', 'filename' => 'article-coffee.png'],
                ],
            ],
            [
                'locale' => 'en',
                'title' => 'Documents',
                'description' => 'Brochures, whitepapers, and other downloadable assets.',
                'key' => 'demo-documents',
                'type' => $defaultType,
                'items' => [
                    ['title' => 'Sulu MCP Whitepaper', 'description' => 'Technical whitepaper describing the Sulu MCP architecture.', 'color' => [55, 65, 81], 'label' => 'WHITEPAPER', 'filename' => 'doc-whitepaper.png'],
                    ['title' => 'Brand Guidelines', 'description' => 'Brand colors, typography, and voice guidelines.', 'color' => [157, 23, 77], 'label' => 'BRAND', 'filename' => 'doc-brand.png'],
                ],
            ],
        ];
    }
}
