// filament-mouseless — keymap engine
//
// Reads window.filamentData.mouseless (injected by FilamentAsset::registerScriptData),
// listens for keydown globally, and triggers each matching Filament action by
// clicking its rendered button.
//
// Verbose by default — every step logs to the console. Set
// mouseless.debug=false in config to quiet things down later.

const TAG = '[mouseless]';

function bootMouseless() {
    console.log(TAG, '=== boot starting ===');
    console.log(TAG, 'document.readyState=', document.readyState);
    console.log(TAG, 'window.filamentData=', window.filamentData);

    // Arm the keyboard probe before anything else — it must work even if
    // bindings fail to load, since it controls the mobile menu reveal.
    setupKeyboardProbe();

    const cfg = (window.filamentData && window.filamentData.mouseless) || {};
    console.log(TAG, 'mouseless cfg=', cfg);

    const bindings = cfg.bindings || {};
    const reserved = new Set((cfg.reserved || []).map(normalize));
    const defaultIgnore = ['input', 'textarea', '[contenteditable]'];
    const ignoreList = Array.isArray(cfg.listModeIgnore) && cfg.listModeIgnore.length
        ? cfg.listModeIgnore
        : defaultIgnore;
    const ignoreSel = ignoreList.join(',');
    const strings = cfg.strings || {};

    // ---- "go to" palette state (nav.goto leader-key overlay) ----
    let gotoOpen = false;
    let gotoQuery = '';
    let gotoItems = [];   // current <a data-mouseless-goto-item> nodes
    let gotoActive = -1;  // index into gotoItems of the highlighted row
    let gotoEl = null;    // the [data-mouseless-goto] wrapper element

    // Anchor row for shift+j / shift+k range selection. A DOM node, not an
    // index, because rows re-render on sort / filter / paginate. Null between
    // gestures; a plain j/k, a Space-toggle, or an Escape-deselect resets it.
    let selectAnchorRow = null;

    console.log(TAG, 'bindings count=', Object.keys(bindings).length);
    console.log(TAG, 'bindings=', bindings);
    console.log(TAG, 'reserved=', [...reserved]);
    console.log(TAG, 'ignoreSel=', JSON.stringify(ignoreSel));

    if (!Object.keys(bindings).length) {
        console.error(TAG, 'NO BINDINGS LOADED. Cause matrix:');
        console.error(TAG, '  - window.filamentData missing entirely → Filament asset emission did not run for this request (check that this page goes through a Filament panel).');
        console.error(TAG, '  - window.filamentData present but no .mouseless key → ServiceProvider did not register script data (check FilamentMouselessServiceProvider::packageBooted ran).');
        console.error(TAG, '  - .mouseless present but bindings empty → BindingResolver returned no preset. Check the configured default_preset slug exists in config/mouseless/presets/.');
        return;
    }

    const keyToAction = {};
    for (const [actionId, key] of Object.entries(bindings)) {
        if (!key) continue;
        const n = normalize(key);
        if (reserved.has(n)) {
            console.log(TAG, 'skipping reserved binding', actionId, '=', n);
            continue;
        }
        if (keyToAction[n] && keyToAction[n] !== actionId) {
            console.warn(TAG, 'COLLISION', n, '->', keyToAction[n], 'vs', actionId, '(later wins)');
        }
        keyToAction[n] = actionId;
    }

    console.log(TAG, 'keyToAction map=', keyToAction);
    console.log(TAG, 'attaching keydown listener (capture)');

    document.addEventListener('keydown', onKeydown, true);

    // When the help overlay opens, grey out shortcuts whose target isn't on the
    // current page. The server pre-marks custom rows via their onPage flag; this
    // re-checks live so it stays correct after Livewire updates / SPA nav.
    window.addEventListener('mouseless-help', () => {
        setTimeout(() => {
            const overlay = document.querySelector('[data-mouseless-overlay]');
            if (overlay && isVisible(overlay)) probeHelpAvailability();
        }, 60);
    });

    // Shared with the Blade key-capture snippets (recording cell, key-search
    // modal) so combo normalization can never drift from the engine's.
    window.mouselessEventToKey = eventToKey;
    console.log(TAG, '=== boot complete ===');

    // Toggle the "unavailable" class on every help row whose action can't be
    // resolved on the current page. Conservative: only greys out when we are
    // confident the target is absent, so an available action is never dimmed.
    function probeHelpAvailability() {
        const rows = document.querySelectorAll('[data-mouseless-help-item]');
        rows.forEach(row => {
            // Read-only (native) rows keep the server-rendered availability —
            // their buttons carry no data-mouseless hook to probe.
            if (row.hasAttribute('data-mouseless-help-readonly')) return;
            const id = row.getAttribute('data-mouseless-help-item');
            row.classList.toggle('fi-mouseless-help-item-unavailable', !probeAvailable(id));
        });
    }

    function probeAvailable(id) {
        // The command palette maps to Filament's global search — available only
        // when that field is actually rendered on the current page.
        if (id === 'nav.command-palette') return !!findGlobalSearchInput(document);

        // UI and navigation actions are always reachable.
        if (id.startsWith('ui.') || id.startsWith('nav.')) return true;

        // Managed custom actions expose a data-mouseless hook on their button.
        if (id.startsWith('custom.')) {
            const el = document.querySelector(`[data-mouseless="${id}"]`);
            return !!(el && isVisible(el));
        }

        if (id === 'crud.create') {
            if (document.querySelector('a[href$="/create"]')) return true;
            if (/^(\/[^\/]+\/[^\/]+)\/(create|\d+(\/(edit|view))?)$/.test(window.location.pathname)) return true;
            return !!cfg.rootResource;
        }

        if (id === 'list.next-row' || id === 'list.prev-row' || id === 'list.toggle-row'
            || id === 'list.select-next-row' || id === 'list.select-prev-row') {
            return !!document.querySelector('.fi-ta-row, .fi-ta-record');
        }

        if (id === 'list.search') {
            return !!findTableSearchInput(document);
        }

        const names = filamentActionNames(id);
        if (!names.length) return true; // Unknown action type — never dim.
        for (const name of names) {
            if (findActionButton(name, document, true)) return true;
        }
        return false;
    }

    function onKeydown(e) {
        // Skip pure-modifier keys without spamming the log.
        if (['Control', 'Alt', 'Shift', 'Meta'].includes(e.key)) return;

        // The "go to" palette owns every keystroke while it's open.
        if (gotoOpen) { handleGotoKey(e); return; }

        // The my-shortcuts page is capturing a combo (recording / key search)
        // — stay out of the way so the captured key never triggers an action.
        if (document.querySelector('[data-mouseless-recording]')) return;

        const pressed = eventToKey(e);
        const inInput = isTextInputFocus(e.target);
        // isBare = "would type a character if focus were in a textbox" — used to
        // suppress letter/punctuation/space shortcuts inside inputs. Excludes
        // named keys (Escape, Tab, F-keys, arrows) so those still close modals etc.
        const isPrintable = e.key.length === 1 || e.code === 'Space';
        const isBare  = isPrintable && !e.altKey && !e.ctrlKey && !e.metaKey;
        const pressedNoShift = pressed.replace(/^shift\+|(\+)shift\+/, '$1');
        const actionId = keyToAction[pressed] || keyToAction[pressedNoShift];

        console.log(TAG, 'keydown', {
            key: e.key,
            code: e.code,
            alt: e.altKey, ctrl: e.ctrlKey, meta: e.metaKey, shift: e.shiftKey,
            pressed,
            pressedNoShift: pressedNoShift !== pressed ? pressedNoShift : undefined,
            inInput,
            isBare,
            targetTag: e.target?.tagName,
            actionId: actionId || '(no match)',
        });

        if (inInput && isBare) {
            console.log(TAG, 'suppressed bare key inside input/textarea');
            return;
        }
        if (!actionId) return;

        // Defer Escape to Filament when a modal is open — its own handler closes it.
        if (actionId === 'ui.close' && hasOpenModal()) {
            console.log(TAG, 'ui.close: modal open, deferring to Filament');
            return;
        }

        console.log(TAG, 'matched action', actionId, '— dispatching');
        e.preventDefault();
        e.stopPropagation();
        dispatch(actionId);
    }

    function hasOpenModal() {
        const modal = document.querySelector('.fi-modal-window');
        return !!(modal && isVisible(modal));
    }

    function dispatch(actionId) {
        console.log(TAG, 'dispatch start', actionId);

        // UI actions
        if (actionId === 'ui.help') {
            console.log(TAG, 'dispatch -> ui.help (window event "mouseless-help")');
            window.dispatchEvent(new CustomEvent('mouseless-help'));
            return;
        }
        if (actionId === 'ui.close') {
            // Priority chain — first match wins:
            //   1. Help overlay open → close it.
            //   2. Selected rows on the page → clear selection.
            //   3. /create, /{id}, /{id}/edit, /{id}/view → navigate back to index.
            //   4. Fall through → blur active element.
            const overlay = document.querySelector('[data-mouseless-overlay]');
            if (overlay && isVisible(overlay)) {
                console.log(TAG, 'dispatch -> ui.close: help overlay open, closing it');
                window.dispatchEvent(new CustomEvent('mouseless-help'));
                return;
            }
            const selected = document.querySelectorAll('.fi-ta-row.fi-selected, .fi-ta-record.fi-selected');
            if (selected.length) {
                selectAnchorRow = null; // clearing the selection ends any shift-select gesture
                // Livewire.dispatch('deselectAllTableRecords') does NOT reach Filament's
                // $wire.$on() listener for this event in Livewire 3 — call the Alpine
                // method on each table component directly (handles N tables on one page).
                const tables = document.querySelectorAll('[x-data*="filamentTable"]');
                console.log(TAG, 'dispatch -> ui.close: deselecting', selected.length, 'row(s) across', tables.length, 'table(s)');
                let cleared = 0;
                tables.forEach(el => {
                    const data = window.Alpine?.$data?.(el);
                    if (typeof data?.deselectAllRecords === 'function') {
                        data.deselectAllRecords();
                        cleared++;
                    }
                });
                if (!cleared) {
                    console.warn(TAG, 'no filamentTable Alpine component responded — falling back to per-checkbox click');
                    document.querySelectorAll('.fi-ta-record-checkbox:checked').forEach(cb => cb.click());
                }
                return;
            }
            const path = window.location.pathname;
            const m = path.match(/^(\/[^\/]+\/[^\/]+)\/(create|\d+(\/(edit|view))?)$/);
            if (m) {
                console.log(TAG, 'dispatch -> ui.close: back to', m[1]);
                return goUrl(m[1]);
            }
            console.log(TAG, 'dispatch -> ui.close (blur active element)');
            document.activeElement?.blur();
            return;
        }

        // Navigation actions
        if (actionId.startsWith('nav.')) {
            if (actionId === 'nav.goto') { openGoto(); return; }
            // The command palette IS Filament's top-bar global search. Its input
            // focuses itself via x-mousetrap on cmd/ctrl+k, but mouseless owns that
            // combo in capture phase (preventDefault + stopPropagation), so we must
            // focus the field ourselves — otherwise cmd+k just gets swallowed.
            if (actionId === 'nav.command-palette') {
                const input = findGlobalSearchInput(document);
                if (input) {
                    console.log(TAG, 'dispatch -> nav.command-palette: focusing global search');
                    input.focus();
                    input.select?.();
                    return;
                }
                console.warn(TAG, 'nav.command-palette: no global search field on this page');
                toast(strings.no_match);
                return;
            }
            if (clickByData(actionId, false)) {
                console.log(TAG, 'dispatch -> nav via [data-mouseless="' + actionId + '"]');
                return;
            }
            if (actionId === 'nav.profile')   { console.log(TAG, 'dispatch -> /admin/my-shortcuts'); return goUrl('/admin/my-shortcuts'); }
            if (actionId === 'nav.dashboard') {
                // Prefer the panel's real home URL (where Filament lands you
                // after login). It need not be "/admin" — any panel path works.
                if (cfg.homeUrl) {
                    console.log(TAG, 'dispatch -> nav.dashboard: home URL', cfg.homeUrl);
                    return goUrl(cfg.homeUrl);
                }
                const navLinks = document.querySelectorAll('.fi-sidebar-nav a.fi-sidebar-item-button, .fi-sidebar a.fi-sidebar-item-button');
                console.log(TAG, 'dispatch -> nav.dashboard: found', navLinks.length, 'sidebar link(s)');
                for (const link of navLinks) {
                    if (isVisible(link)) {
                        console.log(TAG, '  clicking first visible sidebar item:', link);
                        link.click();
                        return;
                    }
                }
                console.log(TAG, '  no sidebar item visible — falling back to /admin');
                return goUrl('/admin');
            }
            if (actionId.startsWith('nav.resource.')) {
                const url = `/admin/${actionId.replace('nav.resource.', '')}`;
                console.log(TAG, 'dispatch -> resource', url);
                return goUrl(url);
            }
            console.warn(TAG, 'nav action without target', actionId);
            toast(strings.no_match);
            return;
        }

        // list.next-row / list.prev-row — vim-style cursor through Filament table rows.
        // Filament v5 ships two table styles:
        //   - cell-style:  <tr class="fi-ta-row">   with <td><a class="fi-ta-col"> cells
        //   - card-style:  <div class="fi-ta-record"> with .fi-ta-record-content link/button
        // We pick the best focusable per row and let Enter activate it natively.
        if (actionId === 'list.next-row' || actionId === 'list.prev-row') {
            const dir = actionId === 'list.next-row' ? 1 : -1;
            selectAnchorRow = null; // a plain move ends any shift-select gesture
            const rowElements = Array.from(document.querySelectorAll('.fi-ta-row, .fi-ta-record')).filter(isVisible);
            console.log(TAG, 'dispatch -> list row nav', actionId, 'rows=', rowElements.length);
            if (!rowElements.length) {
                toast(strings.no_match);
                return;
            }
            const focusedRow = document.activeElement?.closest?.('.fi-ta-row, .fi-ta-record');
            const currentIdx = focusedRow ? rowElements.indexOf(focusedRow) : -1;
            const len = rowElements.length;
            const nextIdx = currentIdx === -1
                ? (dir > 0 ? 0 : len - 1)
                : (currentIdx + dir + len) % len;
            const target = rowFocusTarget(rowElements[nextIdx]);
            console.log(TAG, '  focus row', currentIdx, '->', nextIdx, 'target=', target);
            target.focus();
            rowElements[nextIdx].scrollIntoView({ block: 'nearest' });
            return;
        }

        // list.select-next-row / list.select-prev-row — shift+j / shift+k grow or
        // shrink a contiguous selection, spreadsheet-style. An anchor row is pinned
        // on the first shift-move; each press moves the cursor one row (clamped —
        // never wrapping) and selects exactly the rows between the anchor and the
        // cursor, so shifting back past the anchor deselects again.
        if (actionId === 'list.select-next-row' || actionId === 'list.select-prev-row') {
            const dir = actionId === 'list.select-next-row' ? 1 : -1;
            const rows = Array.from(document.querySelectorAll('.fi-ta-row, .fi-ta-record')).filter(isVisible);
            console.log(TAG, 'dispatch -> row select', actionId, 'rows=', rows.length);
            if (!rows.length) {
                toast(strings.no_match);
                return;
            }

            const focusedRow = document.activeElement?.closest?.('.fi-ta-row, .fi-ta-record');
            let currentIdx = focusedRow ? rows.indexOf(focusedRow) : -1;

            // (Re)establish the anchor when this is a fresh gesture or the pinned
            // row has since left the DOM (re-sorted / filtered / paginated away).
            let anchorIdx = selectAnchorRow ? rows.indexOf(selectAnchorRow) : -1;
            if (anchorIdx === -1) {
                anchorIdx = currentIdx === -1 ? (dir > 0 ? 0 : rows.length - 1) : currentIdx;
                selectAnchorRow = rows[anchorIdx];
                currentIdx = anchorIdx; // the first shift-move includes the anchor row itself
            }

            const nextIdx = Math.min(Math.max(currentIdx + dir, 0), rows.length - 1);
            rowFocusTarget(rows[nextIdx]).focus();
            rows[nextIdx].scrollIntoView({ block: 'nearest' });

            // Drive selection to exactly [anchor, cursor]; flip only what differs
            // so one step toggles one checkbox (one Livewire round-trip, not N).
            const lo = Math.min(anchorIdx, nextIdx);
            const hi = Math.max(anchorIdx, nextIdx);
            let flipped = 0, withCheckbox = 0;
            rows.forEach((row, i) => {
                const cb = row.querySelector('.fi-ta-record-checkbox');
                if (!cb) return;
                withCheckbox++;
                const desired = i >= lo && i <= hi;
                if (cb.checked !== desired) { cb.click(); flipped++; }
            });
            console.log(TAG, '  select range', lo + '..' + hi, 'anchor=', anchorIdx, 'cursor=', nextIdx, 'flipped=', flipped);
            if (!withCheckbox) console.warn(TAG, actionId, ': table has no selection checkboxes — moved cursor only');
            return;
        }

        // list.search — the table search is an <input>, not a button, so the
        // generic action finder never reaches it. Focus it directly (preferring
        // the table the cursor is in when several are on the page) and select
        // any existing text so the next keystroke replaces the query.
        if (actionId === 'list.search') {
            const focusedTable = document.activeElement?.closest?.('[x-data*="filamentTable"]');
            const input = (focusedTable && findTableSearchInput(focusedTable)) || findTableSearchInput(document);
            console.log(TAG, 'dispatch -> list.search, input=', input);
            if (input) {
                input.focus();
                input.select?.();
                return;
            }
            console.warn(TAG, 'list.search: no table search input on this page');
            toast(strings.no_match);
            return;
        }

        // list.filter — Filament v5 renders the filter trigger as an Alpine-bound
        // button (x-on:click="toggleFiltersDropdown"), not as a mountAction wire-click,
        // so the generic action finder misses it. Click the trigger directly.
        if (actionId === 'list.filter') {
            const trigger = document.querySelector('[x-on\\:click*="toggleFiltersDropdown"]');
            console.log(TAG, 'dispatch -> list.filter, trigger=', trigger);
            if (trigger && isVisible(trigger)) {
                trigger.click();
                return;
            }
            // Fall through to generic action lookup (modal-style custom filters).
        }

        // list.toggle-row — flip the focused row's selection checkbox.
        if (actionId === 'list.toggle-row') {
            selectAnchorRow = null; // a manual toggle breaks the shift-select range
            const row = document.activeElement?.closest?.('.fi-ta-row, .fi-ta-record');
            console.log(TAG, 'dispatch -> list.toggle-row, row=', row);
            if (!row) {
                console.warn(TAG, 'list.toggle-row: no focused row');
                return;
            }
            const checkbox = row.querySelector('.fi-ta-record-checkbox');
            if (!checkbox) {
                console.warn(TAG, 'list.toggle-row: row has no .fi-ta-record-checkbox (selection disabled?)');
                return;
            }
            console.log(TAG, '  clicking checkbox', checkbox);
            checkbox.click();
            return;
        }

        // crud.create — URL-based navigation.
        //   1. Existing /create link on the page → use it (resource list / header).
        //   2. URL is a resource detail page (create/edit/view/show) → /{panel}/{slug}/create.
        //   3. Otherwise → fall back to cfg.rootResource (config('app.root_resource')).
        if (actionId === 'crud.create') {
            const link = document.querySelector('a[href$="/create"]');
            if (link) {
                console.log(TAG, 'dispatch -> crud.create via existing link:', link.href);
                return goUrl(link.href);
            }
            const path = window.location.pathname;
            const m = path.match(/^(\/[^\/]+\/[^\/]+)\/(create|\d+(\/(edit|view))?)$/);
            if (m) {
                const url = m[1] + '/create';
                console.log(TAG, 'dispatch -> crud.create via resource URL:', url);
                return goUrl(url);
            }
            const root = cfg.rootResource;
            if (root) {
                const panel = path.split('/')[1] || 'admin';
                let url;
                if (root.startsWith('/')) {
                    url = root.endsWith('/create') ? root : `${root}/create`;
                } else {
                    url = `/${panel}/${root}/create`;
                }
                console.log(TAG, 'dispatch -> crud.create via root_resource:', url);
                return goUrl(url);
            }
            console.warn(TAG, 'crud.create: no /create link, not a resource page, and no root_resource configured (config/app.php → root_resource)');
            toast(strings.no_match);
            return;
        }

        // Filament action — find the rendered button and click it.
        // Row-level actions (edit/delete/view/duplicate) only make sense for the
        // row the user has cursored to. Without scoping, querySelectorAll returns
        // every row's button on a list page and the first one (row 0) always wins.
        const candidates = filamentActionNames(actionId);
        const rowScoped = ROW_LEVEL_ACTIONS.has(actionId);
        const focusedRow = document.activeElement?.closest?.('.fi-ta-row, .fi-ta-record');
        const scope = (rowScoped && focusedRow) ? focusedRow : document;
        console.log(TAG, 'looking for Filament action button. Candidates:', candidates, 'scope=', scope === document ? 'document' : 'focusedRow');

        if (rowScoped && !focusedRow && document.querySelector('.fi-ta-row, .fi-ta-record')) {
            console.warn(TAG, actionId, 'requires a focused row — press j/k first');
            toast(strings.no_match);
            return;
        }

        for (const name of candidates) {
            const btn = findActionButton(name, scope);
            console.log(TAG, '  candidate', name, '->', btn ? 'FOUND' : 'not found');
            if (btn) {
                console.log(TAG, '  button el=', btn);
                btn.click();
                console.log(TAG, '  click dispatched');
                return;
            }
        }

        if (clickByData(actionId, false)) {
            console.log(TAG, 'fallback: clicked [data-mouseless="' + actionId + '"]');
            return;
        }

        console.warn(TAG, 'NO MATCH for', actionId, '— no button, no data-attribute target');
        toast(strings.no_match);
    }

    const ROW_LEVEL_ACTIONS = new Set(['crud.edit', 'crud.delete', 'crud.view', 'crud.duplicate']);

    function findActionButton(actionName, scope = document, quiet = false) {
        const log = (...args) => { if (!quiet) console.log(...args); };

        // Strategy 1: wire:click="mountAction('name'…)" — modal/method actions
        const wireSelectors = [
            `[wire\\:click^="mountAction('${actionName}'"]`,
            `[wire\\:click^="mountAction(&quot;${actionName}&quot;"]`,
            `[wire\\:click="${actionName}"]`,
            `[wire\\:click^="${actionName}("]`,
        ];
        for (const sel of wireSelectors) {
            const all = scope.querySelectorAll(sel);
            if (all.length) log(TAG, '    [wire] selector matched', all.length, 'el(s):', sel);
            for (const el of all) {
                if (isVisible(el) && !el.disabled) return el;
            }
        }

        // Strategy 2: route-based actions render as <a href=".../{actionName}">
        // Most common: create → /resources/{slug}/create
        if (actionName === 'create') {
            const links = scope.querySelectorAll('a.fi-ac-btn-action[href*="/create"], a.fi-btn[href*="/create"], header a[href$="/create"]');
            log(TAG, '    [href] create link matches:', links.length);
            for (const el of links) {
                if (isVisible(el)) return el;
            }
        }

        // Row-scoped href fallback: each row often has /{id}/edit, /{id}/view links.
        if (scope !== document && (actionName === 'edit' || actionName === 'view')) {
            const links = scope.querySelectorAll(`a[href$="/${actionName}"]`);
            log(TAG, '    [row-href] /' + actionName + ' link matches:', links.length);
            for (const el of links) {
                if (isVisible(el)) return el;
            }
        }

        // Strategy 3: form submit button for save (edit/create pages)
        if (actionName === 'save') {
            const submit = scope.querySelector('form[wire\\:submit] button[type="submit"], form[wire\\:submit="save"] button[type="submit"]');
            log(TAG, '    [form] submit button match:', submit);
            if (submit && isVisible(submit) && !submit.disabled) return submit;
        }

        // Strategy 4: localized label text matching, scoped to Filament action elements
        const labels = (window.filamentData?.mouseless?.actionLabels || {})[actionName] || [];
        if (labels.length) {
            log(TAG, '    [label] trying labels:', labels);
            const candidates = scope.querySelectorAll('.fi-ac-btn-action, .fi-ac-link-action, .fi-ac-icon-btn-action, .fi-ac-badge-action');
            for (const el of candidates) {
                if (!isVisible(el) || el.disabled) continue;
                const text = (el.textContent || '').replace(/\s+/g, ' ').trim();
                for (const label of labels) {
                    if (text.toLowerCase() === label.toLowerCase()) {
                        log(TAG, '    [label] matched on text:', text);
                        return el;
                    }
                }
            }
        }

        return null;
    }

    function isVisible(el) {
        return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
    }

    // True only for elements that accept text input. <input type="checkbox|radio|button|...">
    // does NOT count — clicking a row's selection checkbox shouldn't break j/k navigation.
    function isTextInputFocus(target) {
        if (!target) return false;
        if (target.isContentEditable) return true;
        if (target.tagName === 'TEXTAREA') return true;
        if (target.tagName === 'INPUT') {
            const t = (target.type || 'text').toLowerCase();
            return !['checkbox', 'radio', 'button', 'submit', 'reset', 'file', 'image', 'hidden', 'color', 'range'].includes(t);
        }
        return false;
    }

    // Best focusable inside a .fi-ta-record. Prefers the native row link/button
    // so Enter opens it; falls back to making the row itself focusable.
    function rowFocusTarget(row) {
        const content = row.querySelector('.fi-ta-record-content');
        if (content && (content.tagName === 'A' || content.tagName === 'BUTTON')) return content;
        const focusable = row.querySelector('a[href], button:not([disabled])');
        if (focusable) return focusable;
        if (!row.hasAttribute('tabindex')) row.setAttribute('tabindex', '-1');
        return row;
    }

    // The Filament table search box binds to $wire.tableSearch (the global
    // top-bar search binds to `search` — deliberately not matched here). The
    // attribute may be `wire:model` or a debounced variant, so match on the
    // bound property value rather than the exact attribute name.
    function findTableSearchInput(scope = document) {
        for (const el of scope.querySelectorAll('input[type="search"], input[type="text"]')) {
            for (const attr of el.attributes) {
                if (attr.name.startsWith('wire:model') && attr.value === 'tableSearch' && isVisible(el)) {
                    return el;
                }
            }
        }
        return null;
    }

    // Filament's top-bar global search input lives in .fi-global-search-field and
    // binds to $wire.search (distinct from the per-table `tableSearch`). This is
    // the target for nav.command-palette / cmd+k.
    function findGlobalSearchInput(scope = document) {
        const field = scope.querySelector('.fi-global-search-field');
        const input = field?.querySelector('input');
        if (input && isVisible(input)) return input;
        // Fallback: match the wire:model="search" binding directly.
        for (const el of scope.querySelectorAll('input[type="search"]')) {
            for (const attr of el.attributes) {
                if (attr.name.startsWith('wire:model') && attr.value === 'search' && isVisible(el)) {
                    return el;
                }
            }
        }
        return null;
    }

    function filamentActionNames(actionId) {
        const map = {
            'crud.create':     ['create'],
            'crud.edit':       ['edit'],
            'crud.delete':     ['delete'],
            'crud.save':       ['save'],
            'crud.view':       ['view'],
            'crud.duplicate':  ['replicate', 'duplicate'],
            'crud.cancel':     ['cancel'],
            'list.filter':     ['openFiltersModal', 'filter'],
            'list.search':     ['search'],
            'list.refresh':    ['refresh'],
            'list.export':     ['export'],
            'list.import':     ['import'],
            'list.select-all': ['selectAll'],
            'list.bulk-action': ['openBulkActionsModal'],
            'record.print':    ['print'],
            'record.history':  ['history', 'activityLog'],
            'record.comment':  ['comment'],
            'record.archive':  ['archive'],
            'record.approve':  ['approve'],
            'record.reject':   ['reject'],
            'record.merge':    ['merge'],
        };
        return map[actionId] ?? [];
    }

    function clickByData(actionId, fallbackToast = true) {
        const el = document.querySelector(`[data-mouseless="${actionId}"]`);
        if (el) { el.click(); return true; }
        if (fallbackToast) toast(strings.no_match);
        return false;
    }

    function goUrl(url) {
        try { window.location.href = url; return true; } catch { return false; }
    }

    function toast(msg) {
        if (!msg) return;
        console.info(TAG, 'toast:', msg);
        if (window.Livewire && window.Livewire.dispatch) {
            try { window.Livewire.dispatch('mouseless-toast', { message: msg }); return; } catch {}
        }
    }

    // ---------- "go to" palette ----------
    // Opened by the nav.goto leader key. Filters navigation targets as you type
    // (label prefix OR JetBrains-style camel-hump chord: "Product Categories" →
    // "pc"), auto-commits when exactly one remains, and lets Enter/arrows resolve
    // prefix ties (e.g. "Order" vs "Orders"). Rows are real
    // <a> links, so committing just follows the href. The list is server-rendered
    // once; all filtering happens here, client-side.

    function openGoto() {
        gotoEl = document.querySelector('[data-mouseless-goto]');
        if (!gotoEl) {
            console.warn(TAG, 'nav.goto: no palette on the page (disabled?)');
            toast(strings.no_match);
            return;
        }

        gotoItems = Array.from(gotoEl.querySelectorAll('[data-mouseless-goto-item]'));
        if (!gotoItems.length) {
            console.warn(TAG, 'nav.goto: palette has no navigation targets');
            return;
        }

        // Backdrop click closes; clicks on the panel fall through to the links.
        if (!gotoEl.dataset.wired) {
            gotoEl.dataset.wired = '1';
            gotoEl.addEventListener('click', (ev) => {
                if (!ev.target.closest('.fi-mouseless-goto-panel')) closeGoto();
            });
        }

        gotoQuery = '';
        gotoActive = -1;
        gotoOpen = true;
        gotoEl.style.display = 'block';
        filterGoto();
        console.log(TAG, 'nav.goto: opened,', gotoItems.length, 'targets');
    }

    function closeGoto() {
        gotoOpen = false;
        setGotoActive(-1);
        if (gotoEl) gotoEl.style.display = 'none';
    }

    function handleGotoKey(e) {
        if (['Control', 'Alt', 'Shift', 'Meta'].includes(e.key)) return;
        e.preventDefault();
        e.stopPropagation();

        const k = e.key;
        if (k === 'Escape') { closeGoto(); return; }
        if (k === 'Enter') { commitGoto(gotoActive); return; }
        if (k === 'ArrowDown' || (k === 'Tab' && !e.shiftKey)) { moveGoto(1); return; }
        if (k === 'ArrowUp' || (k === 'Tab' && e.shiftKey)) { moveGoto(-1); return; }
        if (k === 'Backspace') {
            if (gotoQuery) { gotoQuery = gotoQuery.slice(0, -1); filterGoto(); }
            return;
        }

        // A printable character extends the query — but only if something still
        // matches; otherwise reject it (shake) and keep the last good query.
        if (k.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
            const candidate = gotoQuery + k.toLowerCase();
            if (gotoMatchCount(candidate) > 0) {
                gotoQuery = candidate;
                filterGoto();
            } else {
                shakeGoto();
            }
        }
    }

    function gotoMatches(item, q) {
        if (!q) return true;
        const label = item.dataset.gotoLabel || '';
        const chord = item.dataset.gotoChord || '';
        return label.startsWith(q) || (!!chord && chord.startsWith(q));
    }

    function gotoMatchCount(q) {
        let n = 0;
        for (const it of gotoItems) if (gotoMatches(it, q)) n++;
        return n;
    }

    function filterGoto() {
        updateGotoQuery();

        let firstVisible = -1;
        let visibleCount = 0;
        gotoItems.forEach((el, i) => {
            const on = gotoMatches(el, gotoQuery);
            el.hidden = !on;
            if (on) {
                visibleCount++;
                if (firstVisible === -1) firstVisible = i;
            }
        });

        // Collapse group headings that have no visible items under them.
        gotoEl.querySelectorAll('[data-mouseless-goto-group]').forEach((g) => {
            g.hidden = !g.querySelector('[data-mouseless-goto-item]:not([hidden])');
        });

        // Exactly one target left → jump immediately. Guarded on a non-empty
        // query so merely opening the palette never auto-navigates.
        if (gotoQuery !== '' && visibleCount === 1) {
            commitGoto(firstVisible);
            return;
        }

        // Keep the highlight if it survived the filter, else fall to the first.
        if (gotoActive < 0 || gotoItems[gotoActive].hidden) {
            setGotoActive(firstVisible);
        }
    }

    function setGotoActive(i) {
        if (gotoActive >= 0 && gotoItems[gotoActive]) {
            gotoItems[gotoActive].style.backgroundColor = '';
            gotoItems[gotoActive].style.boxShadow = '';
        }
        gotoActive = i;
        if (i < 0 || !gotoItems[i]) return;
        const el = gotoItems[i];
        // App primary colour, no stylesheet: a soft tint + a left accent bar,
        // matching the table-row highlight elsewhere in the plugin.
        el.style.backgroundColor = 'color-mix(in oklab, var(--primary-500) 15%, transparent)';
        el.style.boxShadow = 'inset 3px 0 0 0 var(--primary-500)';
        el.scrollIntoView({ block: 'nearest' });
    }

    function moveGoto(dir) {
        const visible = [];
        gotoItems.forEach((el, i) => { if (!el.hidden) visible.push(i); });
        if (!visible.length) return;
        let pos = visible.indexOf(gotoActive);
        pos = pos === -1
            ? (dir > 0 ? 0 : visible.length - 1)
            : (pos + dir + visible.length) % visible.length;
        setGotoActive(visible[pos]);
    }

    function commitGoto(i) {
        const el = i >= 0 ? gotoItems[i] : null;
        if (!el) return;
        const url = el.dataset.gotoUrl;
        console.log(TAG, 'nav.goto: commit ->', url);
        closeGoto();
        goUrl(url);
    }

    function updateGotoQuery() {
        const textEl = gotoEl.querySelector('[data-mouseless-goto-text]');
        if (textEl) textEl.textContent = gotoQuery;
        const ph = gotoEl.querySelector('[data-mouseless-goto-placeholder]');
        if (ph) ph.style.display = gotoQuery ? 'none' : '';
    }

    function shakeGoto() {
        const box = gotoEl.querySelector('[data-mouseless-goto-query]');
        if (!box || typeof box.animate !== 'function') return;
        box.animate(
            [
                { transform: 'translateX(0)' },
                { transform: 'translateX(-5px)' },
                { transform: 'translateX(5px)' },
                { transform: 'translateX(0)' },
            ],
            { duration: 160 },
        );
    }

    function normalize(key) {
        if (!key) return '';
        const parts = key.split('+').map(s => s.trim().toLowerCase());
        const mods  = ['ctrl', 'cmd', 'meta', 'alt', 'shift'];
        const m = mods.filter(x => parts.includes(x));
        const k = parts.find(x => !mods.includes(x)) || '';
        return [...m, k].join('+');
    }

    function eventToKey(e) {
        const parts = [];
        if (e.ctrlKey)  parts.push('ctrl');
        if (e.metaKey)  parts.push('cmd');
        if (e.altKey)   parts.push('alt');
        if (e.shiftKey) parts.push('shift');

        let k = e.key;
        const hasModifier = e.ctrlKey || e.metaKey || e.altKey;

        if (e.code === 'Space') {
            // e.key === ' ' would normalize to '' (empty) — name it explicitly.
            k = 'space';
        } else if (hasModifier && /^Key[A-Z]$/.test(e.code)) {
            k = e.code.slice(3).toLowerCase();
        } else if (hasModifier && /^Digit[0-9]$/.test(e.code)) {
            k = e.code.slice(5);
        } else if (k === 'Dead' && /^Key[A-Z]$/.test(e.code)) {
            k = e.code.slice(3).toLowerCase();
        } else {
            k = k.toLowerCase();
        }

        parts.push(k);
        return parts.join('+');
    }
}

// Heuristic keyboard probe: once the user fires any "real" hardware keydown,
// remember "this browser has a keyboard" in localStorage and reveal the
// mobile-hidden shortcuts menu immediately by adding `fi-mouseless-kbd` to
// the body class (the inline boot script reapplies it on every page load).
// No way to ask the browser "is there a keyboard?" directly — but a phone
// without one never produces these events, and a phone WITH a Bluetooth
// keyboard does. Soft keyboards are filtered via keyCode 229 / Unidentified.
function setupKeyboardProbe() {
    if (window.mouselessKeyboardProbeDisabled) {
        console.log(TAG, 'keyboard probe: disabled, skipping');
        return;
    }
    let alreadyDetected = false;
    try { alreadyDetected = localStorage.getItem('mouseless_kbd') === '1'; } catch (e) {}
    if (alreadyDetected) {
        console.log(TAG, 'keyboard probe: already detected (localStorage), skipping');
        return;
    }
    const onProbeKeydown = (e) => {
        if (!e.isTrusted) return;
        if (e.keyCode === 229 || e.key === 'Unidentified') return; // IME / soft keyboard
        if (['Control', 'Alt', 'Shift', 'Meta'].includes(e.key)) return;
        // If a text input is focused, only count modifier-combo keys — a single
        // letter could be the user tapping a soft keyboard.
        const t = e.target;
        const inEditable = t && (
            t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable
        );
        if (inEditable && !(e.altKey || e.ctrlKey || e.metaKey)) return;

        console.log(TAG, 'keyboard probe: hardware key detected, revealing menu');
        try { localStorage.setItem('mouseless_kbd', '1'); } catch (e) {}
        if (document.body) document.body.classList.add('fi-mouseless-kbd');
        document.removeEventListener('keydown', onProbeKeydown, true);
    };
    document.addEventListener('keydown', onProbeKeydown, true);
    console.log(TAG, 'keyboard probe armed');
}

console.log(TAG, 'script tag loaded, readyState=', document.readyState);
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootMouseless);
} else {
    bootMouseless();
}
