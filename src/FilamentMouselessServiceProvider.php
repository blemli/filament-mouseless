<?php

namespace Blemli\FilamentMouseless;

use Blemli\FilamentMouseless\Commands\FilamentMouselessCommand;
use Blemli\FilamentMouseless\Livewire\HelpOverlay;
use Blemli\FilamentMouseless\Livewire\ShortcutsProfileTab;
use Blemli\FilamentMouseless\Services\BindingResolver;
use Blemli\FilamentMouseless\Services\PresetRegistry;
use Blemli\FilamentMouseless\Testing\TestsFilamentMouseless;
use Filament\Support\Assets\Asset;
use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Facades\FilamentIcon;
use Illuminate\Filesystem\Filesystem;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FilamentMouselessServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-mouseless';

    public static string $viewNamespace = 'filament-mouseless';

    public function configurePackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasCommands($this->getCommands())
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->askToRunMigrations()
                    ->endWith(function (InstallCommand $command) {
                        $command->newLine();
                        $command->warn('One more step: register the plugin in your Panel provider.');
                        $command->line('');
                        $command->line('  use Blemli\\FilamentMouseless\\FilamentMouselessPlugin;');
                        $command->line('');
                        $command->line('  public function panel(Panel $panel): Panel');
                        $command->line('  {');
                        $command->line('      return $panel');
                        $command->line('          // ...');
                        $command->line('          ->plugins([');
                        $command->line('              FilamentMouselessPlugin::make(),');
                        $command->line('          ]);');
                        $command->line('  }');
                        $command->newLine();
                        $command->info('Then press ? on any Filament page to see the shortcut overlay.');
                    });
            });

        $configFileName = $package->shortName();

        if (file_exists($package->basePath("/../config/{$configFileName}.php"))) {
            $package->hasConfigFile();
        }

        if (file_exists($package->basePath('/../database/migrations'))) {
            $package->hasMigrations($this->getMigrations());
        }

        if (file_exists($package->basePath('/../resources/lang'))) {
            $package->hasTranslations();
        }

        if (file_exists($package->basePath('/../resources/views'))) {
            $package->hasViews(static::$viewNamespace);
        }
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(PresetRegistry::class);
        $this->app->singleton(BindingResolver::class);
    }

    public function packageBooted(): void
    {
        FilamentAsset::register(
            $this->getAssets(),
            $this->getAssetPackageName()
        );

        FilamentAsset::registerScriptData(
            $this->getScriptData(),
            $this->getAssetPackageName()
        );

        FilamentIcon::register($this->getIcons());

        Livewire::component('mouseless-help-overlay', HelpOverlay::class);
        Livewire::component('mouseless-shortcuts-tab', ShortcutsProfileTab::class);

        if (app()->runningInConsole()) {
            foreach (app(Filesystem::class)->files(__DIR__ . '/../stubs/') as $file) {
                $this->publishes([
                    $file->getRealPath() => base_path("stubs/filament-mouseless/{$file->getFilename()}"),
                ], 'filament-mouseless-stubs');
            }
        }

        Testable::mixin(new TestsFilamentMouseless);
    }

    protected function getAssetPackageName(): ?string
    {
        return 'blemli/filament-mouseless';
    }

    /**
     * @return array<Asset>
     */
    protected function getAssets(): array
    {
        return [
            Js::make('filament-mouseless', __DIR__ . '/../resources/js/index.js'),
            Css::make('filament-mouseless', __DIR__ . '/../resources/css/mouseless.css'),
        ];
    }

    /**
     * @return array<class-string>
     */
    protected function getCommands(): array
    {
        return [
            FilamentMouselessCommand::class,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function getIcons(): array
    {
        return [
            'filament-mouseless::nav' => 'heroicon-o-cursor-arrow-ripple',
            'filament-mouseless::admin' => 'heroicon-o-cursor-arrow-ripple',
            'filament-mouseless::profile' => 'heroicon-o-cursor-arrow-ripple',
            'filament-mouseless::help' => 'heroicon-o-cursor-arrow-ripple',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getScriptData(): array
    {
        // Only meaningful when there is a current request with an authenticated user;
        // the resolver tolerates a null user and falls back to config defaults.
        try {
            $resolved = app(BindingResolver::class)->forUser(auth()->id());
        } catch (\Throwable) {
            $resolved = ['bindings' => [], 'preset' => null, 'overrides' => [], 'disabled' => []];
        }

        return [
            'mouseless' => [
                'bindings' => $resolved['bindings'],
                'reserved' => (array) config('mouseless.reserved_keys', []),
                'listModeIgnore' => (array) config('mouseless.list_mode.ignore_in', []),
                'debug' => (bool) config('mouseless.debug', false),
                'actionLabels' => $this->getActionLabels(),
                // crud.create on a non-resource page (dashboard, custom page) creates
                // a record of this resource. Slug ("categories"), path ("/admin/categories"),
                // or full create URL ("/admin/categories/create") all accepted.
                'rootResource' => config('app.root_resource'),
                'strings' => [
                    'no_match' => __('filament-mouseless::mouseless.help.no_match'),
                ],
            ],
        ];
    }

    /**
     * Literal labels for each Filament action — used by the JS engine as a
     * last-resort text match when the button has no clean wire:click hook.
     *
     * Why: NEVER call trans('filament-actions::…') here. Laravel's translator
     * caches `loaded[namespace][group][locale] = []` on the first miss, and
     * filament-actions hasn't registered its hint yet at packageBooted time,
     * so the cached empty array later prevents filament-actions from rendering
     * its own labels (they appear as raw "filament-actions::view.single.label").
     *
     * Users on other locales can extend via mouseless.action_labels.<name> in
     * config (array of strings).
     *
     * @return array<string, array<int, string>>
     */
    protected function getActionLabels(): array
    {
        $defaults = [
            'create'    => ['New', 'Neu', 'Anlegen', 'Create'],
            'edit'      => ['Edit', 'Bearbeiten'],
            'delete'    => ['Delete', 'Löschen'],
            'save'      => ['Save', 'Speichern'],
            'view'      => ['View', 'Anzeigen'],
            'replicate' => ['Replicate', 'Duplicate', 'Duplizieren', 'Kopieren'],
            'export'    => ['Export', 'Exportieren'],
            'import'    => ['Import', 'Importieren'],
        ];

        $userLabels = (array) config('mouseless.action_labels', []);

        foreach ($defaults as $action => $list) {
            $extra = (array) ($userLabels[$action] ?? []);
            $defaults[$action] = array_values(array_unique(array_merge($list, $extra)));
        }

        return $defaults;
    }

    /**
     * @return array<string>
     */
    protected function getMigrations(): array
    {
        return [
            'create_mouseless_presets_table',
            'create_mouseless_user_settings_table',
        ];
    }
}
