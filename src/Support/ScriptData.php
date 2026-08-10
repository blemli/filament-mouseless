<?php

namespace Blemli\FilamentMouseless\Support;

use Blemli\FilamentMouseless\Filament\Pages\MyShortcuts;
use Blemli\FilamentMouseless\FilamentMouselessPlugin;
use Blemli\FilamentMouseless\Models\Nudge;
use Blemli\FilamentMouseless\Models\Statistic;
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
            $resolved = app(BindingResolver::class)->forUser(PanelAuth::id());
        } catch (\Throwable) {
            $resolved = ['bindings' => [], 'preset' => null, 'disabled' => []];
        }

        return [
            'bindings' => $resolved['bindings'],
            'reserved' => Keys::reservedKeys(),
            'listModeIgnore' => FilamentMouselessPlugin::listModeIgnoreSelectors(),
            'debug' => (bool) config('mouseless.debug', false),
            'underlines' => FilamentMouselessPlugin::underlinesEnabled(),
            'hints' => FilamentMouselessPlugin::hintsEnabled(),
            'jump' => $this->jump(),
            'softProhibitions' => FilamentMouselessPlugin::prohibitionsAreSoft(),
            'escapeTo' => FilamentMouselessPlugin::escapeTarget(),
            'teach' => $this->teach(),
            'stats' => $this->stats(),
            'actionLabels' => $this->actionLabels,
            // Translated display name per bound action ("Zeile aus-/abwählen").
            // The teach layer names the ACTION for row-scoped nudges — a row
            // element's own text is every cell concatenated, never a label.
            'actionNames' => collect(array_keys($resolved['bindings']))
                ->mapWithKeys(fn (string $id) => [$id => ActionMeta::label($id)])
                ->all(),
            // The panel's post-login landing page (configured home URL, or the
            // panel root). nav.dashboard navigates here instead of a hardcoded
            // "/admin", so it works for panels on any path.
            'homeUrl' => $this->homeUrl(),
            // nav.profile navigates here — resolved server-side so it works
            // for panels on any path (root, /admin, nested), null when the
            // my-shortcuts page isn't registered.
            'shortcutsUrl' => $this->shortcutsUrl(),
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
     * Jump-mode payload — non-null arms the double-tap detector in the JS
     * engine. Null when the plugin doesn't call ->jump().
     *
     * @return array{chord: string, timeoutMs: int, strings: array<string, string>}|null
     */
    protected function jump(): ?array
    {
        if (! FilamentMouselessPlugin::jumpEnabled()) {
            return null;
        }

        return [
            'chord' => FilamentMouselessPlugin::jumpChord(),
            'timeoutMs' => FilamentMouselessPlugin::jumpTimeoutMs(),
            'strings' => [
                'no_targets' => __('filament-mouseless::mouseless.jump.no_targets'),
                'move_hint' => __('filament-mouseless::mouseless.jump.move_hint'),
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
        if (! FilamentMouselessPlugin::teachingEnabled() || ! PanelAuth::check()) {
            return null;
        }

        try {
            if (! Schema::hasTable('mouseless_nudges')) {
                return null;
            }

            // ->deferDays(X): newcomers get X quiet days before any teaching.
            $deferDays = FilamentMouselessPlugin::teachDeferredDays();
            $createdAt = PanelAuth::user()?->created_at;
            if ($deferDays > 0 && $createdAt && $createdAt->addDays($deferDays)->isFuture()) {
                return null;
            }

            $userId = (int) PanelAuth::id();

            return [
                'muted' => Nudge::isMuted($userId),
                'helpNudge' => FilamentMouselessPlugin::cheatsheetNudgeEnabled(),
                'states' => Nudge::statesFor($userId),
                // Statistics-informed focus (empty without ->statistics()):
                // the engine spends its one-nudge-per-page on these first.
                'clickPriority' => $this->teachClickPriority($userId),
                'shortcutsUrl' => $this->shortcutsUrl(),
                'strings' => collect([
                    'title', 'change', 'dismiss', 'mute',
                    // Per-context bodies: the engine picks by clicked element
                    // (tab, sidebar link, row, breadcrumb, …), 'body' is the
                    // generic labeled-button fallback.
                    'body', 'body_tab', 'body_nav', 'body_row_open', 'body_row_select',
                    'body_back', 'body_search', 'body_search_global', 'body_page', 'body_sort',
                    'body_jump', 'body_help', 'help_title',
                ])->mapWithKeys(fn (string $key) => [
                    $key => __("filament-mouseless::mouseless.teach.{$key}"),
                ])->all(),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The user's most-clicked actions that teaching could still fix —
     * ranked by lifetime click count, minus taught/dismissed ones. The
     * teach layer nudges at most once per page view; this list makes that
     * one nudge go to a frequent offender instead of a one-off click.
     * Empty (= no restriction) when statistics aren't tracked.
     *
     * @return array<int, string>
     */
    protected function teachClickPriority(int $userId): array
    {
        if (! FilamentMouselessPlugin::statisticsEnabled()) {
            return [];
        }

        try {
            if (! Schema::hasTable('mouseless_statistics')) {
                return [];
            }

            $states = Nudge::statesFor($userId);

            return collect(Statistic::perActionTotals($userId))
                ->filter(fn (array $t, string $id): bool => $t['click'] > 0
                    && ! ($states[$id]['learned'] ?? false)
                    && ! ($states[$id]['dismissed'] ?? false))
                ->sortByDesc(fn (array $t): int => $t['click'])
                ->take(5)
                ->keys()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The statistics layer's payload — non-null arms the engine's usage
     * counter (keyboard invocations + bypassing clicks, batched and flushed
     * to the StatisticsFlush component). Null when the plugin doesn't have
     * ->statistics(), the user is a guest, or the table isn't migrated.
     *
     * @return array{flushMs: int}|null
     */
    protected function stats(): ?array
    {
        if (! FilamentMouselessPlugin::statisticsEnabled() || ! PanelAuth::check()) {
            return null;
        }

        try {
            if (! Schema::hasTable('mouseless_statistics')) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        return ['flushMs' => 10_000];
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
