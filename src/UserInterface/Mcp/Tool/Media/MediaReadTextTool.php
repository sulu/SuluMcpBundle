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

namespace Sulu\Mcp\UserInterface\Mcp\Tool\Media;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Smalot\PdfParser\Parser;
use Sulu\Bundle\MediaBundle\Entity\Collection;
use Sulu\Bundle\MediaBundle\Entity\MediaInterface;
use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;
use Sulu\Bundle\MediaBundle\Media\Storage\StorageInterface;
use Sulu\Component\Media\SystemCollections\SystemCollectionManagerInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\Domain\Security\PermissionRequirement;
use Sulu\Mcp\Domain\Security\RequiresPermission;

/**
 * @internal
 */
class MediaReadTextTool
{
    private const DEFAULT_MAX_CHARS = 20000;
    private const HARD_MAX_CHARS = 100000;
    private const CHUNK_BYTES = 65536;

    private const TEXT_MIME_TYPES = [
        'application/json',
        'application/ld+json',
        'application/xml',
        'application/x-yaml',
        'application/yaml',
        'application/csv',
        'application/x-csv',
    ];

    // Browsers and Sulu often label these files application/octet-stream.
    private const TEXT_EXTENSIONS = ['txt', 'md', 'markdown', 'csv', 'json', 'xml', 'yaml', 'yml'];

    public function __construct(
        private readonly MediaManagerInterface $mediaManager,
        private readonly StorageInterface $storage,
        private readonly ToolPermissionCheckerInterface $permissionChecker,
        private readonly int|float|string $maxFilesizeInMegabytes,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'sulu_media_read_text',
        title: 'Read Media Text',
        description: 'Read the plain text of a document in the media library by ID. Use it when the user attached or mentioned a document such as a datasheet, brochure, manual, report, text file or spreadsheet export and you need its content. Supports PDF and text formats (text, markdown, csv, json, xml, yaml). Images and other binary files have no readable text. Long documents come back in pages: when truncated is true, call again with offset set to nextOffset. Returns the text, mime type, total length in characters and the paging hints.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    #[RequiresPermission(
        requirements: [new PermissionRequirement('sulu.media.collections', PermissionTypes::VIEW)],
        objectResolved: true,
        discoveryContexts: ['sulu.media.collections'],
    )]
    public function readText(
        #[Schema(description: 'Id of the media. Use sulu_media_list to find one.')]
        int $id,
        #[Schema(description: 'Locale of the media metadata. Defaults to en.')]
        string $locale = 'en',
        #[Schema(description: 'Character position to start reading at. Use nextOffset of the previous call.', minimum: 0)]
        int $offset = 0,
        #[Schema(description: 'Maximum number of characters to return, at most 100000. Defaults to 20000.', minimum: 1, maximum: self::HARD_MAX_CHARS)]
        int $maxChars = self::DEFAULT_MAX_CHARS,
    ): array {
        try {
            $offset = \max(0, $offset);
            $maxChars = \min(self::HARD_MAX_CHARS, \max(1, $maxChars));

            try {
                $media = $this->mediaManager->getById($id, $locale);
                /** @var MediaInterface $entity */
                $entity = $media->getEntity();
                $collection = $entity->getCollection();
            } catch (\Throwable) {
                return [
                    'error' => \sprintf('Media not found: %d', $id),
                    'hint' => 'Verify the media id. Use sulu_media_list to browse available media.',
                ];
            }

            if (SystemCollectionManagerInterface::COLLECTION_TYPE === $collection->getType()->getKey()) {
                $this->permissionChecker->check('sulu.media.system_collections', PermissionTypes::VIEW, $locale);
            }
            $this->permissionChecker->check(
                'sulu.media.collections',
                PermissionTypes::VIEW,
                $locale,
                Collection::class,
                $collection->getId(),
            );

            $mimeType = (string) $media->getMimeType();
            $kind = $this->kindOf($mimeType, (string) $media->getExtension());

            if (null === $kind) {
                return [
                    'error' => \sprintf('Media %d has no readable text (%s).', $id, '' !== $mimeType ? $mimeType : 'unknown type'),
                    'hint' => 'Only PDF and text formats can be read. For an image use sulu_media_get and its title and description.',
                ];
            }

            $maxBytes = (int) ((float) $this->maxFilesizeInMegabytes * 1024 * 1024);
            if ($maxBytes > 0 && (int) $media->getSize() > $maxBytes) {
                return [
                    'error' => \sprintf('Media %d is too large to read (%d bytes, limit %d).', $id, (int) $media->getSize(), $maxBytes),
                    'hint' => 'The limit is sulu_media.upload.max_filesize. Ask the user for a shorter document.',
                ];
            }

            $stream = $this->storage->load($media->getStorageOptions());

            try {
                if ('pdf' === $kind) {
                    $window = $this->readPdf($stream, $maxBytes, $offset, $maxChars);
                } else {
                    $window = $this->readPlainText($stream, $offset, $maxChars);
                }
            } finally {
                \fclose($stream);
            }

            if (null === $window) {
                return [
                    'error' => \sprintf('Media %d contains no extractable text.', $id),
                    'hint' => 'A scanned PDF holds only images. Ask the user for a text version.',
                ];
            }

            [$text, $total] = $window;
            $end = $offset + \mb_strlen($text);
            $truncated = $end < $total;

            return [
                'id' => $id,
                'title' => $media->getTitle(),
                'mimeType' => $mimeType,
                'totalLength' => $total,
                'offset' => $offset,
                'returnedLength' => \mb_strlen($text),
                'truncated' => $truncated,
                'nextOffset' => $truncated ? $end : null,
                'text' => $text,
            ];
        } catch (PermissionDeniedException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            return [
                'error' => \sprintf('Failed to read media %d: %s', $id, $e->getMessage()),
                'hint' => 'The file may be damaged or encrypted. Use sulu_media_get to check the media.',
            ];
        }
    }

    private function kindOf(string $mimeType, string $extension): ?string
    {
        $mimeType = \strtolower(\trim(\explode(';', $mimeType)[0]));

        if ('application/pdf' === $mimeType) {
            return 'pdf';
        }

        if (\str_starts_with($mimeType, 'text/')
            || \in_array($mimeType, self::TEXT_MIME_TYPES, true)
            || \str_ends_with($mimeType, '+json')
            || \str_ends_with($mimeType, '+xml')
        ) {
            return 'text';
        }

        if (\in_array($mimeType, ['', 'application/octet-stream'], true)
            && \in_array(\strtolower($extension), [...self::TEXT_EXTENSIONS], true)
        ) {
            return 'text';
        }

        return null;
    }

    /**
     * The parser needs the whole file, so the size limit bounds the memory it can take.
     *
     * @param resource $stream
     *
     * @return array{string, int}|null
     */
    private function readPdf($stream, int $maxBytes, int $offset, int $maxChars): ?array
    {
        $content = \stream_get_contents($stream, $maxBytes > 0 ? $maxBytes + 1 : null);

        if (false === $content || ($maxBytes > 0 && \strlen($content) > $maxBytes)) {
            throw new \RuntimeException('The file exceeds the size limit.');
        }

        $text = \trim((new Parser())->parseContent($content)->getText());
        unset($content);

        if ('' === $text) {
            return null;
        }

        $text = \mb_scrub($text);

        return [\mb_substr($text, $offset, $maxChars), \mb_strlen($text)];
    }

    /**
     * Reads in chunks and keeps only the requested window, so a large file is never held in memory.
     *
     * @param resource $stream
     *
     * @return array{string, int}|null
     */
    private function readPlainText($stream, int $offset, int $maxChars): ?array
    {
        $window = '';
        $total = 0;
        $carry = '';
        $first = true;

        while (!\feof($stream)) {
            $chunk = \fread($stream, self::CHUNK_BYTES);
            if (false === $chunk || '' === $chunk) {
                break;
            }

            $chunk = $carry . $chunk;
            if ($first) {
                $chunk = \preg_replace('/^\xEF\xBB\xBF/', '', $chunk) ?? $chunk;
                $first = false;
            }

            $cut = $this->incompleteTailLength($chunk);
            $carry = \substr($chunk, \strlen($chunk) - $cut);
            $text = \mb_scrub(\substr($chunk, 0, \strlen($chunk) - $cut));

            $length = \mb_strlen($text);
            $from = \max($offset, $total);
            $to = \min($offset + $maxChars, $total + $length);
            if ($from < $to) {
                $window .= \mb_substr($text, $from - $total, $to - $from);
            }
            $total += $length;
        }

        $total += \mb_strlen(\mb_scrub($carry));

        if (0 === $total) {
            return null;
        }

        return [$window, $total];
    }

    /**
     * Number of trailing bytes that start a multi-byte character the chunk cut off.
     */
    private function incompleteTailLength(string $chunk): int
    {
        $length = \strlen($chunk);

        for ($back = 1; $back <= \min(3, $length); ++$back) {
            $byte = \ord($chunk[$length - $back]);

            if (0x80 === ($byte & 0xC0)) {
                continue;
            }

            $needed = match (true) {
                0xF0 === ($byte & 0xF8) => 4,
                0xE0 === ($byte & 0xF0) => 3,
                0xC0 === ($byte & 0xE0) => 2,
                default => 1,
            };

            return $needed > $back ? $back : 0;
        }

        return 0;
    }
}
