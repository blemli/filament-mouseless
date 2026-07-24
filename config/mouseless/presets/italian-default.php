<?php

return [
    'slug' => 'italian-default',
    'name' => 'Italiano (predefinito)',
    'description' => 'Azioni sulle loro iniziali italiane (Alt+N = Nuovo)',
    'locale' => 'it',
    'version' => '2.0',
    'author' => 'blemli/filament-mouseless',

    'bindings' => [
        // Livello invio — identico in tutte le lingue ('mod' = ⌘ su macOS, Ctrl altrove).
        'crud.submit' => 'mod+Enter',
        'crud.save' => 'mod+s',
        'crud.create-another' => 'mod+shift+Enter',
        'list.select-all' => 'mod+a', // solo in modalità lista, mai nei campi di testo
        'record.copy-markdown' => 'mod+c', // solo quando non c'è nulla di selezionato
        'record.print' => 'mod+p', // cheatsheet → azione di stampa → stampa del browser
        'nav.command-palette' => 'mod+k',

        // Azioni sul record — Alt + iniziale.
        'crud.create' => 'alt+n', // Nuovo
        'crud.edit' => 'alt+m', // Modifica
        'crud.view' => 'alt+v', // Vedi
        'crud.delete' => 'alt+e', // Elimina
        'crud.force-delete' => 'alt+f', // Forza eliminazione
        'crud.restore' => 'alt+r', // Ripristina
        'crud.duplicate' => 'alt+d', // Duplica
        'crud.attach' => 'alt+c', // Collega (attach / associate)
        'crud.detach' => 'alt+s', // Scollega (detach / dissociate)
        // crud.cancel condivide Escape con ui.close (stessa semantica).
        'record.history' => 'alt+t', // Timeline
        'record.merge' => 'alt+u', // Unisci
        'record.split' => 'alt+shift+d', // Dividi
        'record.comment' => 'alt+shift+n', // Nota
        'record.approve' => 'alt+a', // Approva
        'record.reject' => 'alt+x', // X = depennare, uguale in tutte le lingue
        'record.archive' => 'alt+shift+a', // Archivia
        'record.favorite' => 'alt+p', // Preferito
        'record.watch' => 'alt+o', // Osserva
        'record.lock' => 'alt+b', // Blocca
        'record.share' => 'alt+i', // Inoltra

        // Controlli tabella — Alt+Shift + iniziale.
        'list.filter' => 'alt+shift+f', // Filtri
        'list.group' => 'alt+shift+r', // Raggruppa
        'list.sort' => 'alt+shift+o', // Ordina
        'list.columns' => 'alt+shift+c', // Colonne
        'list.bulk-action' => 'alt+shift+m', // Azioni multiple
        'list.export' => 'alt+shift+e', // Esporta
        'list.import' => 'alt+shift+i', // Importa

        // Navigazione lista — tasti fissi.
        'list.search' => '/',
        'list.refresh' => 'F5',
        'list.next-page' => 'alt+ArrowRight',
        'list.prev-page' => 'alt+ArrowLeft',
        'list.next-row' => 'j', // vim giù
        'list.prev-row' => 'k', // vim su
        'list.toggle-row' => 'Space', // attiva/disattiva la casella della riga attiva
        'list.select-next-row' => 'shift+j', // estendi la selezione verso il basso
        'list.select-prev-row' => 'shift+k', // estendi la selezione verso l'alto

        // Interfaccia — Ctrl+Alt, identico in tutte le lingue.
        'nav.goto' => 'g', // leader: apre la palette "Vai a"
        'nav.dashboard' => 'alt+ArrowUp', // home del panel
        'nav.profile' => 'ctrl+alt+p',
        'nav.logout' => 'ctrl+alt+q', // Q = quit
        'nav.language' => 'ctrl+alt+g', // G = globo 🌐
        'nav.recently-viewed' => 'ctrl+alt+r',
        'nav.notifications' => 'ctrl+alt+n',
        'ui.help' => '?',
        'ui.close' => 'Escape',
        'ui.next-tab' => 'alt+ArrowDown',
    ],
];
