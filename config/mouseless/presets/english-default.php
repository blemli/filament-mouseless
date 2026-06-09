<?php

return [
    'slug' => 'english-default',
    'name' => 'English (default)',
    'locale' => 'en',
    'version' => '1.0',
    'author' => 'blemli/filament-mouseless',

    'bindings' => [
        // CRUD
        'crud.create' => 'alt+c',
        'crud.edit' => 'alt+e',
        'crud.delete' => 'alt+d',
        'crud.save' => 'alt+s',
        // crud.cancel intentionally shares Escape with ui.close — the engine treats
        // both the same way (blur active element / close modal).
        'crud.view' => 'alt+v',
        'crud.duplicate' => 'alt+y',

        // List
        'list.search' => '/',
        'list.filter' => 'alt+f',
        'list.refresh' => 'F5',
        'list.export' => 'alt+x',
        'list.import' => 'alt+i',
        'list.select-all' => 'alt+a',
        'list.bulk-action' => 'alt+b',
        'list.next-page' => 'alt+ArrowRight',
        'list.prev-page' => 'alt+ArrowLeft',
        'list.next-row' => 'j', // vim down
        'list.prev-row' => 'k', // vim up
        'list.toggle-row' => 'Space', // toggle checkbox on focused row

        // Record
        'record.print' => 'alt+p',
        'record.history' => 'alt+h',
        'record.comment' => 'alt+k',
        'record.archive' => 'alt+shift+a',
        'record.approve' => 'alt+g',
        'record.reject' => 'alt+shift+x',

        // Bulk
        'bulk.merge' => 'alt+m',

        // Navigation
        'nav.dashboard' => 'alt+ArrowUp', // first sidebar nav item
        'nav.profile' => 'alt+shift+u',
        'nav.logout' => 'alt+shift+q',
        'nav.command-palette' => 'cmd+k',
        'nav.recently-viewed' => 'alt+shift+r',
        'nav.notifications' => 'alt+shift+n',

        // UI
        'ui.help' => '?',
        'ui.close' => 'Escape',
        'ui.next-tab' => 'alt+ArrowDown',
    ],
];
