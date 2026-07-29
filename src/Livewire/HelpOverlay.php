<?php

namespace Blemli\FilamentMouseless\Livewire;

use Blemli\FilamentMouseless\Facades\FilamentMouseless;
use Blemli\FilamentMouseless\FilamentMouselessPlugin;
use Blemli\FilamentMouseless\Services\CustomActionRegistry;
use Blemli\FilamentMouseless\Support\ActionMeta;
use Composer\InstalledVersions;
use Livewire\Component;

class HelpOverlay extends Component
{
    /** The host app's version, or null when composer.json doesn't declare one. */
    protected function appVersion(): ?string
    {
        $version = InstalledVersions::getRootPackage()['pretty_version'] ?? null;

        // Composer reports these when the root composer.json has no version field.
        if ($version === null || $version === '1.0.0+no-version-set' || str_starts_with($version, 'dev-')) {
            return null;
        }

        return $version;
    }

    public function render()
    {
        $resolved = FilamentMouseless::forCurrentUser();

        $groups = [];
        foreach ($resolved['bindings'] as $actionId => $key) {
            // Custom actions get their own, metadata-rich section below.
            if (str_starts_with($actionId, 'custom.')) {
                continue;
            }

            [$ns] = explode('.', $actionId, 2);
            $groups[$ns][$actionId] = $key;
        }
        ksort($groups);

        // Jump mode is a gesture, not a preset binding — list it in the UI
        // group (only when the plugin activates it) so the overlay and the
        // printable cheatsheet teach the double-tap.
        if (FilamentMouselessPlugin::jumpEnabled()) {
            $groups['ui']['ui.jump'] = (string) config('mouseless.jump.chord', 'ctrl,ctrl');
        }

        $plugin = FilamentMouselessPlugin::safeGet();

        return view('filament-mouseless::livewire.help-overlay', [
            'groups' => $groups,
            'customRows' => $this->customRows($resolved['bindings']),
            // Singleton mode hides presets everywhere — the footer's
            // "source: <preset>" line would leak the managed layout's name.
            'preset' => FilamentMouselessPlugin::singletonEnabled() ? null : $resolved['preset'],
            'printable' => $plugin?->isCheatsheetPrintable() ?? true,
            'brandName' => filament()->getBrandName(),
            'slogan' => config('app.slogan'),
            'appVersion' => $this->appVersion(),
            'printedAt' => now()->isoFormat('L'),
        ]);
    }

    /**
     * Custom-action rows for the overlay. Managed actions show their effective
     * (possibly user-overridden) combo and are skipped when unbound/disabled;
     * read-only actions always show their code-defined combo. Rows carry an
     * `onPage` flag so the engine (and CSS) can grey out actions that aren't
     * available on the current page.
     *
     * @param  array<string, string>  $bindings
     * @return array<int, array<string, mixed>>
     */
    protected function customRows(array $bindings): array
    {
        try {
            $actions = app(CustomActionRegistry::class)->all();
        } catch (\Throwable) {
            return [];
        }

        $rows = [];

        foreach ($actions as $id => $meta) {
            $managed = (bool) ($meta['managed'] ?? false);

            if ($managed) {
                // Unbound or disabled managed actions drop out of the binding map.
                if (! array_key_exists($id, $bindings)) {
                    continue;
                }

                $combo = $bindings[$id];
            } else {
                $combo = $meta['combos'][0] ?? null;
            }

            if ($combo === null) {
                continue;
            }

            $rows[] = [
                'id' => $id,
                'label' => ActionMeta::label($id),
                'combo' => $combo,
                'readonly' => ! $managed,
                'onPage' => (bool) ($meta['onPage'] ?? false),
            ];
        }

        usort($rows, fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $rows;
    }
}
