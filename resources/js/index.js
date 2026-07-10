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

        if (id === 'list.next-row' || id === 'list.prev-row' || id === 'list.toggle-row') {
            return !!document.querySelector('.fi-ta-row, .fi-ta-record');
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
