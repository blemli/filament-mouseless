<?php

namespace Blemli\FilamentMouseless\Livewire;

use Blemli\FilamentMouseless\Models\Nudge;
use Blemli\FilamentMouseless\Support\PanelAuth;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Invisible persistence endpoint for the teach layer. The engine (and the
 * teach notification's action buttons) dispatch Livewire events; this
 * component writes them to the mouseless_nudges table so backoff, per-action
 * dismissals, and the global mute survive the page — and follow the user
 * across devices.
 */
class TeachNudges extends Component
{
    #[On('mouseless-teach-shown')]
    public function shown(string $actionId): void
    {
        if ($this->accepts($actionId)) {
            Nudge::recordShown((int) PanelAuth::id(), $actionId);
        }
    }

    #[On('mouseless-teach-dismiss')]
    public function dismiss(string $actionId): void
    {
        if ($this->accepts($actionId)) {
            Nudge::dismiss((int) PanelAuth::id(), $actionId);
        }
    }

    /** One keyboard use — 3 in a row and the action counts as taught. */
    #[On('mouseless-teach-used')]
    public function used(string $actionId): void
    {
        if ($this->accepts($actionId)) {
            Nudge::recordUsed((int) PanelAuth::id(), $actionId);
        }
    }

    /** A mouse click on the action's button — breaks the keyboard streak. */
    #[On('mouseless-teach-clicked')]
    public function clicked(string $actionId): void
    {
        if ($this->accepts($actionId)) {
            Nudge::breakStreak((int) PanelAuth::id(), $actionId);
        }
    }

    #[On('mouseless-teach-mute')]
    public function mute(): void
    {
        if ($this->accepts(Nudge::MUTE_ALL)) {
            Nudge::muteAll((int) PanelAuth::id());
        }
    }

    protected function accepts(string $actionId): bool
    {
        if (! PanelAuth::check()) {
            return false;
        }

        if (! preg_match('/^[a-z0-9.*_-]{1,100}$/i', $actionId)) {
            return false;
        }

        try {
            return Schema::hasTable('mouseless_nudges');
        } catch (\Throwable) {
            return false;
        }
    }

    public function render()
    {
        return view('filament-mouseless::livewire.teach-nudges');
    }
}
