<?php

namespace Blemli\FilamentMouseless\Filament\Widgets;

use Blemli\FilamentMouseless\FilamentMouselessPlugin;
use Blemli\FilamentMouseless\Models\Statistic;
use Blemli\FilamentMouseless\Services\BindingResolver;
use Blemli\FilamentMouseless\Support\ActionMeta;
use Blemli\FilamentMouseless\Support\Keys;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Schema;

/**
 * The personal statistics card on /my-shortcuts (below the preset selector):
 * lifetime "clicks avoided" headline, an 8-week trend sparkline, and the
 * "untapped actions" nudge list — actions the user still mostly clicks
 * although a shortcut exists.
 */
class StatisticsOverview extends Widget
{
    protected const TREND_WEEKS = 8;

    protected const UNTAPPED_LIMIT = 3;

    protected string $view = 'filament-mouseless::widgets.statistics';

    /** Per-request memo — the blade reads the totals several times. */
    private ?array $totalsMemo = null;

    /**
     * Dashboard registration (`->widgets([MouselessWidget::class])`) checks
     * this — the card only renders while statistics are actually tracked.
     */
    public static function canView(): bool
    {
        if (! FilamentMouselessPlugin::statisticsEnabled() || ! auth()->check()) {
            return false;
        }

        try {
            return Schema::hasTable('mouseless_statistics');
        } catch (\Throwable) {
            return false;
        }
    }

    public function clicksAvoided(): int
    {
        return array_sum(array_map(fn (array $t): int => $t['kb'], $this->totals()));
    }

    /**
     * Keyboard and click sums per week, oldest first — TREND_WEEKS buckets
     * ending with the current (partial) week.
     *
     * @return array<int, array{kb: int, click: int}>
     */
    public function weeklyTrend(): array
    {
        $daily = Statistic::dailyCounts((int) auth()->id(), self::TREND_WEEKS * 7);

        $weeks = array_fill(0, self::TREND_WEEKS, ['kb' => 0, 'click' => 0]);
        $day = 0;
        foreach ($daily as $counts) {
            $weeks[intdiv($day, 7)]['kb'] += $counts['kb'];
            $weeks[intdiv($day, 7)]['click'] += $counts['click'];
            $day++;
        }

        return $weeks;
    }

    /**
     * Inline-SVG points for the sparkline ("x,y" pairs in a 100×32 viewBox).
     * Plotted is the weekly KEYBOARD SHARE — kb / (kb + click) — not the raw
     * count: a quiet vacation week shouldn't read as a keyboard relapse.
     * Weeks without any activity contribute no point; the line interpolates
     * across them. The y scale is absolute (0…100 %), so the line's height
     * is meaningful across users and weeks.
     */
    public function trendPoints(): string
    {
        $weeks = $this->weeklyTrend();
        $stepX = 100 / (count($weeks) - 1);

        $points = [];
        foreach ($weeks as $i => $week) {
            $total = $week['kb'] + $week['click'];
            if ($total === 0) {
                continue;
            }

            $y = 30 - ($week['kb'] / $total) * 28;
            $points[] = round($i * $stepX, 1) . ',' . round($y, 1);
        }

        return implode(' ', $points);
    }

    /** A ratio line needs at least two active weeks to say anything. */
    public function hasTrend(): bool
    {
        $activeWeeks = count(array_filter(
            $this->weeklyTrend(),
            fn (array $week): bool => $week['kb'] + $week['click'] > 0,
        ));

        return $activeWeeks >= 2;
    }

    /**
     * Actions the user clicks more than they key, although a shortcut is
     * bound right now — the strongest candidates for building the habit.
     *
     * @return array<int, array{label: string, combo: string, clicks: int, kb: int}>
     */
    public function untappedActions(): array
    {
        try {
            $bindings = (array) (app(BindingResolver::class)->forUser(auth()->id())['bindings'] ?? []);
        } catch (\Throwable) {
            return [];
        }

        return collect($this->totals())
            ->filter(fn (array $t, string $id): bool => $t['click'] > $t['kb'] && filled($bindings[$id] ?? null))
            ->sortByDesc(fn (array $t): int => $t['click'] - $t['kb'])
            ->take(self::UNTAPPED_LIMIT)
            ->map(fn (array $t, string $id): array => [
                'label' => ActionMeta::label($id),
                'combo' => Keys::display($bindings[$id]),
                'clicks' => $t['click'],
                'kb' => $t['kb'],
            ])
            ->values()
            ->all();
    }

    /** @return array<string, array{kb: int, click: int}> */
    protected function totals(): array
    {
        return $this->totalsMemo ??= Statistic::perActionTotals((int) auth()->id());
    }
}
