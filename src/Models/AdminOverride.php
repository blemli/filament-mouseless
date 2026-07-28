<?php

namespace Blemli\FilamentMouseless\Models;

use Blemli\FilamentMouseless\Support\Keys;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * An admin's change to the shortcut defaults — one row per changed action,
 * layered by the BindingResolver on top of every locale's default preset.
 * Rebinds are soft (a user's own explicit binding, or a key they actively
 * used before the change, wins); disables are hard for everyone.
 *
 * Rows whose action_id starts with '~' store admin page settings instead
 * (currently only the default-preset choice, kept in `combo`).
 */
class AdminOverride extends Model
{
    public const SETTING_PREFIX = '~';

    public const SETTING_DEFAULT_PRESET = '~default-preset';

    protected $table = 'mouseless_admin_overrides';

    protected $guarded = [];

    protected $casts = [
        'is_unbound' => 'bool',
        'is_disabled' => 'bool',
        'combo_set_at' => 'datetime',
    ];

    /**
     * Every active override, keyed by action. Empty when the table doesn't
     * exist (feature not migrated) — callers treat that as "no overrides".
     *
     * @return array<string, array{
     *     combo: ?string,
     *     unbound: bool,
     *     disabled: bool,
     *     previous: ?string,
     *     first_set_at: ?string,
     *     combo_set_at: ?string,
     * }>
     */
    public static function current(): array
    {
        try {
            if (! Schema::hasTable('mouseless_admin_overrides')) {
                return [];
            }

            $out = [];

            foreach (static::query()->orderBy('action_id')->get() as $row) {
                if (str_starts_with($row->action_id, self::SETTING_PREFIX)) {
                    continue;
                }

                $out[$row->action_id] = [
                    'combo' => $row->combo,
                    'unbound' => (bool) $row->is_unbound,
                    'disabled' => (bool) $row->is_disabled,
                    'previous' => $row->previous_combo,
                    // Date strings (Y-m-d) — compared against the statistics
                    // rows' daily granularity when grandfathering users.
                    'first_set_at' => $row->created_at?->toDateString(),
                    'combo_set_at' => $row->combo_set_at?->toDateString(),
                ];
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /** Whether the given override entry rebinds (or unbinds) the action. */
    public static function rebinds(array $override): bool
    {
        return $override['combo'] !== null || $override['unbound'];
    }

    /**
     * Action IDs hard-disabled for every user.
     *
     * @return array<int, string>
     */
    public static function disabledActions(): array
    {
        return array_keys(array_filter(
            static::current(),
            fn (array $override): bool => $override['disabled'],
        ));
    }

    /**
     * Persist one action's admin state. Equal-to-default states ($rebound
     * false, $disabled false) delete the row — the table holds deltas only.
     *
     * @param  bool  $rebound  the combo differs from the built-in default
     *                         ($combo null + $rebound = unbound for everyone)
     */
    public static function apply(string $actionId, bool $rebound, ?string $combo, bool $disabled): void
    {
        $row = static::query()->firstWhere('action_id', $actionId);

        if (! $rebound && ! $disabled) {
            $row?->delete();

            return;
        }

        if (! $row) {
            static::create([
                'action_id' => $actionId,
                'combo' => $rebound ? $combo : null,
                'is_unbound' => $rebound && $combo === null,
                'is_disabled' => $disabled,
                // First override: users grandfathered here were using their
                // locale default, which the resolver knows per user — so no
                // previous_combo is needed yet.
                'previous_combo' => null,
                'combo_set_at' => $rebound ? now() : null,
            ]);

            return;
        }

        $wasRebound = $row->is_unbound || $row->combo !== null;

        if ($rebound) {
            if ($wasRebound && Keys::normalize($row->combo) !== Keys::normalize($combo)) {
                // Users who adopted the old override keep it — remember it.
                $row->previous_combo = $row->combo;
                $row->combo_set_at = now();
            } elseif (! $wasRebound) {
                $row->combo_set_at = now();
            }

            $row->combo = $combo;
            $row->is_unbound = $combo === null;
        } else {
            // Rebind reverted; only the disable remains.
            $row->combo = null;
            $row->is_unbound = false;
            $row->previous_combo = null;
            $row->combo_set_at = null;
        }

        $row->is_disabled = $disabled;
        $row->save();
    }

    public static function defaultPresetSlug(): ?string
    {
        try {
            if (! Schema::hasTable('mouseless_admin_overrides')) {
                return null;
            }

            return static::query()->firstWhere('action_id', self::SETTING_DEFAULT_PRESET)?->combo;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function setDefaultPresetSlug(?string $slug): void
    {
        if ($slug === null || $slug === '') {
            static::query()->where('action_id', self::SETTING_DEFAULT_PRESET)->delete();

            return;
        }

        static::query()->updateOrCreate(
            ['action_id' => self::SETTING_DEFAULT_PRESET],
            ['combo' => $slug],
        );
    }
}
