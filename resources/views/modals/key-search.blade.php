<div
    data-mouseless-recording
    class="fi-mouseless-key-search-modal"
    x-data="{
        capture(e) {
            if (e.key === 'Escape') return; {{-- Filament closes the modal itself. --}}
            e.preventDefault();
            e.stopPropagation();
            if (['Control','Alt','Shift','Meta'].includes(e.key)) return;
            const combo = window.mouselessEventToKey?.(e);
            if (combo) this.$wire.applyKeySearch(combo);
        },
    }"
    {{--
        Alpine owns this window listener (attached on init, removed when the
        modal closes) rather than a hand-rolled addEventListener in
        x-init/$nextTick — the deferred attach never ran in a backgrounded tab.
    --}}
    x-on:keydown.window.capture="capture($event)"
>
    <x-filament::badge color="warning" size="lg">
        {{ __('filament-mouseless::mouseless.table.recording.press') }}
    </x-filament::badge>
</div>
