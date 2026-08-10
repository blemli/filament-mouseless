<?php

namespace Blemli\FilamentMouseless\Livewire;

use Blemli\FilamentMouseless\Events\MilestoneReached;
use Blemli\FilamentMouseless\FilamentMouselessPlugin;
use Blemli\FilamentMouseless\Models\Statistic;
use Blemli\FilamentMouseless\Support\PanelAuth;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Number;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Invisible persistence endpoint for usage statistics. The engine buffers
 * keyboard invocations and bypassing mouse clicks per action and dispatches
 * them here in batches; this component merges them into the user's daily
 * row and fires the milestone congratulation when a flush crosses 100,
 * 1'000 or 10'000 lifetime keyboard uses.
 */
class StatisticsFlush extends Component
{
    /** @param  array<string, array{kb?: int, click?: int}>  $events */
    #[On('mouseless-stats-flush')]
    public function flush(array $events): void
    {
        if (! $this->accepts()) {
            return;
        }

        $clean = [];

        foreach ($events as $actionId => $counts) {
            if (! is_string($actionId) || ! preg_match('/^[a-z0-9.*_-]{1,100}$/i', $actionId)) {
                continue;
            }
            if (! is_array($counts)) {
                continue;
            }

            $kb = min(max((int) ($counts['kb'] ?? 0), 0), Statistic::MAX_EVENTS_PER_FLUSH);
            $click = min(max((int) ($counts['click'] ?? 0), 0), Statistic::MAX_EVENTS_PER_FLUSH);

            if ($kb === 0 && $click === 0) {
                continue;
            }

            $clean[$actionId] = ['kb' => $kb, 'click' => $click];
        }

        if ($clean === []) {
            return;
        }

        $crossed = Statistic::record((int) PanelAuth::id(), $clean);

        if ($crossed !== []) {
            $lifetime = Statistic::lifetimeKeyboardCount((int) PanelAuth::id());

            foreach ($crossed as $milestone) {
                MilestoneReached::dispatch((int) PanelAuth::id(), $milestone, $lifetime);
            }

            $this->congratulate(max($crossed));
        }
    }

    protected function congratulate(int $milestone): void
    {
        Notification::make()
            ->title(__('filament-mouseless::mouseless.stats.milestone.title', [
                'count' => Number::format($milestone),
            ]))
            ->body(__('filament-mouseless::mouseless.stats.milestone.body', [
                'count' => Number::format(Statistic::lifetimeKeyboardCount((int) PanelAuth::id())),
            ]))
            ->success()
            ->icon('heroicon-o-trophy')
            ->persistent()
            ->send();
    }

    protected function accepts(): bool
    {
        if (! PanelAuth::check() || ! FilamentMouselessPlugin::statisticsEnabled()) {
            return false;
        }

        try {
            return Schema::hasTable('mouseless_statistics');
        } catch (\Throwable) {
            return false;
        }
    }

    public function render()
    {
        return view('filament-mouseless::livewire.statistics-flush');
    }
}
