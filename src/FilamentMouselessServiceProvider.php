<?php

namespace Blemli\FilamentMouseless;

use Blemli\FilamentMouseless\Commands\ScanActionsCommand;
use Blemli\FilamentMouseless\Filament\Widgets\PresetSelector;
use Blemli\FilamentMouseless\Livewire\GotoPalette;
use Blemli\FilamentMouseless\Livewire\HelpOverlay;
use Blemli\FilamentMouseless\Services\ActionDiscovery;
use Blemli\FilamentMouseless\Services\BindingResolver;
use Blemli\FilamentMouseless\Services\CustomActionRegistry;
use Blemli\FilamentMouseless\Services\PresetRegistry;
use Blemli\FilamentMouseless\Support\ScriptData;
use Blemli\FilamentMouseless\Support\Shield;
use Blemli\FilamentMouseless\Testing\TestsFilamentMouseless;
use Filament\Facades\Filament;
use Filament\Support\Assets\Asset;
use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Facades\FilamentIcon;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Symfony\Component\Console\Input\InputOption;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class FilamentMouselessServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-mouseless';

    public static string $viewNamespace = 'filament-mouseless';

    public function configurePackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasCommand(ScanActionsCommand::class)
            ->hasInstallCommand(function (InstallCommand $command) {
                $command->setName('mouseless:install');
                $command->addOption('stateless', null, InputOption::VALUE_NONE, 'Install in stateless mode (skip the per-user shortcuts migrations)');
                $command
                    ->publishConfigFile()
                    ->startWith(function (InstallCommand $command) {
                        intro('filament-mouseless');

                        if ($command->option('stateless')) {
                            info('Stateless install (--stateless): skipping the per-user shortcuts migrations.');

                            return;
                        }

                        if ($this->mouselessTablesPresent()) {
                            info('Mouseless tables already present — skipping the migration step.');

                            return;
                        }

                        $mode = select(
                            label: 'Allow each user to customize their own shortcuts?',
                            options: [
                                'stateful' => 'Yes — publish and run the package migrations',
                                'stateless' => 'No — run in stateless mode (no migrations)',
                            ],
                            default: 'stateful',
                            hint: 'You can switch later — stateless just skips the package migrations.',
                        );

                        if ($mode === 'stateless') {
                            info('Stateless mode selected — no migrations will be published.');

                            return;
                        }

                        spin(
                            fn () => $command->callSilent('vendor:publish', ['--tag' => 'filament-mouseless-migrations']),
                            'Publishing migrations...',
                        );
                        $command->call('migrate');
                    })
                    ->endWith(function (InstallCommand $command) {
                        $stateless = (bool) $command->option('stateless') || ! $this->mouselessTablesPresent();

                        $strict = false;
                        if (Shield::isInstalled()) {
                            $strict = confirm(
                                label: 'Filament Shield detected — gate mouseless behind its permissions (strict mode)?',
                                default: false,
                                hint: 'Permissive by default: every user gets shortcuts until you opt in.',
                            );
                        }

                        $modifiers = array_values(array_filter([
                            $stateless ? 'stateless' : null,
                            $strict ? 'strictPermissions' : null,
                        ]));

                        $this->installPluginRegistration($modifiers);

                        if ($stateless) {
                            note(implode("\n", [
                                'Running stateless — no per-user shortcut storage. To enable it later:',
                                '',
                                '  php artisan vendor:publish --tag=filament-mouseless-migrations',
                                '  php artisan migrate',
                                '',
                                '…then remove the ->stateless() call from your panel provider.',
                            ]));
                        }

                        if ($strict) {
                            $this->installShieldFollowUps($command);
                        } elseif (Shield::isInstalled()) {
                            info('Shield stays dormant — everyone gets shortcuts. Opt in later with ->strictPermissions().');
                        }

                        try {
                            spin(fn () => $command->callSilent('filament:assets'), 'Publishing Filament assets...');
                        } catch (\Throwable) {
                            warning("Couldn't run filament:assets — run it manually so the mouseless JS/CSS get published.");
                        }

                        try {
                            spin(fn () => $command->callSilent('mouseless:scan'), 'Scanning for custom action shortcuts...');
                        } catch (\Throwable) {
                            // Non-fatal: the scan can be run later with `php artisan mouseless:scan`.
                        }

                        note(implode("\n", array_filter([
                            'Press ? on any Filament page to open the shortcut overlay.',
                            'Esc closes modals and menus, Option+↑ goes home.',
                            $stateless ? null : 'Users can customize their shortcuts at /my-shortcuts.',
                            'Tweak defaults (reserved keys, list-mode ignores, debug) in config/mouseless.php.',
                            'Docs: https://github.com/blemli/filament-mouseless',
                        ])));

                        outro('mouseless is ready — enjoy the keyboard.');
                    });
            });

        if (file_exists($package->basePath('/../config/mouseless.php'))) {
            $package->hasConfigFile('mouseless');
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
        // Scoped (not singleton): the memo caches inside must reset per
        // request under Octane.
        $this->app->scoped(PresetRegistry::class);
        $this->app->scoped(BindingResolver::class);
        $this->app->scoped(CustomActionRegistry::class);
        $this->app->scoped(ActionDiscovery::class);
    }

    public function packageBooted(): void
    {
        $this->registerShieldCustomPermission();

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
        Livewire::component('mouseless-goto-palette', GotoPalette::class);
        Livewire::component('mouseless-preset-selector', PresetSelector::class);

        // Discover (and, on opted-in pages, take over) custom actions' key
        // bindings as each Filament page renders. The render event fires before
        // the page's blade view is rendered, so disarming a native binding here
        // prevents Filament from emitting its x-mousetrap attribute.
        Livewire::listen('render', function (object $component): void {
            app(ActionDiscovery::class)->discover($component);
        });

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

    protected function mouselessTablesPresent(): bool
    {
        try {
            return Schema::hasTable('mouseless_user_settings');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * IDs of every Filament panel that already has the mouseless plugin
     * registered. Used by the installer to skip the "register me in your panel
     * provider" snippet on subsequent runs.
     *
     * @return array<int, string>
     */
    protected function panelsWithPluginRegistered(): array
    {
        $panels = [];

        try {
            foreach (Filament::getPanels() as $panel) {
                try {
                    $panel->getPlugin('filament-mouseless');
                    $panels[] = $panel->getId();
                } catch (\Throwable) {
                    // Plugin not on this panel; keep scanning.
                }
            }
        } catch (\Throwable) {
            // Filament not booted, or no panels registered yet.
        }

        return $panels;
    }

    /**
     * Installer step: get `FilamentMouselessPlugin::make()` into the host
     * app's panel provider(s) — by editing the file(s) after consent, or by
     * printing the snippet when that's declined or fails.
     *
     * @param  array<int, string>  $modifiers  method names to chain, e.g. 'stateless'
     */
    protected function installPluginRegistration(array $modifiers): void
    {
        $registeredOn = $this->panelsWithPluginRegistered();

        if ($registeredOn !== []) {
            info('Plugin already registered on panel: ' . implode(', ', $registeredOn));

            if ($modifiers !== []) {
                note('Make sure your existing FilamentMouselessPlugin::make() call chains ->' . implode('()->', $modifiers) . '().');
            }

            return;
        }

        $candidates = $this->panelProviderCandidates();
        $labels = [];
        foreach ($candidates as $file) {
            $labels[$file] = ltrim(str_replace(base_path(), '', $file), '/');
        }

        $chosen = [];
        if (count($candidates) === 1) {
            $file = $candidates[0];
            if (confirm("Register the plugin in {$labels[$file]} now?", true)) {
                $chosen = [$file];
            }
        } elseif (count($candidates) > 1) {
            $chosen = multiselect(
                label: 'Register the plugin in which panel providers?',
                options: $labels,
                default: array_keys($labels),
            );
        }

        $written = [];
        foreach ($chosen as $file) {
            if ($this->registerPluginInProviderFile($file, $modifiers)) {
                $written[] = $labels[$file];
            } else {
                warning("Couldn't modify {$labels[$file]} automatically — register the plugin manually.");
            }
        }

        if ($written !== []) {
            info('Registered FilamentMouselessPlugin in: ' . implode(', ', $written));
        }

        if (count($written) === count($chosen) && $written !== []) {
            return;
        }

        warning('One more step: register the plugin in your Panel provider.');

        $chain = ['            FilamentMouselessPlugin::make()'];
        foreach ($modifiers as $modifier) {
            $chain[] = '                ->' . $modifier . '()';
        }
        $chain[count($chain) - 1] .= ',';

        note(implode("\n", [
            'use Blemli\\FilamentMouseless\\FilamentMouselessPlugin;',
            '',
            'public function panel(Panel $panel): Panel',
            '{',
            '    return $panel',
            '        // ...',
            '        ->plugins([',
            ...$chain,
            '        ]);',
            '}',
        ]));
    }

    /** Installer step: wire up Shield when the user opted into strict mode. */
    protected function installShieldFollowUps(InstallCommand $command): void
    {
        $configPath = config_path('filament-shield.php');

        if (is_file($configPath)) {
            $contents = (string) file_get_contents($configPath);

            if (str_contains($contents, "'custom_permissions' => false") && confirm(
                label: 'Enable the custom-permissions tab in config/filament-shield.php?',
                default: true,
                hint: 'Shows the MouselessUse master permission in the role-edit UI.',
            )) {
                file_put_contents($configPath, str_replace(
                    "'custom_permissions' => false",
                    "'custom_permissions' => true",
                    $contents,
                ));
                info('Enabled shield_resource.tabs.custom_permissions.');
            }
        } else {
            note(implode("\n", [
                "Shield's config isn't published. To surface MouselessUse in the role-edit UI:",
                '',
                '  php artisan vendor:publish --tag=filament-shield-config',
                '',
                "  …then set 'shield_resource' => ['tabs' => ['custom_permissions' => true]].",
            ]));
        }

        if (confirm('Run shield:generate --all now to seed the permission records?', true)) {
            $command->call('shield:generate', ['--all' => true]);
        }

        table(
            ['Permission', 'Gates'],
            [
                ['MouselessUse', 'Master switch: link, overlay, boot script, page access'],
                ['View:MyShortcuts', 'The per-user customization page'],
                ['View:MouselessSettings', 'The admin settings page'],
            ],
        );

        note(implode("\n", [
            'Grant the permissions to your roles — via the role-edit UI, or in tinker:',
            '',
            "  Role::firstWhere('name', 'panel_user')",
            "      ?->givePermissionTo(['MouselessUse', 'View:MyShortcuts']);",
        ]));
    }

    /**
     * Panel-provider files in the host app that don't have the plugin yet.
     *
     * @return array<int, string>
     */
    protected function panelProviderCandidates(): array
    {
        $files = array_unique(array_merge(
            glob(app_path('Providers/Filament/*.php')) ?: [],
            glob(app_path('Providers/*PanelProvider.php')) ?: [],
        ));

        return array_values(array_filter(
            $files,
            fn (string $file): bool => str_ends_with($file, 'PanelProvider.php')
                && ! str_contains((string) file_get_contents($file), 'FilamentMouselessPlugin'),
        ));
    }

    /**
     * Insert the plugin import and a `FilamentMouselessPlugin::make()` call
     * into a panel-provider file. Returns false (without writing) when the
     * file's structure isn't recognized — callers fall back to the snippet.
     *
     * @param  array<int, string>  $modifiers  method names to chain, e.g. 'stateless'
     */
    protected function registerPluginInProviderFile(string $path, array $modifiers): bool
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            return false;
        }

        $import = 'use Blemli\\FilamentMouseless\\FilamentMouselessPlugin;';

        if (! str_contains($contents, $import)) {
            if (preg_match_all('/^use [A-Za-z0-9_\\\\]+;/m', $contents, $matches, PREG_OFFSET_CAPTURE) > 0) {
                $last = end($matches[0]);
                $contents = substr_replace($contents, "\n" . $import, $last[1] + strlen($last[0]), 0);
            } elseif (preg_match('/^namespace [^;]+;/m', $contents, $match, PREG_OFFSET_CAPTURE)) {
                $contents = substr_replace($contents, "\n\n" . $import, $match[0][1] + strlen($match[0][0]), 0);
            } else {
                return false;
            }
        }

        if (preg_match('/^([ \t]*)->plugins\(\[/m', $contents, $match, PREG_OFFSET_CAPTURE)) {
            $indent = $match[1][0];
            $afterBracket = $match[0][1] + strlen($match[0][0]);

            $newlinePos = strpos($contents, "\n", $afterBracket);
            $restOfLine = $newlinePos === false ? '' : substr($contents, $afterBracket, $newlinePos - $afterBracket);

            if (trim($restOfLine) === '') {
                $itemIndent = $indent . '    ';
                $code = $itemIndent . 'FilamentMouselessPlugin::make()';
                foreach ($modifiers as $modifier) {
                    $code .= "\n" . $itemIndent . '    ->' . $modifier . '()';
                }
                $insert = "\n" . $code . ',';
            } else {
                // Inline array like ->plugins([Foo::make()]) — keep it inline.
                $insert = 'FilamentMouselessPlugin::make()';
                foreach ($modifiers as $modifier) {
                    $insert .= '->' . $modifier . '()';
                }
                $insert .= ', ';
            }

            return file_put_contents($path, substr_replace($contents, $insert, $afterBracket, 0)) !== false;
        }

        if (preg_match('/^([ \t]*)return \$panel$/m', $contents, $match, PREG_OFFSET_CAPTURE)) {
            $indent = $match[1][0] . '    ';

            $code = $indent . '->plugins([' . "\n" . $indent . '    FilamentMouselessPlugin::make()';
            foreach ($modifiers as $modifier) {
                $code .= "\n" . $indent . '        ->' . $modifier . '()';
            }
            $code .= ',' . "\n" . $indent . '])';

            $insertAt = $match[0][1] + strlen($match[0][0]);

            return file_put_contents($path, substr_replace($contents, "\n" . $code, $insertAt, 0)) !== false;
        }

        return false;
    }

    /**
     * Register the `mouseless_use` master-switch permission with Shield so
     * that `shield:generate` seeds it and the role-edit UI renders its label.
     * Idempotent and a no-op when Shield isn't installed.
     */
    protected function registerShieldCustomPermission(): void
    {
        if (! Shield::isInstalled()) {
            return;
        }

        $existing = (array) config('filament-shield.custom_permissions', []);

        foreach ($existing as $key => $value) {
            if ((is_int($key) ? $value : $key) === Shield::CUSTOM_PERMISSION) {
                return;
            }
        }

        $existing[Shield::CUSTOM_PERMISSION] = Shield::permissionLabel();
        config(['filament-shield.custom_permissions' => $existing]);
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
     * The mouseless payload is resolved lazily (see {@see ScriptData}): the
     * binding map and discovered custom actions are only final once the page
     * has rendered, which happens after this boot-time registration but before
     * `@filamentScripts` serializes the data.
     *
     * @return array<string, mixed>
     */
    protected function getScriptData(): array
    {
        return [
            'mouseless' => new ScriptData($this->getActionLabels()),
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
            'create' => ['New', 'Neu', 'Anlegen', 'Create'],
            'edit' => ['Edit', 'Bearbeiten'],
            'delete' => ['Delete', 'Löschen'],
            'save' => ['Save', 'Speichern'],
            'view' => ['View', 'Anzeigen'],
            'replicate' => ['Replicate', 'Duplicate', 'Duplizieren', 'Kopieren'],
            'export' => ['Export', 'Exportieren'],
            'import' => ['Import', 'Importieren'],
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
