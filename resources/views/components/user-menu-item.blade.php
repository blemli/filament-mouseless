{{-- The my-shortcuts link, rendered at a user-menu render hook (see shortcutsPosition()). --}}
<x-filament::dropdown.list class="fi-mouseless-shortcuts-link">
    <x-filament::dropdown.list.item
        tag="a"
        :href="$url"
        :icon="$icon"
        :badge="($badge ?? null) ?: null"
        badge-color="danger"
    >
        {{ $label }}
    </x-filament::dropdown.list.item>
</x-filament::dropdown.list>
