<?php

return [
    'slug' => 'french-default',
    'name' => 'Français (par défaut)',
    'description' => 'Actions sur leurs initiales françaises (Alt+C = Créer)',
    'locale' => 'fr',
    'version' => '2.0',
    'author' => 'blemli/filament-mouseless',

    'bindings' => [
        // Niveau soumission — identique dans toutes les langues ('mod' = ⌘ sur macOS, Ctrl ailleurs).
        'crud.submit' => 'mod+Enter',
        'crud.save' => 'mod+s',
        'crud.create-another' => 'mod+shift+Enter',
        'list.select-all' => 'mod+a', // uniquement en mode liste, jamais dans un champ
        'record.copy-markdown' => 'mod+c', // uniquement quand rien n'est sélectionné
        'record.print' => 'mod+p', // cheatsheet → action d'impression → impression du navigateur
        'nav.command-palette' => 'mod+k',

        // Actions d'enregistrement — Alt + initiale.
        'crud.create' => 'alt+c', // Créer
        'crud.edit' => 'alt+m', // Modifier
        'crud.view' => 'alt+v', // Voir
        'crud.delete' => 'alt+s', // Supprimer
        'crud.force-delete' => 'alt+shift+s', // Supprimer définitivement — le Suppr « renforcé »
        'crud.restore' => 'alt+r', // Restaurer
        'crud.duplicate' => 'alt+d', // Dupliquer
        'crud.attach' => 'alt+l', // Lier (attach / associate)
        'crud.detach' => 'alt+shift+d', // Détacher (detach / dissociate)
        // crud.cancel partage Escape avec ui.close (même sémantique).
        'record.history' => 'alt+h', // Historique
        'record.merge' => 'alt+f', // Fusionner
        'record.split' => 'alt+e', // Éclater
        'record.comment' => 'alt+n', // Noter
        'record.approve' => 'alt+a', // Approuver
        'record.reject' => 'alt+x', // X = barrer, identique dans toutes les langues
        'record.archive' => 'alt+shift+a', // Archiver
        'record.favorite' => 'alt+shift+p', // Préféré
        'record.watch' => 'alt+o', // Observer
        'record.lock' => 'alt+b', // Bloquer
        'record.share' => 'alt+p', // Partager

        // Contrôles de table — Alt+Shift + initiale.
        'list.filter' => 'alt+shift+f', // Filtres
        'list.group' => 'alt+shift+g',
        'list.sort' => 'alt+shift+t', // Trier
        'list.columns' => 'alt+shift+c', // Colonnes
        'list.bulk-action' => 'alt+shift+m', // Actions multiples
        'list.export' => 'alt+shift+e', // Exporter
        'list.import' => 'alt+shift+i', // Importer

        // Navigation de liste — touches fixes.
        'list.search' => '/',
        'list.refresh' => 'F5',
        'list.next-page' => 'alt+ArrowRight',
        'list.prev-page' => 'alt+ArrowLeft',
        'list.next-row' => 'j', // vim bas
        'list.prev-row' => 'k', // vim haut
        'list.toggle-row' => 'Space', // cocher/décocher la ligne active
        'list.select-next-row' => 'shift+j', // étendre la sélection vers le bas
        'list.select-prev-row' => 'shift+k', // étendre la sélection vers le haut

        // Interface — Ctrl+Alt, identique dans toutes les langues.
        'nav.goto' => 'g', // leader : ouvre la palette « Aller à »
        'nav.dashboard' => 'alt+ArrowUp', // accueil du panel
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
