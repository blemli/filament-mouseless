<?php

return [
    'slug' => 'english-default',
    'name' => 'English (default)',
    'description' => 'Actions on their English initials (Alt+N = New)',
    'locale' => 'en',
    'version' => '2.0',
    'author' => 'blemli/filament-mouseless',

    'bindings' => [
        // Submit tier — identical in every language ('mod' = ⌘ on macOS, Ctrl elsewhere).
        'crud.submit' => 'mod+Enter',
        'crud.save' => 'mod+s',
        'crud.create-another' => 'mod+shift+Enter',
        'list.select-all' => 'mod+a', // only fires in list mode, never inside inputs
        'record.copy-markdown' => 'mod+c', // only fires when nothing is selected
        'record.print' => 'mod+p', // cheatsheet → record print action → browser print
        'nav.command-palette' => 'mod+k',

        // Record actions — Alt + the action's initial.
        'crud.create' => 'alt+n', // New (Filament v4's own button label)
        'crud.edit' => 'alt+e',
        'crud.view' => 'alt+v',
        'crud.delete' => 'alt+d',
        'crud.force-delete' => 'alt+f',
        'crud.restore' => 'alt+r',
        'crud.duplicate' => 'alt+y', // Replicate — Y as in "copY" (vim yank)
        'crud.attach' => 'alt+l', // Link (attach / associate)
        'crud.detach' => 'alt+u', // Unlink (detach / dissociate)
        // crud.cancel intentionally shares Escape with ui.close — the engine treats
        // both the same way (blur active element / close modal).
        'record.history' => 'alt+h',
        'record.merge' => 'alt+m',
        'record.split' => 'alt+s',
        'record.comment' => 'alt+c',
        'record.approve' => 'alt+a',
        'record.reject' => 'alt+x', // X = the cross-out mark, same in every language
        'record.archive' => 'alt+shift+a',
        'record.favorite' => 'alt+b', // Bookmark
        'record.watch' => 'alt+w',
        'record.lock' => 'alt+shift+l',
        'record.share' => 'alt+shift+s',

        // Table controls — Alt+Shift + initial.
        'list.filter' => 'alt+shift+f',
        'list.group' => 'alt+shift+g',
        'list.sort' => 'alt+shift+o', // Order by
        'list.columns' => 'alt+shift+c',
        'list.bulk-action' => 'alt+shift+b',
        'list.export' => 'alt+shift+e',
        'list.import' => 'alt+shift+i',

        // List navigation — fixed keys.
        'list.search' => '/',
        'list.refresh' => 'F5',
        'list.next-page' => 'alt+ArrowRight',
        'list.prev-page' => 'alt+ArrowLeft',
        'list.next-row' => 'j', // vim down
        'list.prev-row' => 'k', // vim up
        'list.toggle-row' => 'Space', // toggle checkbox on focused row
        'list.select-next-row' => 'shift+j', // extend the row selection down
        'list.select-prev-row' => 'shift+k', // extend the row selection up

        // UI & chrome — Ctrl+Alt, identical in every language.
        'nav.goto' => 'g', // leader: opens the "go to" palette (type a nav item's name)
        'nav.dashboard' => 'alt+ArrowUp', // panel home
        'nav.profile' => 'ctrl+alt+p',
        'nav.logout' => 'ctrl+alt+q', // Q = quit
        'nav.language' => 'ctrl+alt+g', // G = globe 🌐
        'nav.recently-viewed' => 'ctrl+alt+r',
        'nav.notifications' => 'ctrl+alt+n',
        'ui.help' => '?',
        'ui.close' => 'Escape',
        'ui.next-tab' => 'alt+ArrowDown',
    ],
];
