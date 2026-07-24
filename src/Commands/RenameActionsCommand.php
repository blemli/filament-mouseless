<?php

namespace Blemli\FilamentMouseless\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Codemod that renames app-defined Filament actions to the conventional names
 * the mouseless scheme binds — `Action::make('remove')` becomes
 * `Action::make('delete')` so the record picks up the delete shortcut.
 *
 * Dry-run by default: it prints what would change and touches nothing until
 * --write is passed. Only the string inside ::make() is rewritten; other
 * references to the old name in the same file (getAction('remove'),
 * mountAction('remove'), tests…) are reported for manual review.
 *
 * Names the engine already recognizes as aliases (activityLog, timeline,
 * bookmark, star, subscribe, follow, unlock, duplicate, associate, dissociate)
 * are left alone — they need no rename to work.
 */
class RenameActionsCommand extends Command
{
    protected $signature = 'mouseless:rename-actions
        {--write : Apply the renames to the files (default is a dry run)}
        {--path=* : Directories to scan (defaults to mouseless.scan_paths)}';

    protected $description = 'Rename app Filament actions to the conventional names the mouseless shortcut scheme binds.';

    /**
     * Conservative synonym → canonical map. Extend or override per app via
     * config('mouseless.rename_map').
     */
    protected const RENAME_MAP = [
        // crud
        'remove' => 'delete',
        'destroy' => 'delete',
        'add' => 'create',
        'new' => 'create',
        'update' => 'edit',
        'modify' => 'edit',
        'show' => 'view',
        'copy' => 'replicate',
        'clone' => 'replicate',
        // record extras
        'activity' => 'history',
        'annotate' => 'comment',
        'note' => 'comment',
        'accept' => 'approve',
        'decline' => 'reject',
        'deny' => 'reject',
        'refuse' => 'reject',
        'combine' => 'merge',
        'divide' => 'split',
        'separate' => 'split',
        'like' => 'favorite',
        'observe' => 'watch',
        'protect' => 'lock',
        'forward' => 'share',
    ];

    public function handle(): int
    {
        $paths = $this->option('path') ?: (array) config('mouseless.scan_paths', []);
        $paths = array_values(array_filter($paths, fn ($p): bool => is_string($p) && is_dir($p)));

        if ($paths === []) {
            $this->warn('No scan paths found — set mouseless.scan_paths or pass --path.');

            return self::SUCCESS;
        }

        $map = array_merge(self::RENAME_MAP, (array) config('mouseless.rename_map', []));
        $write = (bool) $this->option('write');

        /** @var array<int, array{file: string, line: int, old: string, new: string}> $renames */
        $renames = [];
        /** @var array<int, string> $warnings */
        $warnings = [];

        foreach ($paths as $dir) {
            foreach (File::allFiles($dir) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $this->processFile((string) $file->getRealPath(), $map, $write, $renames, $warnings);
            }
        }

        if ($renames === []) {
            $this->info('All action names already fit the scheme — nothing to rename.');

            return self::SUCCESS;
        }

        $this->table(
            ['File', 'Line', 'Rename'],
            array_map(fn (array $r): array => [
                str_replace(base_path() . '/', '', $r['file']),
                $r['line'],
                "{$r['old']} → {$r['new']}",
            ], $renames),
        );

        foreach ($warnings as $warning) {
            $this->warn($warning);
        }

        $this->newLine();
        $this->info($write
            ? count($renames) . ' action(s) renamed.'
            : count($renames) . ' rename(s) planned — run again with --write to apply.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $map
     * @param  array<int, array{file: string, line: int, old: string, new: string}>  $renames
     * @param  array<int, string>  $warnings
     */
    protected function processFile(string $path, array $map, bool $write, array &$renames, array &$warnings): void
    {
        $source = File::get($path);
        $found = [];

        $updated = preg_replace_callback(
            '/\b([A-Za-z_\\\\]*Action)::make\(\s*([\'"])([A-Za-z0-9_.-]+)\2/',
            function (array $m) use ($map, $source, &$found): string {
                $old = $m[3];
                $new = $map[$old] ?? null;
                if ($new === null || $new === $old) {
                    return $m[0];
                }

                $line = substr_count(substr($source, 0, strpos($source, $m[0]) ?: 0), "\n") + 1;
                $found[] = ['line' => $line, 'old' => $old, 'new' => $new];

                return "{$m[1]}::make({$m[2]}{$new}{$m[2]}";
            },
            $source,
        );

        if ($found === [] || $updated === null) {
            return;
        }

        foreach ($found as $f) {
            $renames[] = ['file' => $path, ...$f];

            // The rename only touches ::make() — flag any other quoted use of
            // the old name (getAction('remove'), mountAction('remove'), …).
            $others = preg_match_all('/[\'"]' . preg_quote($f['old'], '/') . '[\'"]/', $updated);
            if ($others > 0) {
                $warnings[] = "{$path}: {$others} other reference(s) to '{$f['old']}' left untouched — review manually.";
            }
        }

        if ($write) {
            File::put($path, $updated);
        }
    }
}
