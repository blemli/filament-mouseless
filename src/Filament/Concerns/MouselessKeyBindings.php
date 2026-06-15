<?php

namespace Blemli\FilamentMouseless\Filament\Concerns;

/**
 * Opt a Filament page into mouseless takeover of its actions' ->keyBindings().
 *
 * With this trait on the page class, every custom action's native key binding
 * is disarmed at render time and re-bound through the mouseless engine instead
 * — which lets users rebind or disable those shortcuts from /my-shortcuts.
 * Without it, such actions are still surfaced (overlay + my-shortcuts) but
 * remain read-only, driven by Filament's own key handling.
 *
 * Override {@see mouselessExcludedActions()} to keep specific actions on their
 * native binding.
 */
trait MouselessKeyBindings
{
    /**
     * Action names to leave on their native Filament key binding.
     *
     * @return array<int, string>
     */
    public function mouselessExcludedActions(): array
    {
        return [];
    }
}
