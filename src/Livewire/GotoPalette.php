<?php

namespace Blemli\FilamentMouseless\Livewire;

use Blemli\FilamentMouseless\Support\NavigationItems;
use Livewire\Component;

/**
 * The "go to" palette: a keyboard-driven overlay that jumps to any navigation
 * target. Rendered once at the end of the panel body (see the plugin's render
 * hook); the JS engine opens it on the `nav.goto` leader key and drives the
 * type-to-filter interaction entirely client-side against the pre-rendered
 * markup, so there are no Livewire round-trips while filtering.
 */
class GotoPalette extends Component
{
    public function render()
    {
        return view('filament-mouseless::livewire.goto-palette', [
            'groups' => app(NavigationItems::class)->forCurrentPanel(),
        ]);
    }
}
