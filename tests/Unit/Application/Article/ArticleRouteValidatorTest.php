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

namespace Sulu\Mcp\Tests\Unit\Application\Article;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sulu\Mcp\Application\Article\ArticleRouteValidator;

#[CoversClass(ArticleRouteValidator::class)]
final class ArticleRouteValidatorTest extends TestCase
{
    private const HINT = 'Use sulu_get_context to inspect template fields. Look for a property with type "route" (use content.url) or "page_tree_route" (use content.page).';

    // --- validate(): missing / conflicting routing form -----------------------------------

    public function testValidateReturnsNullWhenNoRoutingDataAndNotRequired(): void
    {
        $this->assertNull(ArticleRouteValidator::validate([], false));
    }

    public function testValidateReturnsErrorWhenNoRoutingDataAndRequired(): void
    {
        $result = ArticleRouteValidator::validate([], true);

        $this->assertSame([
            'error' => 'Article content is missing routing data. Pass either content={"url": "/my-article"} (simple route templates) or content={"page": {"path": "/blog", "uuid": "<page-uuid>", "suffix": "my-article"}} (page_tree_route templates). Call sulu_get_context to see which form your template expects -- look for a field of type "route" or "page_tree_route" in the template schema.',
            'hint' => self::HINT,
        ], $result);
    }

    public function testValidateReturnsErrorWhenBothUrlAndPagePresent(): void
    {
        $result = ArticleRouteValidator::validate([
            'url' => '/my-article',
            'page' => ['path' => '/blog', 'uuid' => 'parent-uuid', 'suffix' => 'my-article'],
        ], true);

        $this->assertSame([
            'error' => 'Article content has both "url" and "page" routing fields. Pass exactly one form depending on the template (use sulu_get_context to check).',
            'hint' => self::HINT,
        ], $result);
    }

    public function testValidateRejectsBothRoutingFormsEvenWhenNotRequired(): void
    {
        // $required only gates the "neither form present" branch -- a conflicting pair of
        // forms must still be rejected regardless of whether routing is mandatory.
        $result = ArticleRouteValidator::validate([
            'url' => '/my-article',
            'page' => ['path' => '/blog', 'uuid' => 'parent-uuid', 'suffix' => 'my-article'],
        ], false);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('both', $result['error']);
    }

    // --- validate(): simple "url" string form ----------------------------------------------

    public function testValidateAcceptsSimpleUrlString(): void
    {
        $this->assertNull(ArticleRouteValidator::validate(['url' => '/my-article'], true));
    }

    /** @return array<string, array{0: mixed}> */
    public static function invalidSimpleUrlValueProvider(): array
    {
        return [
            'empty string' => [''],
            'integer' => [42],
            'boolean' => [true],
            'null' => [null],
        ];
    }

    #[DataProvider('invalidSimpleUrlValueProvider')]
    public function testValidateRejectsNonStringUrl(mixed $url): void
    {
        $result = ArticleRouteValidator::validate(['url' => $url], true);

        $this->assertSame([
            'error' => 'Article content.url must be a non-empty string, e.g. "/my-article", or a page_tree_route object.',
            'hint' => self::HINT,
        ], $result);
    }

    public function testValidateRejectsUrlWithoutLeadingSlash(): void
    {
        $result = ArticleRouteValidator::validate(['url' => 'my-article'], true);

        $this->assertSame([
            'error' => 'Article content.url must start with "/". Got: my-article',
            'hint' => self::HINT,
        ], $result);
    }

    public function testValidateStillRejectsInvalidUrlWhenNotRequired(): void
    {
        // $required=false ("update" calls) must not turn off validation of a routing form
        // that *was* supplied -- it only allows omitting routing entirely.
        $result = ArticleRouteValidator::validate(['url' => 'no-leading-slash'], false);

        $this->assertSame([
            'error' => 'Article content.url must start with "/". Got: no-leading-slash',
            'hint' => self::HINT,
        ], $result);
    }

    // --- validate(): "url" as a page_tree_route object (Sulu-native nested form) -----------

    public function testValidateAcceptsUrlAsPageTreeRouteObject(): void
    {
        $result = ArticleRouteValidator::validate([
            'url' => [
                'page' => ['path' => '/blog', 'uuid' => 'parent-uuid'],
                'suffix' => 'my-article',
            ],
        ], true);

        $this->assertNull($result);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function urlPageNotObjectProvider(): array
    {
        return [
            'page key missing' => [['suffix' => 'my-article']],
            'page is a string' => [['page' => 'not-an-object', 'suffix' => 'my-article']],
        ];
    }

    #[DataProvider('urlPageNotObjectProvider')]
    public function testValidateRejectsUrlObjectWithNonObjectPage(array $urlContent): void
    {
        $result = ArticleRouteValidator::validate(['url' => $urlContent], true);

        $this->assertSame([
            'error' => 'Article content.url.page must be an object with keys "path" and "uuid".',
            'hint' => self::HINT,
        ], $result);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function urlPageTreeRouteMissingKeyProvider(): array
    {
        return [
            'missing path' => [
                ['page' => ['uuid' => 'parent-uuid'], 'suffix' => 'my-article'],
                'Article content.url.page.path must be a non-empty string.',
            ],
            'empty path' => [
                ['page' => ['path' => '', 'uuid' => 'parent-uuid'], 'suffix' => 'my-article'],
                'Article content.url.page.path must be a non-empty string.',
            ],
            'missing uuid' => [
                ['page' => ['path' => '/blog'], 'suffix' => 'my-article'],
                'Article content.url.page.uuid must be a non-empty string.',
            ],
            'non-string uuid' => [
                ['page' => ['path' => '/blog', 'uuid' => 42], 'suffix' => 'my-article'],
                'Article content.url.page.uuid must be a non-empty string.',
            ],
        ];
    }

    #[DataProvider('urlPageTreeRouteMissingKeyProvider')]
    public function testValidateRejectsUrlObjectWithInvalidPageKey(array $urlContent, string $expectedMessage): void
    {
        $result = ArticleRouteValidator::validate(['url' => $urlContent], true);

        $this->assertSame([
            'error' => $expectedMessage,
            'hint' => self::HINT,
        ], $result);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function urlPageTreeRouteInvalidSuffixProvider(): array
    {
        return [
            'missing suffix' => [['page' => ['path' => '/blog', 'uuid' => 'parent-uuid']]],
            'empty suffix' => [['page' => ['path' => '/blog', 'uuid' => 'parent-uuid'], 'suffix' => '']],
        ];
    }

    #[DataProvider('urlPageTreeRouteInvalidSuffixProvider')]
    public function testValidateRejectsUrlObjectWithInvalidSuffix(array $urlContent): void
    {
        $result = ArticleRouteValidator::validate(['url' => $urlContent], true);

        $this->assertSame([
            'error' => 'Article content.url.suffix must be a non-empty string.',
            'hint' => self::HINT,
        ], $result);
    }

    // --- validate(): "page" alias form -------------------------------------------------------

    public function testValidateAcceptsPageAlias(): void
    {
        $result = ArticleRouteValidator::validate([
            'page' => ['path' => '/blog', 'uuid' => 'parent-uuid', 'suffix' => 'my-article'],
        ], true);

        $this->assertNull($result);
    }

    public function testValidateRejectsPageAliasThatIsNotAnObject(): void
    {
        $result = ArticleRouteValidator::validate(['page' => 'not-an-object'], true);

        $this->assertSame([
            'error' => 'Article content.page must be an object with keys "path", "uuid", and "suffix".',
            'hint' => self::HINT,
        ], $result);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function pageAliasMissingKeyProvider(): array
    {
        return [
            'missing path' => [
                ['uuid' => 'parent-uuid', 'suffix' => 'my-article'],
                'Article content.page.path must be a non-empty string. Required keys: path (parent URL), uuid (parent page UUID), suffix (article slug).',
            ],
            'empty path' => [
                ['path' => '', 'uuid' => 'parent-uuid', 'suffix' => 'my-article'],
                'Article content.page.path must be a non-empty string. Required keys: path (parent URL), uuid (parent page UUID), suffix (article slug).',
            ],
            'non-string path' => [
                ['path' => 123, 'uuid' => 'parent-uuid', 'suffix' => 'my-article'],
                'Article content.page.path must be a non-empty string. Required keys: path (parent URL), uuid (parent page UUID), suffix (article slug).',
            ],
            'missing uuid' => [
                ['path' => '/blog', 'suffix' => 'my-article'],
                'Article content.page.uuid must be a non-empty string. Required keys: path (parent URL), uuid (parent page UUID), suffix (article slug).',
            ],
            'missing suffix' => [
                ['path' => '/blog', 'uuid' => 'parent-uuid'],
                'Article content.page.suffix must be a non-empty string. Required keys: path (parent URL), uuid (parent page UUID), suffix (article slug).',
            ],
            'empty suffix' => [
                ['path' => '/blog', 'uuid' => 'parent-uuid', 'suffix' => ''],
                'Article content.page.suffix must be a non-empty string. Required keys: path (parent URL), uuid (parent page UUID), suffix (article slug).',
            ],
        ];
    }

    #[DataProvider('pageAliasMissingKeyProvider')]
    public function testValidateRejectsPageAliasWithInvalidKey(array $page, string $expectedMessage): void
    {
        $result = ArticleRouteValidator::validate(['page' => $page], true);

        $this->assertSame([
            'error' => $expectedMessage,
            'hint' => self::HINT,
        ], $result);
    }

    // --- normalizeForSulu() ------------------------------------------------------------------

    public function testNormalizeForSuluLeavesContentUnchangedWhenNoPageKey(): void
    {
        $content = ['url' => '/my-article', 'title' => 'Test'];

        $this->assertSame($content, ArticleRouteValidator::normalizeForSulu($content));
    }

    public function testNormalizeForSuluLeavesContentUnchangedWhenUrlAlreadyPresent(): void
    {
        // Both keys present is an invalid state that validate() would already have rejected,
        // but normalizeForSulu() itself must not silently overwrite an existing "url".
        $content = [
            'url' => '/my-article',
            'page' => ['path' => '/blog', 'uuid' => 'parent-uuid', 'suffix' => 'my-article'],
        ];

        $this->assertSame($content, ArticleRouteValidator::normalizeForSulu($content));
    }

    public function testNormalizeForSuluLeavesContentUnchangedWhenPageIsNotAnArray(): void
    {
        $content = ['page' => 'not-an-array'];

        $this->assertSame($content, ArticleRouteValidator::normalizeForSulu($content));
    }

    public function testNormalizeForSuluConvertsPageAliasToSuluUrlShape(): void
    {
        $content = [
            'title' => 'Test',
            'page' => ['path' => '/blog', 'uuid' => 'parent-uuid', 'suffix' => 'my-article'],
        ];

        $result = ArticleRouteValidator::normalizeForSulu($content);

        $this->assertSame([
            'title' => 'Test',
            'url' => [
                'page' => ['path' => '/blog', 'uuid' => 'parent-uuid'],
                'suffix' => 'my-article',
            ],
        ], $result);
        $this->assertArrayNotHasKey('page', $result);
    }

    public function testNormalizeForSuluDefaultsMissingPageSubKeysToNull(): void
    {
        $result = ArticleRouteValidator::normalizeForSulu(['page' => ['path' => '/blog']]);

        $this->assertSame([
            'url' => [
                'page' => ['path' => '/blog', 'uuid' => null],
                'suffix' => null,
            ],
        ], $result);
    }

    // --- assertRoutingResolved() -------------------------------------------------------------

    public function testAssertRoutingResolvedReturnsNullWhenNoRoutingWasRequested(): void
    {
        $result = ArticleRouteValidator::assertRoutingResolved(['url' => null], []);

        $this->assertNull($result);
    }

    public function testAssertRoutingResolvedReturnsNullWhenUrlStringResolved(): void
    {
        $result = ArticleRouteValidator::assertRoutingResolved(
            ['url' => '/my-article'],
            ['url' => '/my-article'],
        );

        $this->assertNull($result);
    }

    public function testAssertRoutingResolvedReturnsNullWhenPageTreeRouteResolved(): void
    {
        $result = ArticleRouteValidator::assertRoutingResolved(
            ['url' => ['page' => ['path' => '/blog', 'uuid' => 'parent-uuid'], 'suffix' => 'my-article']],
            ['page' => ['path' => '/blog', 'uuid' => 'parent-uuid', 'suffix' => 'my-article']],
        );

        $this->assertNull($result);
    }

    public function testAssertRoutingResolvedRejectsEmptyResolvedUrlString(): void
    {
        $result = ArticleRouteValidator::assertRoutingResolved(
            ['url' => ''],
            ['url' => '/my-article'],
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('routing was dropped', $result['error']);
    }

    public function testAssertRoutingResolvedRejectsMissingResolvedUrlKey(): void
    {
        $result = ArticleRouteValidator::assertRoutingResolved(
            ['title' => 'Test'],
            ['url' => '/my-article'],
        );

        $this->assertArrayHasKey('error', $result);
    }

    public function testAssertRoutingResolvedRejectsPageTreeRouteMissingSuffix(): void
    {
        $result = ArticleRouteValidator::assertRoutingResolved(
            ['url' => ['page' => ['path' => '/blog', 'uuid' => 'parent-uuid'], 'suffix' => '']],
            ['url' => ['page' => ['path' => '/blog', 'uuid' => 'parent-uuid'], 'suffix' => 'my-article']],
        );

        $this->assertArrayHasKey('error', $result);
    }

    public function testAssertRoutingResolvedSuggestsPageTreeRouteWhenSimpleUrlWasTried(): void
    {
        $result = ArticleRouteValidator::assertRoutingResolved(
            ['url' => null],
            ['url' => '/my-article'],
        );

        $this->assertSame(
            'Article was created but routing was dropped (url resolved to null). This usually means the URL form does not match the template\'s route property type. Tried content.url as a simple route but the template likely uses page_tree_route. Retry with content={"page": {"path": "...", "uuid": "...", "suffix": "..."}}. Call sulu_get_context to inspect the template field types.',
            $result['error'],
        );
    }

    public function testAssertRoutingResolvedSuggestsSimpleRouteWhenUrlObjectWasTried(): void
    {
        $result = ArticleRouteValidator::assertRoutingResolved(
            ['url' => null],
            ['url' => ['page' => ['path' => '/blog', 'uuid' => 'parent-uuid'], 'suffix' => 'my-article']],
        );

        $this->assertSame(
            'Article was created but routing was dropped (url resolved to null). This usually means the URL form does not match the template\'s route property type. Tried content.url as a page_tree_route object but the template likely uses a simple route. Retry with content={"url": "/<full-path>"}. Call sulu_get_context to inspect the template field types.',
            $result['error'],
        );
    }

    public function testAssertRoutingResolvedSuggestsSimpleRouteWhenPageAliasWasTried(): void
    {
        $result = ArticleRouteValidator::assertRoutingResolved(
            ['url' => null],
            ['page' => ['path' => '/blog', 'uuid' => 'parent-uuid', 'suffix' => 'my-article']],
        );

        $this->assertSame(
            'Article was created but routing was dropped (url resolved to null). This usually means the URL form does not match the template\'s route property type. Tried content.page as a page_tree_route but the template likely uses a simple route. Retry with content={"url": "/<full-path>"}. Call sulu_get_context to inspect the template field types.',
            $result['error'],
        );
    }
}
