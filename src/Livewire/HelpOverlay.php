<?php

namespace Blemli\FilamentMouseless\Livewire;

use Blemli\FilamentMouseless\Facades\FilamentMouseless;
use Livewire\Component;

class HelpOverlay extends Component
{
    public function render()
    {
        $resolved = FilamentMouseless::forCurrentUser();

        $groups = [];
        foreach ($resolved['bindings'] as $actionId => $key) {
            [$ns] = explode('.', $actionId, 2);
            $groups[$ns][$actionId] = $key;
        }
        ksort($groups);

        return view('filament-mouseless::livewire.help-overlay', [
            'groups' => $groups,
            'preset' => $resolved['preset'],
        ]);
    }
}
