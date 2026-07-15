<?php

namespace Blemli\FilamentMouseless\Support;

use Blemli\FilamentMouseless\Services\CustomActionRegistry;

/**
 * Single source of truth for "which shortcuts collide". Mirrors the per-row
 * `duplicate` flag the shortcuts table computes, but standalone so callers
 * without a table host (e.g. the user-menu badge) get the same numbers.
 *
 * A conflict = two enabled actions resolving to the same normalized combo.
 * The effective set spans the preset's own bindings PLUS custom actions'
 * effective combos (a preset override, else their code-defined default) — a
 * read-only custom action sharing a combo is still a real, dispatch-level clash.
 */
class ShortcutConflicts
{
    /**
     * Number of actions whose effective combo is shared with at least one other
     * enabled action. Matches the count of "Mehrfach belegt" rows in the table.
     */
    public static function duplicateCount(?array $preset): int
    {
        $effective = self::effectiveCombos(
            (array) ($preset['bindings'] ?? []),
            (array) ($preset['disabled_actions'] ?? []),
        );

        $counts = array_count_values($effective);

        return count(array_filter($effective, fn (string $combo): bool => ($counts[$combo] ?? 0) > 1));
    }

    /**
     * Effective normalized combo per enabled action id: preset bindings first
     * (custom overrides included), then each custom action's code default when
     * the preset says nothing about it, minus every disabled action.
     *
     * @param  array<string, ?string>  $bindings
     * @param  array<int, string>  $disabled
     * @return array<string, string> id => normalized combo
     */
    public static function effectiveCombos(array $bindings, array $disabled): array
    {
        $effective = [];

        foreach ($bindings as $id => $combo) {
            $normalized = Keys::normalize($combo);
            if ($normalized !== null) {
                $effective[$id] = $normalized;
            }
        }

        foreach (self::customCombos() as $id => $meta) {
            if (! array_key_exists($id, $bindings)) {
                $effective[$id] = $meta['combo'];
            }
        }

        foreach ($disabled as $id) {
            unset($effective[$id]);
        }

        return $effective;
    }

    /**
     * Every custom action's code-defined combo (normalized) and display label.
     * These live in code, not the editable preset, so the recording guard uses
     * them to refuse a hand-created collision the same way it refuses protected
     * bindings.
     *
     * @return array<string, array{combo: string, label: string}>
     */
    public static function customCombos(): array
    {
        try {
            $actions = app(CustomActionRegistry::class)->all();
        } catch (\Throwable) {
            return [];
        }

        $out = [];

        foreach ($actions as $id => $meta) {
            $combo = Keys::normalize($meta['combos'][0] ?? null);
            if ($combo === null) {
                continue;
            }

            $label = (is_string($meta['label'] ?? null) && $meta['label'] !== '')
                ? $meta['label']
                : ActionMeta::label($id);

            $out[$id] = ['combo' => $combo, 'label' => $label];
        }

        return $out;
    }
}
