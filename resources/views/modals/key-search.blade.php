<div
    data-mouseless-recording
    class="fi-mouseless-key-search-modal"
    x-data
    x-init="
        $nextTick(() => {
            const handler = (e) => {
                if (! $el.isConnected) {
                    window.removeEventListener('keydown', handler, true);
                    return;
                }
                if (e.key === 'Escape') {
                    window.removeEventListener('keydown', handler, true);
                    return; {{-- Filament closes the modal itself. --}}
                }
                e.preventDefault();
                e.stopPropagation();
                if (['Control','Alt','Shift','Meta'].includes(e.key)) return;
                const combo = window.mouselessEventToKey?.(e);
                if (! combo) return;
                window.removeEventListener('keydown', handler, true);
                $wire.applyKeySearch(combo);
            };
            window.addEventListener('keydown', handler, true);
        })
    "
>
    <x-filament::badge color="warning" size="lg">
        {{ __('filament-mouseless::mouseless.table.recording.press') }}
    </x-filament::badge>
</div>
