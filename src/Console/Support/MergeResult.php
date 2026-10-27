<?php

namespace Brume\Console\Support;

/**
 * What PackageJsonMerger decided. Nothing here touches files; the caller applies $added and
 * $raised to the project's package.json.
 */
final class MergeResult
{
    /**
     * @param  list<array{section: string, name: string, version: string}>  $added  missing packages to add
     * @param  list<array{section: string, name: string, from: string, to: string}>  $raised  older specs raised with --upgrade-deps
     * @param  list<array{name: string, host: string, needs: string, newer: bool}>  $conflicts  specs that do not admit Brume's version
     * @param  list<array{name: string, host: string, needs: string}>  $unverified  specs that cannot be compared (tags, workspace:, ranges with ||)
     */
    public function __construct(
        public readonly array $added = [],
        public readonly array $raised = [],
        public readonly array $conflicts = [],
        public readonly array $unverified = [],
    ) {}

    public function changesPackageJson(): bool
    {
        return $this->added !== [] || $this->raised !== [];
    }
}
