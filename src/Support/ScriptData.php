<?php

namespace Blemli\FilamentMouseless\Support;

use Blemli\FilamentMouseless\Filament\Pages\MyShortcuts;
use Blemli\FilamentMouseless\FilamentMouselessPlugin;
use Blemli\FilamentMouseless\Models\Nudge;
use Blemli\FilamentMouseless\Services\BindingResolver;
use Blemli\FilamentMouseless\Services\CustomActionRegistry;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Schema;
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
            'reserved' => Keys::reservedKeys(),
            'listModeIgnore' => (array) config('mouseless.list_mode.ignore_in', []),
            'debug' => (bool) config('mouseless.debug', false),
            'underlines' => FilamentMouselessPlugin::underlinesEnabled(),
            'hints' => FilamentMouselessPlugin::hintsEnabled(),
            'softProhibitions' => FilamentMouselessPlugin::prohibitionsAreSoft(),
            'escapeTo' => FilamentMouselessPlugin::escapeTarget(),
            'teach' => $this->teach(),
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
                'copied' => __('filament-mouseless::mouseless.table.export.copied'),
            ],
        ];
    }

    /**
     * The teach layer's payload: per-action nudge state (backoff deadline,
     * dismissed, learned) plus the notification strings. Null when teaching
     * is off, the user is a guest, or the nudges table hasn't been migrated
     * — the JS engine treats null as "feature absent".
     *
     * @return array<string, mixed>|null
     */
    protected function teach(): ?array
    {
        if (! FilamentMouselessPlugin::teachingEnabled() || ! auth()->check()) {
            return null;
        }

        try {
            if (! Schema::hasTable('mouseless_nudges')) {
                return null;
            }

            // ->deferDays(X): newcomers get X quiet days before any teaching.
            $deferDays = FilamentMouselessPlugin::teachDeferredDays();
            $createdAt = auth()->user()?->created_at;
            if ($deferDays > 0 && $createdAt && $createdAt->addDays($deferDays)->isFuture()) {
                return null;
            }

            $userId = (int) auth()->id();

            return [
                'muted' => Nudge::isMuted($userId),
                'states' => Nudge::statesFor($userId),
                'shortcutsUrl' => $this->shortcutsUrl(),
                'strings' => [
                    'title' => __('filament-mouseless::mouseless.teach.title'),
                    'body' => __('filament-mouseless::mouseless.teach.body'),
                    'change' => __('filament-mouseless::mouseless.teach.change'),
                    'dismiss' => __('filament-mouseless::mouseless.teach.dismiss'),
                    'mute' => __('filament-mouseless::mouseless.teach.mute'),
                ],
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /** The my-shortcuts page URL ("change shortcut" deep-link), when registered. */
    protected function shortcutsUrl(): ?string
    {
        try {
            return MyShortcuts::getUrl();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The panel's home URL — where Filament sends a user after login. Falls
     * back to the panel root when no explicit home URL is configured.
     */
    protected function homeUrl(): ?string
    {
        try {
            return Filament::getHomeUrl();
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
