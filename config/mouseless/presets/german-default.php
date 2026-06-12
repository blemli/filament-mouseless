<?php

return [
    'slug' => 'german-default',
    'name' => 'Deutsch (Standard)',
    'description' => 'Aktionen auf ihren deutschen Anfangsbuchstaben (Alt+N = Neu)',
    'locale' => 'de',
    'version' => '1.0',
    'author' => 'blemli/filament-mouseless',

    'bindings' => [
        // CRUD
        'crud.create' => 'alt+n',  // Neu
        'crud.edit' => 'alt+b',  // Bearbeiten
        'crud.delete' => 'alt+l',  // Löschen
        'crud.save' => 'alt+s',  // Speichern
        // crud.cancel teilt sich Escape mit ui.close (gleiche Semantik).
        'crud.view' => 'alt+a',  // Anzeigen
        'crud.duplicate' => 'alt+k',  // Kopieren

        // List
        'list.search' => '/',
        'list.filter' => 'alt+f',  // Filtern
        'list.refresh' => 'F5',
        'list.export' => 'alt+e',  // Exportieren
        'list.import' => 'alt+i',  // Importieren
        'list.select-all' => 'alt+shift+a', // Alle (shift to avoid clash with Anzeigen)
        'list.bulk-action' => 'alt+shift+b',
        'list.next-page' => 'alt+ArrowRight',
        'list.prev-page' => 'alt+ArrowLeft',
        'list.next-row' => 'j', // vim runter
        'list.prev-row' => 'k', // vim hoch
        'list.toggle-row' => 'Space', // Checkbox der fokussierten Zeile umschalten

        // Record
        'record.print' => 'alt+d',  // Drucken
        'record.history' => 'alt+v',  // Verlauf
        'record.comment' => 'alt+m',  // Anmerkung
        'record.archive' => 'alt+r',  // Archivieren
        'record.approve' => 'alt+g',  // Genehmigen
        'record.reject' => 'alt+shift+r', // Ablehnen (no good DE letter)

        // Bulk
        'bulk.merge' => 'alt+z',  // Zusammenführen

        // Navigation
        'nav.dashboard' => 'alt+ArrowUp', // Erstes Nav-Item (Dashboard / Startseite)
        'nav.profile' => 'alt+shift+p', // Profil
        'nav.logout' => 'alt+shift+q',
        'nav.command-palette' => 'cmd+k',
        'nav.recently-viewed' => 'alt+shift+z', // zuletzt
        'nav.notifications' => 'alt+shift+n',

        // UI
        'ui.help' => '?',
        'ui.close' => 'Escape',
        'ui.next-tab' => 'alt+ArrowDown',
    ],
];
