<?php

return [
    'slug' => 'spanish-default',
    'name' => 'Español (predeterminado)',
    'description' => 'Acciones en sus iniciales españolas (Alt+C = Crear)',
    'locale' => 'es',
    'version' => '2.0',
    'author' => 'blemli/filament-mouseless',

    'bindings' => [
        // Nivel de envío — idéntico en todos los idiomas ('mod' = ⌘ en macOS, Ctrl en el resto).
        'crud.submit' => 'mod+Enter',
        'crud.save' => 'mod+s',
        'crud.create-another' => 'mod+shift+Enter',
        'list.select-all' => 'mod+a', // solo en modo lista, nunca dentro de campos
        'record.copy-markdown' => 'mod+c', // solo cuando no hay nada seleccionado
        'record.print' => 'mod+p', // cheatsheet → acción de imprimir → impresión del navegador
        'nav.command-palette' => 'mod+k',

        // Acciones de registro — Alt + inicial.
        'crud.create' => 'alt+c', // Crear
        'crud.edit' => 'alt+e', // Editar
        'crud.view' => 'alt+v', // Ver
        'crud.delete' => 'alt+b', // Borrar
        'crud.force-delete' => 'alt+f', // Forzar borrado
        'crud.restore' => 'alt+r', // Restaurar
        'crud.duplicate' => 'alt+shift+r', // Replicar
        'crud.attach' => 'alt+shift+v', // Vincular (attach / associate)
        'crud.detach' => 'alt+d', // Desvincular (detach / dissociate)
        // crud.cancel comparte Escape con ui.close (misma semántica).
        'record.history' => 'alt+h', // Historial
        'record.merge' => 'alt+u', // Unir
        'record.split' => 'alt+s', // Separar
        'record.comment' => 'alt+n', // Nota
        'record.approve' => 'alt+a', // Aprobar
        'record.reject' => 'alt+x', // X = tachar, igual en todos los idiomas
        'record.archive' => 'alt+shift+a', // Archivar
        'record.favorite' => 'alt+m', // Marcar
        'record.watch' => 'alt+o', // Observar
        'record.lock' => 'alt+p', // Proteger
        'record.share' => 'alt+shift+s', // Compartir — S internacional de "Share"

        // Controles de tabla — Alt+Shift + inicial.
        'list.filter' => 'alt+shift+f', // Filtros
        'list.group' => 'alt+shift+g',
        'list.sort' => 'alt+shift+o', // Ordenar
        'list.columns' => 'alt+shift+c', // Columnas
        'list.bulk-action' => 'alt+shift+m', // Acciones masivas
        'list.export' => 'alt+shift+e', // Exportar
        'list.import' => 'alt+shift+i', // Importar

        // Navegación de lista — teclas fijas.
        'list.search' => '/',
        'list.refresh' => 'F5',
        'list.next-page' => 'alt+ArrowRight',
        'list.prev-page' => 'alt+ArrowLeft',
        'list.next-row' => 'j', // vim abajo
        'list.prev-row' => 'k', // vim arriba
        'list.toggle-row' => 'Space', // alternar la casilla de la fila enfocada
        'list.select-next-row' => 'shift+j', // extender la selección hacia abajo
        'list.select-prev-row' => 'shift+k', // extender la selección hacia arriba

        // Interfaz — Ctrl+Alt, idéntico en todos los idiomas.
        'nav.goto' => 'g', // leader: abre la paleta "Ir a"
        'nav.dashboard' => 'alt+ArrowUp', // inicio del panel
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
