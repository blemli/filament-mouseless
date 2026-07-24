<?php

namespace Blemli\FilamentMouseless\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-user teach state for one action: how often the "you could have just
 * pressed ⌥E" notification was shown (drives the backoff), whether the user
 * dismissed it for this action, and how many times in a row they used the
 * shortcut via keyboard — at {@see TAUGHT_AFTER} consecutive uses the action
 * counts as taught and is never nudged again (a mouse click on the button
 * resets the streak). The '*' row mutes teach entirely.
 */
class Nudge extends Model
{
    /** action_id sentinel for "never notify me about any shortcut". */
    public const MUTE_ALL = '*';

    /** Backoff ceiling: one nudge per action per week, at most. */
    public const MAX_BACKOFF_HOURS = 168;

    /** Consecutive keyboard uses after which an action counts as taught. */
    public const TAUGHT_AFTER = 3;

    protected $table = 'mouseless_nudges';

    protected $guarded = [];

    protected $casts = [
        'last_shown_at' => 'datetime',
        'dismissed_at' => 'datetime',
        'learned_at' => 'datetime',
    ];

    /**
     * Hours to wait after the Nth showing before nudging the same action
     * again: 1, 2, 4, 8, … doubling up to a week. Hourly at first, daily
     * within a day or two, weekly from then on — teaching that fades instead
     * of nagging.
     */
    public static function backoffHours(int $shownCount): int
    {
        if ($shownCount < 1) {
            return 0;
        }

        return (int) min(2 ** min($shownCount - 1, 10), self::MAX_BACKOFF_HOURS);
    }

    public static function recordShown(int $userId, string $actionId): self
    {
        $nudge = static::firstOrNew(['user_id' => $userId, 'action_id' => $actionId]);
        $nudge->shown_count = ($nudge->shown_count ?? 0) + 1;
        $nudge->last_shown_at = now();
        $nudge->used_streak = 0; // they clicked — the "in a row" chain is broken
        $nudge->save();

        return $nudge;
    }

    public static function dismiss(int $userId, string $actionId): void
    {
        static::firstOrNew(['user_id' => $userId, 'action_id' => $actionId])
            ->fill(['dismissed_at' => now()])
            ->save();
    }

    /**
     * One keyboard use of the shortcut. The streak survives page loads; at
     * {@see TAUGHT_AFTER} in a row the action is taught for good.
     */
    public static function recordUsed(int $userId, string $actionId): self
    {
        $nudge = static::firstOrNew(['user_id' => $userId, 'action_id' => $actionId]);
        $nudge->used_streak = ($nudge->used_streak ?? 0) + 1;

        if ($nudge->used_streak >= self::TAUGHT_AFTER && $nudge->learned_at === null) {
            $nudge->learned_at = now();
        }

        $nudge->save();

        return $nudge;
    }

    /** A mouse click on the action's button — breaks the keyboard streak. */
    public static function breakStreak(int $userId, string $actionId): void
    {
        static::query()
            ->where('user_id', $userId)
            ->where('action_id', $actionId)
            ->where('used_streak', '>', 0)
            ->update(['used_streak' => 0]);
    }

    /** Forget taught/dismissed (and the streak/backoff) — teach it again. */
    public static function clear(int $userId, string $actionId): void
    {
        static::query()
            ->where('user_id', $userId)
            ->where('action_id', $actionId)
            ->delete();
    }

    public static function muteAll(int $userId): void
    {
        static::dismiss($userId, self::MUTE_ALL);
    }

    public static function isMuted(int $userId): bool
    {
        return static::query()
            ->where('user_id', $userId)
            ->where('action_id', self::MUTE_ALL)
            ->whereNotNull('dismissed_at')
            ->exists();
    }

    /** Forget every dismissal, mute, and backoff — tips start fresh. */
    public static function resetFor(int $userId): void
    {
        static::query()->where('user_id', $userId)->delete();
    }

    /**
     * The JS engine's per-action teach state: `nextAt` (epoch ms before which
     * the action must not nudge again), `shown` (nudge count, continues the
     * backoff client-side), `streak` (consecutive keyboard uses so far),
     * `dismissed`, `learned`. Actions with no row are simply absent —
     * eligible immediately.
     *
     * @return array<string, array{nextAt: int|null, shown: int, streak: int, dismissed: bool, learned: bool}>
     */
    public static function statesFor(int $userId): array
    {
        $states = [];

        foreach (static::query()->where('user_id', $userId)->get() as $nudge) {
            if ($nudge->action_id === self::MUTE_ALL) {
                continue;
            }

            $nextAt = $nudge->last_shown_at
                ? $nudge->last_shown_at->addHours(static::backoffHours((int) $nudge->shown_count))->getTimestampMs()
                : null;

            $states[$nudge->action_id] = [
                'nextAt' => $nextAt,
                'shown' => (int) $nudge->shown_count,
                'streak' => (int) $nudge->used_streak,
                'dismissed' => $nudge->dismissed_at !== null,
                'learned' => $nudge->learned_at !== null,
            ];
        }

        return $states;
    }
}
