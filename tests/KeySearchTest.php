<?php

use Blemli\FilamentMouseless\Filament\Concerns\InteractsWithShortcutsTable;

/**
 * Shortcuts-table host with a fixed set of bindings, chosen so label matches
 * and combo matches diverge: "Anzeigen"-style labels contain a g, "pageup"
 * contains a g as a substring but not as a key token.
 */
function makeSearchableShortcutsTableHost(): object
{
    return new class
    {
        use InteractsWithShortcutsTable {
            shortcutRecords as public;
        }

        public ?string $tableSearch = null;

        public function getShortcutsPreset(): ?array
        {
            return [
                'owner_user_id' => null,
                'bindings' => [
                    'crud.view' => 'alt+a',        // label "View" — no g anywhere
                    'nav.goto' => 'g',             // bare g key
                    'nav.language' => 'ctrl+alt+g', // g with modifiers
                    'nav.paging' => 'pageup',      // "g" substring, not a g key
                ],
            ];
        }

        public function getShortcutsParentPreset(): ?array
        {
            return null;
        }

        public function isShortcutsLocked(): bool
        {
            return true;
        }

        public function ensureEditableShortcutsPreset(): void {}

        public function persistShortcuts(array $bindings, array $disabled): bool
        {
            return true;
        }

        public function resetPage(): void {}

        public function unmountAction(): void {}
    };
}

function searchResultIds(object $host, ?string $search): array
{
    return array_values(array_map(
        fn (array $row): string => $row['id'],
        $host->shortcutRecords($search, [], []),
    ));
}

it('matches keystrokes only after a key search', function () {
    $host = makeSearchableShortcutsTableHost();

    $host->applyKeySearch('g');

    // The box shows the keycap form — the visible tell for key mode.
    expect($host->tableSearch)->toBe('G')
        // 'nav.paging' ("pageup") and label-only matches must not appear.
        ->and(searchResultIds($host, $host->tableSearch))
        ->toBe(['nav.goto', 'nav.language']);
});

it('matches modifier combos token-wise in key search', function () {
    $host = makeSearchableShortcutsTableHost();

    $host->applyKeySearch('ctrl+alt+g');

    expect($host->tableSearch)->toBe('Ctrl Alt G')
        ->and(searchResultIds($host, $host->tableSearch))->toBe(['nav.language']);
});

it('keeps plain text search matching labels', function () {
    $host = makeSearchableShortcutsTableHost();

    // "Language" and "Paging" labels contain "g"; so do the g-key combos.
    expect(searchResultIds($host, 'g'))
        ->toContain('nav.goto', 'nav.language', 'nav.paging')
        ->and(searchResultIds($host, 'view'))->toBe(['crud.view']);
});

it('drops back to text search once the search text is edited', function () {
    $host = makeSearchableShortcutsTableHost();

    $host->applyKeySearch('g');

    expect($host->keySearchActive())->toBeTrue();

    // Simulate the user editing the search box: any change ends key mode …
    $host->shortcutRecords('vie', [], []);

    expect($host->keySearchCombo)->toBeNull()
        ->and($host->keySearchActive())->toBeFalse()
        // … so retyping "g" by hand now matches labels again.
        ->and(searchResultIds($host, 'g'))->toContain('nav.paging');
});
