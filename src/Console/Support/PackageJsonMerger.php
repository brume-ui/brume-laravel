<?php

namespace Brume\Console\Support;

/**
 * Decides how Brume's frontend dependencies fit into a project's package.json.
 *
 * Existing specs are never changed unless they cannot work with Brume and $upgrade is set:
 * - missing package            → added at Brume's exact version, in Brume's section
 * - spec admits Brume's version → left alone (same major, or 0.minor for 0.x; >= at or below)
 * - spec is older              → conflict; with $upgrade raised to Brume's version, keeping ^ / ~
 * - spec is newer              → conflict; never downgraded, even with $upgrade
 * - spec cannot be parsed      → left alone and reported as unverified
 */
final class PackageJsonMerger
{
    private const SECTIONS = ['dependencies', 'devDependencies'];

    /**
     * @param  array<string, mixed>  $host  the project's decoded package.json
     * @param  array<string, mixed>  $ours  Brume's frontend/package.json
     */
    public function merge(array $host, array $ours, bool $upgrade = false): MergeResult
    {
        $added = $raised = $conflicts = $unverified = [];

        foreach (self::SECTIONS as $section) {
            foreach ($ours[$section] ?? [] as $name => $needs) {
                [$existingSection, $existing] = $this->find($host, $name);

                if ($existing === null) {
                    $added[] = ['section' => $section, 'name' => $name, 'version' => $needs];

                    continue;
                }

                $spec = self::parse($existing);

                if ($spec === null) {
                    $unverified[] = ['name' => $name, 'host' => $existing, 'needs' => $needs];
                } elseif (! self::admits($spec, $needs)) {
                    $newer = version_compare($spec['version'], $needs, '>');

                    if ($upgrade && ! $newer) {
                        $raised[] = ['section' => $existingSection, 'name' => $name, 'from' => $existing, 'to' => $spec['operator'].$needs];
                    } else {
                        $conflicts[] = ['name' => $name, 'host' => $existing, 'needs' => $needs, 'newer' => $newer];
                    }
                }
            }
        }

        return new MergeResult($added, $raised, $conflicts, $unverified);
    }

    /** @return array{0: ?string, 1: ?string} */
    private function find(array $host, string $name): array
    {
        foreach (self::SECTIONS as $section) {
            $spec = $host[$section][$name] ?? null;

            if (is_string($spec)) {
                return [$section, $spec];
            }
        }

        return [null, null];
    }

    /** @return array{operator: string, version: string}|null */
    public static function parse(string $spec): ?array
    {
        if (! preg_match('/^\s*(\^|~|>=)?\s*v?(\d+(?:\.\d+){0,2}(?:-[0-9A-Za-z.-]+)?)\s*$/', $spec, $match)) {
            return null;
        }

        return ['operator' => $match[1], 'version' => $match[2]];
    }

    /** @param  array{operator: string, version: string}  $spec */
    private static function admits(array $spec, string $needs): bool
    {
        if ($spec['operator'] === '>=') {
            return version_compare($spec['version'], $needs, '<=');
        }

        return self::compatibilityLine($spec['version']) === self::compatibilityLine($needs);
    }

    /** "19.2.0" → "19", "0.1.0-alpha.0" → "0.1": breaking changes happen across these lines. */
    private static function compatibilityLine(string $version): string
    {
        [$major, $minor] = array_map('intval', explode('.', $version.'.0.0'));

        return $major > 0 ? (string) $major : "0.{$minor}";
    }
}
