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

namespace Sulu\Mcp\Tests\Unit\Fixture;

use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;

/**
 * Grants everything unless a context or permission is denied. Records every call
 * so tests can assert what a subject checked.
 *
 * @internal
 */
final class FakeToolPermissionChecker implements ToolPermissionCheckerInterface
{
    /** @var list<array{method: string, context: string, permissions: list<string>, locale: string|null, objectType: string|null, objectId: mixed}> */
    private array $calls = [];

    /** @var list<string> */
    private array $deniedContexts = [];

    private bool $denyEverything = false;

    private ?\Throwable $failure = null;

    /** @var (\Closure(string, string, string|null, string|null, mixed): bool)|null */
    private ?\Closure $policy = null;

    /** @var list<string> */
    private array $granted = [];

    /** @var list<string> */
    private array $grantedContexts = [];

    private ?string $currentLocale = null;

    private ?string $currentObjectType = null;

    private mixed $currentObjectId = null;

    public static function grantingAll(): self
    {
        return new self();
    }

    /**
     * Switches to allowlist mode: nothing passes unless added with grant().
     */
    public function grantingNoneExcept(): self
    {
        return $this->denyAll();
    }

    /**
     * Makes every call blow up, for exercising fail-closed behaviour.
     */
    public function failingWith(\Throwable $failure): self
    {
        $this->failure = $failure;

        return $this;
    }

    /**
     * Decides every call with a predicate, for policies the allow/deny lists cannot express
     * (per-object grants and the like).
     *
     * @param \Closure(string, string, string|null, string|null, mixed): bool $policy
     */
    public function grantWhen(\Closure $policy): self
    {
        $this->policy = $policy;

        return $this;
    }

    public function grantContext(string $context): self
    {
        $this->grantedContexts[] = $context;

        return $this;
    }

    public function grant(string $context, string $permission): self
    {
        $this->granted[] = $context . ':' . $permission;

        return $this;
    }

    public function denyAll(): self
    {
        $this->denyEverything = true;

        return $this;
    }

    public function denyContext(string $context): self
    {
        $this->deniedContexts[] = $context;

        return $this;
    }

    public function check(
        string $context,
        string|array $permissions,
        ?string $locale = null,
        ?string $objectType = null,
        mixed $objectId = null,
    ): void {
        $this->record('check', $context, $permissions, $locale, $objectType, $objectId);

        if (null !== $this->failure) {
            throw $this->failure;
        }

        foreach ((array) $permissions as $permission) {
            if ($this->isDenied($context, $permission)) {
                throw new PermissionDeniedException($context, $permission, $locale);
            }
        }
    }

    public function has(
        string $context,
        string $permission,
        ?string $locale = null,
        ?string $objectType = null,
        mixed $objectId = null,
    ): bool {
        $this->record('has', $context, $permission, $locale, $objectType, $objectId);

        if (null !== $this->failure) {
            throw $this->failure;
        }

        return !$this->isDenied($context, $permission);
    }

    /**
     * Only the check() calls, without the `method` key, for asserting what a subject enforced.
     *
     * @return list<array{context: string, permissions: list<string>, locale: string|null, objectType: string|null, objectId: mixed}>
     */
    public function calls(): array
    {
        $calls = [];
        foreach ($this->calls as $call) {
            if ('check' !== $call['method']) {
                continue;
            }
            unset($call['method']);
            $calls[] = $call;
        }

        return $calls;
    }

    /**
     * Every call, check() and has() alike, each tagged with the method that made it.
     *
     * @return list<array{method: string, context: string, permissions: list<string>, locale: string|null, objectType: string|null, objectId: mixed}>
     */
    public function allCalls(): array
    {
        return $this->calls;
    }

    private function isDenied(string $context, string $permission): bool
    {
        if (null !== $this->policy) {
            return !($this->policy)($context, $permission, $this->currentLocale, $this->currentObjectType, $this->currentObjectId);
        }

        if ([] !== $this->granted || [] !== $this->grantedContexts) {
            return !\in_array($context . ':' . $permission, $this->granted, true)
                && !\in_array($context, $this->grantedContexts, true);
        }

        return $this->denyEverything
            || \in_array($context, $this->deniedContexts, true);
    }

    /**
     * Every recorded call flattened to [context, permission] pairs, in order.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function checkedPairs(): array
    {
        $pairs = [];
        foreach ($this->calls as $call) {
            foreach ($call['permissions'] as $permission) {
                $pairs[] = [$call['context'], $permission];
            }
        }

        return $pairs;
    }

    /**
     * @return list<string>
     */
    public function checkedContexts(): array
    {
        return \array_column($this->calls, 'context');
    }

    /**
     * @param string|list<string> $permissions
     */
    private function record(
        string $method,
        string $context,
        string|array $permissions,
        ?string $locale,
        ?string $objectType,
        mixed $objectId,
    ): void {
        $this->currentLocale = $locale;
        $this->currentObjectType = $objectType;
        $this->currentObjectId = $objectId;

        $this->calls[] = [
            'method' => $method,
            'context' => $context,
            'permissions' => \array_values((array) $permissions),
            'locale' => $locale,
            'objectType' => $objectType,
            'objectId' => $objectId,
        ];
    }
}
