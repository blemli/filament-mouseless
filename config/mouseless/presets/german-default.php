<?php

return [
    'slug' => 'german-default',
    'name' => 'Deutsch (Standard)',
    'description' => 'Aktionen auf ihren deutschen Anfangsbuchstaben (Alt+E = Erstellen)',
    'locale' => 'de',
    'version' => '2.0',
    'author' => 'blemli/filament-mouseless',

    'bindings' => [
        // Submit-Ebene — in jeder Sprache identisch ('mod' = ⌘ auf macOS, sonst Ctrl).
        'crud.submit' => 'mod+Enter',
        'crud.save' => 'mod+s',
        'crud.create-another' => 'mod+shift+Enter',
        'list.select-all' => 'mod+a', // nur im Listen-Modus, nie in Eingabefeldern
        'record.copy-markdown' => 'mod+c', // nur wenn nichts markiert ist
        'record.print' => 'mod+p', // Cheatsheet → Drucken-Aktion → Browser-Druck
        'nav.command-palette' => 'mod+k',

        // Datensatz-Aktionen — Alt + Anfangsbuchstabe.
        'crud.create' => 'alt+e', // Erstellen (Filaments eigene Beschriftung)
        'crud.edit' => 'alt+b', // Bearbeiten
        'crud.view' => 'alt+a', // Anzeigen
        'crud.delete' => 'alt+l', // Löschen
        'crud.force-delete' => 'alt+shift+l', // Endgültig löschen — das „härtere" Löschen
        'crud.restore' => 'alt+w', // Wiederherstellen
        'crud.duplicate' => 'alt+d', // Duplizieren
        'crud.attach' => 'alt+v', // Verknüpfen (attach / associate)
        'crud.detach' => 'alt+t', // Trennen (detach / dissociate)
        // crud.cancel teilt sich Escape mit ui.close (gleiche Semantik).
        'record.history' => 'alt+h', // Historie
        'record.merge' => 'alt+z', // Zusammenführen
        'record.split' => 'alt+shift+z', // Zerteilen
        'record.comment' => 'alt+k', // Kommentieren
        'record.approve' => 'alt+g', // Genehmigen
        'record.reject' => 'alt+x', // X = durchstreichen, in jeder Sprache gleich
        'record.archive' => 'alt+shift+a', // Archivieren
        'record.favorite' => 'alt+m', // Merken
        'record.watch' => 'alt+shift+b', // Beobachten
        'record.lock' => 'alt+s', // Sperren
        'record.share' => 'alt+f', // Freigeben

        // Tabellen-Steuerung — Alt+Shift + Anfangsbuchstabe.
        'list.filter' => 'alt+shift+f', // Filter
        'list.group' => 'alt+shift+g',
        'list.sort' => 'alt+shift+o', // Ordnen
        'list.columns' => 'alt+shift+s', // Spalten
        'list.bulk-action' => 'alt+shift+m', // Mehrfachaktionen
        'list.export' => 'alt+shift+e', // Exportieren
        'list.import' => 'alt+shift+i', // Importieren

        // Listen-Navigation — feste Tasten.
        'list.search' => '/',
        'list.refresh' => 'F5',
        'list.next-page' => 'alt+ArrowRight',
        'list.prev-page' => 'alt+ArrowLeft',
        'list.next-row' => 'j', // vim runter
        'list.prev-row' => 'k', // vim hoch
        'list.toggle-row' => 'Space', // Checkbox der fokussierten Zeile umschalten
        'list.select-next-row' => 'shift+j', // Auswahl nach unten erweitern
        'list.select-prev-row' => 'shift+k', // Auswahl nach oben erweitern

        // Oberfläche — Ctrl+Alt, in jeder Sprache identisch.
        'nav.goto' => 'g', // Leader: öffnet die „Springe zu"-Palette
        'nav.dashboard' => 'alt+ArrowUp', // Startseite des Panels
        'nav.profile' => 'ctrl+alt+p',
        'nav.logout' => 'ctrl+alt+q', // Q = quit
        'nav.language' => 'ctrl+alt+g', // G = Globus 🌐
        'nav.recently-viewed' => 'ctrl+alt+r',
        'nav.notifications' => 'ctrl+alt+n',
        'ui.help' => '?',
        'ui.close' => 'Escape',
        'ui.next-tab' => 'alt+ArrowDown',
    ],
];
