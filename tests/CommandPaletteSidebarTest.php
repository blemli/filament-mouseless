<?php

/*
 * nav.command-palette used to be dead on panels with the global search in the
 * sidebar (->topbar(false)): the field is display:none while the rail is
 * collapsed, so the engine found "no global search field" — and, owning
 * mod+k in capture phase, swallowed the combo for everybody else too. The
 * engine now reveals the sidebar for the search and puts it back afterwards.
 * The engine ships as plain JS without a build step; pin the mechanism here.
 */
it('reveals a collapsed sidebar for the command palette and restores it', function (): void {
    $engine = file_get_contents(__DIR__.'/../resources/js/index.js');

    expect($engine)
        ->toContain('function focusGlobalSearch()')
        ->toContain('findGlobalSearchInput(document, { includeHidden: true })')
        ->toContain("store.isOpen = true;")
        ->toContain("new CustomEvent('mouseless-sidebar-revealed')")
        ->toContain("new CustomEvent('mouseless-sidebar-restored')")
        ->toContain("document.addEventListener('livewire:navigated', () => { sidebarRevealedForSearch = false; });")
        // Never the persisted desktop state — Filament restores that on navigation.
        ->not->toContain('isOpenDesktop = ');
});
