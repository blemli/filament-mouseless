<?php

namespace Blemli\FilamentMouseless\Livewire;

use Blemli\FilamentMouseless\Facades\FilamentMouseless;
use Blemli\FilamentMouseless\FilamentMouselessPlugin;
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
            [$ns] = explode('.', $actionId, 2);
            $groups[$ns][$actionId] = $key;
        }
        ksort($groups);

        $plugin = FilamentMouselessPlugin::safeGet();

        return view('filament-mouseless::livewire.help-overlay', [
            'groups' => $groups,
            'preset' => $resolved['preset'],
            'printable' => $plugin?->isCheatsheetPrintable() ?? true,
            'brandName' => filament()->getBrandName(),
            'slogan' => config('app.slogan'),
            'appVersion' => $this->appVersion(),
            'printedAt' => now()->isoFormat('L'),
        ]);
    }
}
