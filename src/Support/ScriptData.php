<?php

namespace Blemli\FilamentMouseless\Support;

use Blemli\FilamentMouseless\Services\BindingResolver;
use Blemli\FilamentMouseless\Services\CustomActionRegistry;
use JsonSerializable;

/**
 * The `window.filamentData.mouseless` payload, resolved lazily.
 *
 * It is registered once at boot but serialized by `@filamentScripts` at the end
 * of the body — after the page's Livewire component has rendered. That ordering
 * is load-bearing: ActionDiscovery populates the CustomActionRegistry during
 * that render, so resolving the binding map here (and not at boot) is what lets
 * freshly-discovered managed actions reach the engine.
 */
class ScriptData implements JsonSerializable
{
    /** @param  array<string, array<int, string>>  $actionLabels */
    public function __construct(protected array $actionLabels) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        try {
            $resolved = app(BindingResolver::class)->forUser(auth()->id());
        } catch (\Throwable) {
            $resolved = ['bindings' => [], 'preset' => null, 'disabled' => []];
        }

        return [
            'bindings' => $resolved['bindings'],
            'reserved' => (array) config('mouseless.reserved_keys', []),
            'listModeIgnore' => (array) config('mouseless.list_mode.ignore_in', []),
            'debug' => (bool) config('mouseless.debug', false),
            'actionLabels' => $this->actionLabels,
            // The panel's post-login landing page (configured home URL, or the
            // panel root). nav.dashboard navigates here instead of a hardcoded
            // "/admin", so it works for panels on any path.
            'homeUrl' => $this->homeUrl(),
            // crud.create on a non-resource page (dashboard, custom page) creates
            // a record of this resource. Slug ("categories"), path ("/admin/categories"),
            // or full create URL ("/admin/categories/create") all accepted.
            'rootResource' => config('app.root_resource'),
            'customActions' => $this->customActions(),
            'strings' => [
                'no_match' => __('filament-mouseless::mouseless.help.no_match'),
            ],
        ];
    }

    /**
     * The panel's home URL — where Filament sends a user after login. Falls
     * back to the panel root when no explicit home URL is configured.
     */
    protected function homeUrl(): ?string
    {
        try {
            return \Filament\Facades\Filament::getHomeUrl();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Custom actions for the JS layer: availability probing in the help
     * overlay. Managed combos already live inside `bindings`.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function customActions(): array
    {
        try {
            $registry = app(CustomActionRegistry::class);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($registry->all() as $id => $meta) {
            $out[$id] = [
                'combos' => $meta['combos'],
                'managed' => $meta['managed'],
                'onPage' => $meta['onPage'],
            ];
        }

        return $out;
    }
}
