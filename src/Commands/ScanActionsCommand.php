<?php

namespace Blemli\FilamentMouseless\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Static scan for custom Filament actions that carry ->keyBindings(), writing
 * the result to the mouseless manifest (config/mouseless/actions.php).
 *
 * The scan is intentionally a lightweight source parse — it never boots the
 * app's pages (Edit/View pages need a record to mount) and so cannot evaluate
 * closures. Dynamic ->keyBindings(fn () => …) are recorded with a null combo
 * and a warning; the live render-time discovery fills the gap when a user
 * actually visits the page.
 */
class ScanActionsCommand extends Command
{
    protected $signature = 'mouseless:scan {--path=* : Directories to scan (defaults to mouseless.scan_paths)}';

    protected $description = 'Scan for custom Filament actions with ->keyBindings() and refresh the mouseless manifest.';

    /** Action names already represented by core crud.* / list.* ids. */
    protected const CORE_ACTION_NAMES = [
        'create', 'edit', 'view', 'delete', 'save', 'cancel',
        'replicate', 'restore', 'forceDelete',
    ];

    public function handle(): int
    {
        $paths = $this->option('path') ?: (array) config('mouseless.scan_paths', []);
        $paths = array_values(array_filter($paths, fn ($p): bool => is_string($p) && is_dir($p)));

        /** @var array<string, array<string, mixed>> $scanned */
        $scanned = [];
        /** @var array<int, array{0: string, 1: string}> $dynamic */
        $dynamic = [];

        foreach ($paths as $dir) {
            foreach (File::allFiles($dir) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $this->scanFile((string) $file->getRealPath(), $scanned, $dynamic);
            }
        }

        $written = $this->writeManifest($scanned);

        $this->report($scanned, $dynamic, $written);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array<string, mixed>>  $scanned
     * @param  array<int, array{0: string, 1: string}>  $dynamic
     */
    protected function scanFile(string $path, array &$scanned, array &$dynamic): void
    {
        $contents = @file_get_contents($path);
        if ($contents === false || ! str_contains($contents, 'keyBindings')) {
            return;
        }

        $fqcn = $this->fullyQualifiedClassName($contents) ?? $path;
        $usesTrait = str_contains($contents, 'MouselessKeyBindings');

        preg_match_all('/make\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $contents, $makes, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        preg_match_all('/keyBindings\(/', $contents, $bindings, PREG_OFFSET_CAPTURE);

        foreach ($bindings[0] as [$match, $offset]) {
            $name = $this->nearestPrecedingMake($makes, $offset);
            if ($name === null || in_array($name, self::CORE_ACTION_NAMES, true)) {
                continue;
            }

            $combos = $this->extractCombos($contents, $offset + strlen('keyBindings('));
            if ($combos === null) {
                $dynamic[] = [$name, $fqcn];
            }

            $scanned['custom.' . $name] = [
                'label' => Str::headline($name),
                'keyBindings' => $combos,
                'managed' => $usesTrait,
                'source' => $fqcn,
                'scanned' => true,
            ];
        }
    }

    /**
     * Name of the make('…') call closest before the given offset (i.e. the head
     * of the same fluent chain as this keyBindings() call).
     *
     * @param  array<int, array<int, array{0: string, 1: int}>>  $makes
     */
    protected function nearestPrecedingMake(array $makes, int $offset): ?string
    {
        $name = null;
        $best = -1;

        foreach ($makes as $make) {
            $makeOffset = $make[0][1];
            if ($makeOffset < $offset && $makeOffset > $best) {
                $best = $makeOffset;
                $name = $make[1][0];
            }
        }

        return $name;
    }

    /**
     * Pull literal combos out of a keyBindings() argument. Returns null when the
     * argument holds no string literals (closure / variable / dynamic).
     *
     * @return array<int, string>|null
     */
    protected function extractCombos(string $contents, int $start): ?array
    {
        $end = strpos($contents, ')', $start);
        if ($end === false) {
            return null;
        }

        $argument = substr($contents, $start, $end - $start);

        if (preg_match_all('/[\'"]([^\'"]+)[\'"]/', $argument, $matches) === 0) {
            return null;
        }

        $combos = array_values(array_filter(
            $matches[1],
            fn (string $combo): bool => trim($combo) !== '',
        ));

        return $combos === [] ? null : $combos;
    }

    protected function fullyQualifiedClassName(string $contents): ?string
    {
        if (preg_match('/\bclass\s+(\w+)/', $contents, $classMatch) !== 1) {
            return null;
        }

        $class = $classMatch[1];

        if (preg_match('/namespace\s+([^;]+);/', $contents, $nsMatch) === 1) {
            return trim($nsMatch[1]) . '\\' . $class;
        }

        return $class;
    }

    /**
     * Merge scanned entries with the existing manifest, preserving manual
     * (scanned => false) entries, and write it back.
     *
     * @param  array<string, array<string, mixed>>  $scanned
     */
    protected function writeManifest(array $scanned): bool
    {
        $path = config('mouseless.custom_actions_path');
        if (! is_string($path)) {
            return false;
        }

        $existing = [];
        if (is_file($path)) {
            try {
                $existing = (array) (require $path);
            } catch (\Throwable) {
                $existing = [];
            }
        }

        // Manual entries (and any id a developer hand-added) survive re-scans
        // and take precedence over a scanned entry with the same id.
        $manual = array_filter(
            $existing,
            fn ($entry): bool => is_array($entry) && ($entry['scanned'] ?? false) === false,
        );

        $merged = $manual;
        foreach ($scanned as $id => $entry) {
            if (! array_key_exists($id, $manual)) {
                $merged[$id] = $entry;
            }
        }

        if ($merged === [] && ! is_file($path)) {
            return false;
        }

        ksort($merged);

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $this->render($merged));

        return true;
    }

    /**
     * @param  array<string, array<string, mixed>>  $entries
     */
    protected function render(array $entries): string
    {
        $lines = [
            '<?php',
            '',
            '// Custom action shortcuts for filament-mouseless.',
            '// Entries with \'scanned\' => true are rewritten by `php artisan mouseless:scan`.',
            '// Add your own with \'scanned\' => false and they will be kept across re-scans.',
            '',
            'return [',
        ];

        foreach ($entries as $id => $entry) {
            $lines[] = '    ' . $this->phpString((string) $id) . ' => [';
            $lines[] = '        \'label\' => ' . (isset($entry['label']) ? $this->phpString((string) $entry['label']) : 'null') . ',';
            $lines[] = '        \'keyBindings\' => ' . $this->phpCombos($entry['keyBindings'] ?? null) . ',';
            $lines[] = '        \'managed\' => ' . (($entry['managed'] ?? false) ? 'true' : 'false') . ',';
            $lines[] = '        \'source\' => ' . (isset($entry['source']) ? $this->phpString((string) $entry['source']) : 'null') . ',';
            $lines[] = '        \'scanned\' => ' . (($entry['scanned'] ?? false) ? 'true' : 'false') . ',';
            $lines[] = '    ],';
        }

        $lines[] = '];';
        $lines[] = '';

        return implode("\n", $lines);
    }

    protected function phpString(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    /**
     * @param  array<int, string>|null  $combos
     */
    protected function phpCombos(?array $combos): string
    {
        if ($combos === null || $combos === []) {
            return 'null';
        }

        return '[' . implode(', ', array_map($this->phpString(...), $combos)) . ']';
    }

    /**
     * @param  array<string, array<string, mixed>>  $scanned
     * @param  array<int, array{0: string, 1: string}>  $dynamic
     */
    protected function report(array $scanned, array $dynamic, bool $written): void
    {
        if ($scanned === []) {
            $this->info('No custom actions with ->keyBindings() found.');

            return;
        }

        $rows = [];
        foreach ($scanned as $id => $entry) {
            $rows[] = [
                $id,
                $entry['keyBindings'] === null ? '— (dynamic)' : implode(', ', $entry['keyBindings']),
                ($entry['managed'] ?? false) ? 'managed' : 'read-only',
                $entry['source'] ?? '',
            ];
        }

        $this->table(['Action', 'Keys', 'Mode', 'Source'], $rows);

        if ($written) {
            $this->info('Wrote ' . count($scanned) . ' action(s) to ' . config('mouseless.custom_actions_path') . '.');
        }

        foreach ($dynamic as [$name, $source]) {
            $this->warn("Could not resolve keys for '{$name}' in {$source} — dynamic ->keyBindings(). It will be discovered when the page is visited.");
        }

        $readOnly = array_filter($scanned, fn (array $entry): bool => ! ($entry['managed'] ?? false));
        if ($readOnly !== []) {
            $this->warn(count($readOnly) . ' action(s) use ->keyBindings() without the MouselessKeyBindings trait — shown read-only. Add the trait to let users rebind them.');
        }
    }
}
