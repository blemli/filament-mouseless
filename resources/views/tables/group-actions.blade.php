@php
    use Filament\Support\Enums\Size;
    use Illuminate\Support\Js;
@endphp

{{-- Group-level actions injected into the table's group-header row (via the
     Group description slot). One <x-filament::link> per action so they inherit
     Filament's link styling and theme colours. Placement is handled by
     .fi-mouseless-group-actions in mouseless.css. --}}
<span class="fi-mouseless-group-actions">
    @foreach ($buttons as $button)
        <x-filament::link
            tag="button"
            :color="$button['color']"
            :icon="$button['icon']"
            :size="Size::Small"
            wire:click.stop="{{ $button['method'] }}({{ Js::from($category) }})"
        >
            {{ $button['label'] }}
        </x-filament::link>
    @endforeach
</span>
