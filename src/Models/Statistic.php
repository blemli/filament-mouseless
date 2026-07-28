<?php

namespace Blemli\FilamentMouseless\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One user's shortcut usage for one day: a JSON map of per-action counters
 * ({"crud.create": {"kb": 12, "click": 3}}) plus row-level sums so totals,
 * trends and the admin aggregation are plain SQL. `kb` counts keyboard
 * invocations (= clicks avoided), `click` counts trusted mouse clicks on a
 * target that has a shortcut — together they give the per-action ratio the
 * "untapped actions" nudge list is built from.
 */
class Statistic extends Model
{
    /** Lifetime keyboard-use counts that earn a congratulation. */
    public const MILESTONES = [100, 1_000, 10_000];

    /** Ceiling per action and kind in a single flush — anything above is garbage. */
    public const MAX_EVENTS_PER_FLUSH = 1_000;

    protected $table = 'mouseless_statistics';

    protected $guarded = [];

    protected $casts = [
        'counters' => 'array',
        'date' => 'date',
    ];

    /**
     * Merge one flush of buffered events into today's row and return the
     * milestones crossed by it (usually none, at most a few after a very
     * large flush) — highest last.
     *
     * @param  array<string, array{kb?: int, click?: int}>  $events
     * @return array<int, int>
     */
    public static function record(int $userId, array $events): array
    {
        $kbDelta = 0;
        $clickDelta = 0;

        foreach ($events as $counts) {
            $kbDelta += (int) ($counts['kb'] ?? 0);
            $clickDelta += (int) ($counts['click'] ?? 0);
        }

        if ($kbDelta === 0 && $clickDelta === 0) {
            return [];
        }

        $before = static::lifetimeKeyboardCount($userId);

        $row = static::firstOrNew(['user_id' => $userId, 'date' => today()]);
        $counters = (array) ($row->counters ?? []);

        foreach ($events as $actionId => $counts) {
            $entry = (array) ($counters[$actionId] ?? []);
            $entry['kb'] = (int) ($entry['kb'] ?? 0) + (int) ($counts['kb'] ?? 0);
            $entry['click'] = (int) ($entry['click'] ?? 0) + (int) ($counts['click'] ?? 0);
            $counters[$actionId] = $entry;
        }

        $row->counters = $counters;
        $row->keyboard_count = ($row->keyboard_count ?? 0) + $kbDelta;
        $row->click_count = ($row->click_count ?? 0) + $clickDelta;
        $row->save();

        return array_values(array_filter(
            self::MILESTONES,
            fn (int $milestone): bool => $before < $milestone && $before + $kbDelta >= $milestone,
        ));
    }

    /** Total keyboard invocations ever — the "clicks avoided" headline. */
    public static function lifetimeKeyboardCount(int $userId): int
    {
        return (int) static::query()->where('user_id', $userId)->sum('keyboard_count');
    }

    /**
     * Per-action lifetime totals for one user, summed across all daily rows.
     *
     * @return array<string, array{kb: int, click: int}>
     */
    public static function perActionTotals(int $userId): array
    {
        return static::sumCounters(static::query()->where('user_id', $userId)->pluck('counters'));
    }

    /**
     * Keyboard and click counts per day over the trailing window (sparkline
     * data). Every day is present — quiet days as zeros — oldest first.
     *
     * @return array<string, array{kb: int, click: int}> date (Y-m-d) => counts
     */
    public static function dailyCounts(int $userId, int $days = 56): array
    {
        $from = today()->subDays($days - 1);

        $counts = [];
        $rows = static::query()
            ->where('user_id', $userId)
            ->whereDate('date', '>=', $from)
            ->get(['date', 'keyboard_count', 'click_count']);

        foreach ($rows as $row) {
            $counts[$row->date->toDateString()] = [
                'kb' => (int) $row->keyboard_count,
                'click' => (int) $row->click_count,
            ];
        }

        $series = [];
        for ($day = 0; $day < $days; $day++) {
            $key = $from->copy()->addDays($day)->toDateString();
            $series[$key] = $counts[$key] ?? ['kb' => 0, 'click' => 0];
        }

        return $series;
    }

    /**
     * The first day each action was invoked by keyboard, oldest rows first.
     * The admin-override layer compares these against an override's change
     * dates: usage that predates the change keeps the old key ("don't move
     * keys people actually use").
     *
     * @return array<string, string> action => Y-m-d of first keyboard use
     */
    public static function firstKeyboardUseDates(int $userId): array
    {
        $first = [];

        $rows = static::query()
            ->where('user_id', $userId)
            ->orderBy('date')
            ->get(['date', 'counters']);

        foreach ($rows as $row) {
            foreach ((array) $row->counters as $actionId => $counts) {
                if ((int) ($counts['kb'] ?? 0) > 0 && ! isset($first[$actionId])) {
                    $first[$actionId] = $row->date->toDateString();
                }
            }
        }

        return $first;
    }

    /**
     * Org-wide totals for the admin view.
     *
     * @return array{keyboard: int, clicks: int, users: int}
     */
    public static function totals(): array
    {
        $row = static::query()
            ->selectRaw('COALESCE(SUM(keyboard_count), 0) as kb, COALESCE(SUM(click_count), 0) as clicks, COUNT(DISTINCT user_id) as users')
            ->first();

        return [
            'keyboard' => (int) ($row->kb ?? 0),
            'clicks' => (int) ($row->clicks ?? 0),
            'users' => (int) ($row->users ?? 0),
        ];
    }

    /**
     * Per-action totals across ALL users (admin "most used shortcuts").
     *
     * @return array<string, array{kb: int, click: int}>
     */
    public static function perActionTotalsAllUsers(): array
    {
        return static::sumCounters(static::query()->pluck('counters'));
    }

    /**
     * Top users by lifetime keyboard count (admin leaderboard).
     *
     * @return array<int, array{user_id: int, keyboard: int}>
     */
    public static function leaderboard(int $limit = 5): array
    {
        return static::query()
            ->select('user_id', DB::raw('SUM(keyboard_count) as kb'))
            ->groupBy('user_id')
            ->orderByDesc('kb')
            ->limit($limit)
            ->get()
            ->map(fn (self $row): array => [
                'user_id' => (int) $row->user_id,
                'keyboard' => (int) $row->kb,
            ])
            ->all();
    }

    /**
     * @param  Collection<int, mixed>  $counterMaps
     * @return array<string, array{kb: int, click: int}>
     */
    protected static function sumCounters($counterMaps): array
    {
        $totals = [];

        foreach ($counterMaps as $counters) {
            if (is_string($counters)) {
                $counters = json_decode($counters, true);
            }

            foreach ((array) $counters as $actionId => $counts) {
                $entry = $totals[$actionId] ?? ['kb' => 0, 'click' => 0];
                $entry['kb'] += (int) ($counts['kb'] ?? 0);
                $entry['click'] += (int) ($counts['click'] ?? 0);
                $totals[$actionId] = $entry;
            }
        }

        return $totals;
    }
}
