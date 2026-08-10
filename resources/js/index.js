// filament-mouseless — keymap engine
//
// Reads window.filamentData.mouseless (injected by FilamentAsset::registerScriptData),
// listens for keydown globally, and triggers each matching Filament action by
// clicking its rendered button.
//
// Verbose by default — every step logs to the console. Set
// mouseless.debug=false in config to quiet things down later.

const TAG = '[mouseless]';

// Platform check for resolving the 'mod' modifier (⌘ on macOS, Ctrl elsewhere).
const IS_MAC_PLATFORM = /Mac|iPhone|iPad|iPod/.test(navigator.platform || '');

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

    // ---- jump mode state (double-tap modifier → stable-hash label badges) ----
    let jumpOpen = false;
    let jumpQuery = '';
    let jumpTargets = []; // [{ el, label, commit, badge, typed, rest }]
    let jumpMoveItem = null;      // x-sortable-item key of the grabbed row
    let jumpMoveContainer = null; // its [x-sortable] container
    let jumpMoveFromIndex = -1;   // index at grab time — the single 'end' dispatch needs it
    let jumpPlacedItem = null;      // last Enter-dropped row — keeps its outline
    let jumpPlacedContainer = null; // until the next grab / mouse / mode exit
    let jumpOutlineTimer = 0;       // re-asserts outlines wiped by Livewire morphs
    let jumpOutlineObserver = null; // same job, but synchronously (before paint)

    // Anchor row for shift+j / shift+k range selection. A DOM node, not an
    // index, because rows re-render on sort / filter / paginate. Null between
    // gestures; a plain j/k, a Space-toggle, or an Escape-deselect resets it.
    let selectAnchorRow = null;

    // Teach hook: every keyboard dispatch reports the used action so its
    // "3 in a row → taught" streak advances. Assigned by setupTeach(); stays
    // a no-op when teaching is off.
    let teachRecordUsed = () => {};

    // Stats hook: every keyboard dispatch (kind 'kb') and every trusted mouse
    // click on a target that has a shortcut (kind 'click') reports here.
    // Assigned by setupStats(); stays a no-op when statistics are off.
    let statsRecord = () => {};

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
            if (!cfg.softProhibitions) {
                console.log(TAG, 'skipping reserved binding', actionId, '=', n);
                continue;
            }
            console.warn(TAG, 'binding on a reserved combo (soft prohibitions):', actionId, '=', n, '— the browser/OS may win');
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

    // The cheatsheet's Enter-to-run: the overlay names an action id and the
    // engine executes it exactly like the keypress would (stats and teach
    // streaks included). ui.jump is a gesture, not a dispatchable action —
    // open the badges directly.
    window.addEventListener('mouseless-execute', (e) => {
        const actionId = e.detail?.actionId;
        if (!actionId) return;
        console.log(TAG, 'mouseless-execute ->', actionId);
        if (actionId === 'ui.jump') { openJump(); return; }
        dispatch(actionId);
    });

    // Shared with the Blade key-capture snippets (record modal, key-search
    // modal) so combo normalization can never drift from the engine's.
    window.mouselessEventToKey = eventToKey;

    // "alt+shift+e" → ['⌥','⇧','E'] on the Mac, ['Alt','Shift','E'] elsewhere.
    // Mirrors Keys::displayParts() so the record modal's live keycaps match
    // the table's badges.
    window.mouselessComboDisplayParts = (combo) => {
        const mods = IS_MAC_PLATFORM
            ? { ctrl: '⌃', cmd: '⌘', meta: '⌘', alt: '⌥', shift: '⇧' }
            : { ctrl: 'Ctrl', cmd: 'Cmd', meta: 'Cmd', alt: 'Alt', shift: 'Shift' };
        const keys = {
            space: 'Space', escape: 'Esc', enter: 'Enter', tab: 'Tab',
            backspace: '⌫', delete: 'Del', home: 'Home', end: 'End',
            pageup: 'PgUp', pagedown: 'PgDn',
            arrowup: '↑', arrowdown: '↓', arrowleft: '←', arrowright: '→',
        };
        return String(combo || '').split('+').filter(Boolean).map((part) => {
            const p = part.toLowerCase();
            if (mods[p]) return mods[p];
            if (keys[p]) return keys[p];
            if (/^f\d{1,2}$/.test(p)) return p.toUpperCase();
            return p.length === 1 ? p.toUpperCase() : p[0].toUpperCase() + p.slice(1);
        });
    };

    // ---- shortcut discovery: accelerator underlines + Alt-hold hint badges ----
    setupDiscovery();

    // ---- key-search trigger: move the toolbar action into the search box ----
    setupKeySearchRelocate();

    // ---- jump mode: double-tap Ctrl → letter badges on every control ----
    setupJump();

    // ---- row-cursor memory: opening a record and coming back keeps the cursor ----
    setupRowMemory();

    // ---- teach missed shortcuts: mouse click on a shortcut's target → nudge ----
    setupTeach();

    // ---- usage statistics: count keyboard uses + bypassing clicks, flush in batches ----
    setupStats();
    console.log(TAG, '=== boot complete ===');

    // Remember which row the cursor was on, per list URL (sessionStorage), and
    // put it back after returning — via Escape, breadcrumb, or browser back.
    function setupRowMemory() {
        document.addEventListener('focusin', (e) => {
            const row = e.target?.closest?.('.fi-ta-row, .fi-ta-record');
            if (row) saveRowMemory(row);
        });

        // Rows are server-rendered, but deferred tables fill in late — retry once.
        setTimeout(() => restoreRowFocus() || setTimeout(restoreRowFocus, 500), 80);
    }

    // The wire:key is "{livewireId}.table.records.{recordKey}" — the Livewire id
    // changes every load, so only the record part identifies the row across visits.
    function rowRecordKey(row) {
        const wireKey = row.getAttribute('wire:key') || '';
        const m = wireKey.match(/\.table\.records\.(.+)$/);
        return m ? m[1] : null;
    }

    function saveRowMemory(row) {
        const rows = Array.from(document.querySelectorAll('.fi-ta-row, .fi-ta-record'));
        try {
            const map = JSON.parse(sessionStorage.getItem('mouseless_row_memory') || '{}');
            map[location.pathname] = { key: rowRecordKey(row), index: rows.indexOf(row) };
            // Cap the map — a long session shouldn't grow storage unboundedly.
            const urls = Object.keys(map);
            if (urls.length > 20) delete map[urls[0]];
            sessionStorage.setItem('mouseless_row_memory', JSON.stringify(map));
        } catch {}
    }

    function restoreRowFocus() {
        let entry;
        try {
            entry = JSON.parse(sessionStorage.getItem('mouseless_row_memory') || '{}')[location.pathname];
        } catch {
            return true;
        }
        if (!entry) return true;
        // Never steal focus from a field the user is already typing in.
        if (isTextInputFocus(document.activeElement)) return true;

        const rows = Array.from(document.querySelectorAll('.fi-ta-row, .fi-ta-record')).filter(isVisible);
        if (!rows.length) return false; // table not rendered yet — caller retries

        const row = (entry.key && rows.find(r => rowRecordKey(r) === entry.key))
            || rows[Math.min(entry.index ?? 0, rows.length - 1)];
        console.log(TAG, 'row memory: restoring cursor to', entry);
        rowFocusTarget(row).focus();
        row.scrollIntoView({ block: 'nearest' });
        return true;
    }

    // Underline each action's shortcut initial in its button label (Windows
    // accelerator style) and, while the bare Alt key is held outside an input,
    // pop a key badge over every reachable target so users can discover the
    // whole ⌥ layer without opening the help overlay.
    function setupDiscovery() {
        // Accelerator underlines are opt-out (plugin ->disableUnderlines()).
        if (cfg.underlines !== false) {
            // Re-decorate after Livewire morphs; debounced — morphs come in bursts.
            let decorateTimer = null;
            const scheduleDecorate = () => {
                clearTimeout(decorateTimer);
                decorateTimer = setTimeout(decorateAccelerators, 150);
            };
            scheduleDecorate();
            new MutationObserver(scheduleDecorate).observe(document.body, { childList: true, subtree: true });
        }

        // The ⌥-hold hint badges are opt-in (plugin ->hints()).
        if (cfg.hints !== true) return;

        let hintsShown = false;
        document.addEventListener('keydown', (e) => {
            if (e.key !== 'Alt' || e.ctrlKey || e.metaKey || e.shiftKey || e.repeat) return;
            if (isTextInputFocus(document.activeElement)) return;
            const overlay = document.querySelector('[data-mouseless-overlay]');
            if (gotoOpen || jumpOpen || (overlay && isVisible(overlay))) return;
            hintsShown = true;
            showHints();
        }, true);
        const clear = (e) => {
            if (e && e.type === 'keyup' && e.key !== 'Alt') return;
            if (!hintsShown) return;
            hintsShown = false;
            hideHints();
            // Firefox (Windows/Linux) activates its menubar on the Alt keyup —
            // we consumed the press as a hint gesture, so eat the keyup.
            if (e && e.type === 'keyup') e.preventDefault();
        };
        document.addEventListener('keyup', clear, true);
        window.addEventListener('blur', () => clear());
    }

    // The ⌥-family combos we teach: alt / alt+shift plus a single letter.
    function discoverableBindings() {
        const out = [];
        for (const [combo, actionId] of Object.entries(keyToAction)) {
            const m = combo.match(/^alt\+(shift\+)?([a-z])$/);
            if (m) out.push({ actionId, letter: m[2], shifted: !!m[1] });
        }
        return out;
    }

    // Where a binding lands on the current page — the same resolution the
    // dispatcher uses, minus its side effects. Null = no on-page target.
    // scopeRow pins row-level actions to a specific row (the teach layer
    // passes the clicked row; discovery relies on focus / first row).
    function resolveActionTarget(actionId, scopeRow = null) {
        if (actionId === 'list.search') return findTableSearchInput(document);
        if (actionId === 'list.group') return document.querySelector('.fi-ta-grouping-settings-fields select');
        if (actionId === 'list.filter') {
            return document.querySelector('.fi-ta-filters-trigger-action-ctn button')
                ?? document.querySelector('.fi-ta-filters-dropdown .fi-dropdown-trigger button');
        }
        if (actionId === 'list.columns') return document.querySelector('.fi-ta-col-manager-dropdown .fi-dropdown-trigger button');
        if (actionId === 'list.bulk-action') {
            return Array.from(document.querySelectorAll(
                '[data-mouseless="list.bulk-action"], .fi-ta-header-toolbar .fi-dropdown-trigger .fi-ac-btn-group',
            )).find(isVisible) ?? null;
        }
        if (actionId === 'list.sort') return Array.from(document.querySelectorAll('.fi-ta-header-cell-sort-btn')).find(isVisible) ?? null;
        if (actionId === 'list.next-page' || actionId === 'list.prev-page') {
            const rel = actionId === 'list.next-page' ? 'next' : 'prev';
            const sel = `.fi-pagination [rel="${rel}"], .fi-pagination-${rel === 'next' ? 'next' : 'previous'}-btn`;
            return Array.from(document.querySelectorAll(sel)).find(isVisible) ?? null;
        }
        if (actionId === 'nav.command-palette') return document.querySelector('.fi-global-search-field');
        if (actionId === 'nav.logout') {
            return document.querySelector('[data-mouseless="nav.logout"]')
                ?? document.querySelector('form[action$="/logout"] button');
        }
        if (actionId === 'nav.notifications') {
            return document.querySelector('[data-mouseless="nav.notifications"]')
                ?? document.querySelector('.fi-topbar-database-notifications-btn, .fi-sidebar-database-notifications-btn');
        }
        if (actionId === 'crud.create') return document.querySelector('a[href$="/create"]');
        if (actionId.startsWith('custom.')) return document.querySelector(`[data-mouseless="${actionId}"]`);
        if (actionId.startsWith('nav.') || actionId.startsWith('ui.')) {
            return document.querySelector(`[data-mouseless="${actionId}"]`);
        }

        const rowScoped = ROW_LEVEL_ACTIONS.has(actionId);
        const focusedRow = document.activeElement?.closest?.('.fi-ta-row, .fi-ta-record');
        const scope = rowScoped
            ? (scopeRow ?? focusedRow ?? document.querySelector('.fi-ta-row, .fi-ta-record') ?? document)
            : document;
        for (const name of filamentActionNames(actionId)) {
            const btn = findActionButton(name, scope, true);
            if (btn) return btn;
        }
        return document.querySelector(`[data-mouseless="${actionId}"]`);
    }

    function decorateAccelerators() {
        for (const { actionId, letter } of discoverableBindings()) {
            // Sort's target is a column header — its text is a column NAME
            // ("Aktion"), not the action's label, so an underline there would
            // mark a meaningless letter. The ⌥-hold badge still teaches it.
            if (actionId === 'list.sort') continue;
            const el = resolveActionTarget(actionId);
            if (!el) continue;
            // A Livewire morph can keep the marker attribute while replacing the
            // children — re-check that the underline actually survived.
            if (el.dataset.mouselessAccel === letter && el.querySelector('.fi-mouseless-accel')) continue;
            if (underlineLetter(el, letter)) el.dataset.mouselessAccel = letter;
        }
    }

    // Wrap the first occurrence of the letter (word-start preferred) in the
    // element's text with an underline span. Skips svg/underlined content.
    function underlineLetter(el, letter) {
        const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, {
            acceptNode: (n) => {
                if (n.parentElement?.closest('svg, .fi-mouseless-accel')) return NodeFilter.FILTER_REJECT;
                return n.textContent.trim() ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_SKIP;
            },
        });
        const nodes = [];
        while (walker.nextNode()) nodes.push(walker.currentNode);

        // Word-start matches only — the scheme is about INITIALS, and a
        // mid-word underline ("Akti̲on") reads as noise, not as a shortcut.
        for (const node of nodes) {
            const text = node.textContent;
            const m = new RegExp(`(?:^|\\s)(${letter})`, 'i').exec(text);
            if (!m) continue;
            const at = m.index + m[0].length - 1;

            // Replace the whole text node with ONE wrapper span. Filament
            // buttons are flex containers with a gap — splitting the label
            // into several nodes would space them apart ("E rstellen").
            const wrapper = document.createElement('span');
            const accel = document.createElement('span');
            accel.className = 'fi-mouseless-accel';
            accel.textContent = text.slice(at, at + 1);
            wrapper.append(text.slice(0, at), accel, text.slice(at + 1));
            node.replaceWith(wrapper);
            return true;
        }
        return false;
    }

    function showHints() {
        hideHints();
        const container = document.createElement('div');
        container.id = 'fi-mouseless-hints';
        let count = 0;
        const addBadge = (el, text) => {
            if (!el || !isVisible(el)) return;
            const rect = el.getBoundingClientRect();
            if (!rect.width && !rect.height) return;
            const badge = document.createElement('div');
            badge.className = 'fi-mouseless-hint';
            badge.textContent = text;
            badge.style.left = `${Math.max(4, rect.left - 6)}px`;
            badge.style.top = `${Math.max(4, rect.top - 10)}px`;
            container.appendChild(badge);
            count++;
        };
        for (const { actionId, letter, shifted } of discoverableBindings()) {
            addBadge(resolveActionTarget(actionId), (shifted ? '⇧' : '') + letter.toUpperCase());
        }
        // Tabs answer to ⌥1–⌥9 — number them.
        tabItems().slice(0, 9).forEach((tab, i) => addBadge(tab, String(i + 1)));
        if (count) document.body.appendChild(container);
        console.log(TAG, 'hint mode:', count, 'badge(s)');
    }

    function hideHints() {
        document.getElementById('fi-mouseless-hints')?.remove();
    }

    // ---------- jump mode (double-tap modifier → stable-hash label badges) ----------
    // Vimperator-style: double-tap the chord modifier (default Ctrl) and every
    // clickable control in the page content gets a letter badge; typing the
    // label focuses (fields) or clicks (everything else) it. Labels are hashed
    // from each control's stable identity (wire:model statePath, record key +
    // column name, href, …), NOT from screen position — so a control keeps its
    // letter across reloads, relabelings and locale switches.

    const JUMP_ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    // Chrome the engine itself owns, plus sidebar/topbar: navigation has its
    // own affordances (the g palette, nav.* combos) — badges would be noise.
    // Apps can opt dense regions out (marker maps, canvases, custom widgets
    // The key-search trigger is declared as a table toolbar action (that's
    // what wires up its modal), but it belongs INSIDE the search box, in the
    // slot of the magnifying-glass prefix. CSS can't re-parent across the
    // toolbar's flex containers, so the node is physically moved into the
    // search field's input wrapper. Livewire morphs put a fresh button back
    // into the toolbar and drop the moved one; the MutationObserver runs
    // before paint, so the correction never flashes.
    function setupKeySearchRelocate() {
        const relocate = () => {
            const btn = document.querySelector('.fi-ta-header-toolbar .fi-mouseless-key-search-btn');
            if (!btn) return;
            const wrp = btn.closest('.fi-ta-header-toolbar')?.querySelector('.fi-ta-search-field .fi-input-wrp');
            if (wrp && btn.parentElement !== wrp) wrp.insertBefore(btn, wrp.firstChild);
        };
        relocate();
        new MutationObserver(relocate).observe(document.body, { childList: true, subtree: true });
    }

    // with their own keyboard nav) via data-mouseless-jump="ignore" on any
    // ancestor. Editor toolbar buttons ARE targets — their x-on:click
    // handlers (toggleBold() …) give them stable identities. The topbar
    // (user menu, bell, language switcher) is included; the sidebar stays
    // out — the g palette owns navigation.
    const JUMP_EXCLUDE = '.fi-sidebar, .fi-breadcrumbs, #fi-mouseless-hints, #fi-mouseless-jump, [data-mouseless-overlay], [data-mouseless-goto], .phpdebugbar, [data-mouseless-jump="ignore"]';

    const JUMP_SELECTOR = [
        'input:not([type="hidden"]):not([disabled])',
        'select:not([disabled])',
        'textarea:not([disabled])',
        '[contenteditable="true"]',
        'button:not([disabled])',
        'a[href]',
        '[role="button"]', '[role="tab"]', '[role="checkbox"]', '[role="menuitem"]', '[role="slider"]',
        '.fi-dropdown-trigger',
        '.fi-tabs-item',
        // Copyable values outside tables (infolist entries on view pages).
        '.fi-copyable',
        // FilePond's visible "browse" trigger — its real file input is
        // visually hidden and correctly dropped by the visibility checks.
        '.filepond--label-action',
    ].join(',');

    function setupJump() {
        if (!cfg.jump) return;

        const parts = String(cfg.jump.chord || 'ctrl,ctrl').toLowerCase().split(',').map(s => s.trim());
        const NAMES = { ctrl: 'Control', alt: 'Alt', shift: 'Shift', meta: 'Meta' };
        let mod = NAMES[parts[0]];
        if (parts.length !== 2 || parts[0] !== parts[1] || !mod) {
            console.warn(TAG, 'jump: invalid chord', cfg.jump.chord, '— falling back to ctrl,ctrl');
            mod = 'Control';
        }
        const timeoutMs = Number(cfg.jump.timeoutMs) || 350;
        console.log(TAG, 'jump mode armed: double-tap', mod, 'within', timeoutMs, 'ms');

        // "Another modifier is also down" for THIS chord key — a tap must be bare.
        const otherMods = (e) => (mod !== 'Control' && e.ctrlKey) || (mod !== 'Alt' && e.altKey)
            || (mod !== 'Shift' && e.shiftKey) || (mod !== 'Meta' && e.metaKey);

        let lastTapUp = 0;   // timestamp of the last CLEAN tap's keyup
        let tapDirty = true; // a non-chord key went down since the chord key's keydown

        document.addEventListener('keydown', (e) => {
            if (e.key === mod && !e.repeat && !otherMods(e)) { tapDirty = false; return; }
            // Any other key — or a held-repeat of the chord key — means the
            // modifier is being USED (ctrl+k, ctrl+click prep), not tapped.
            tapDirty = true;
            lastTapUp = 0;
        }, true);

        document.addEventListener('keyup', (e) => {
            if (e.key !== mod) return;
            if (tapDirty) { lastTapUp = 0; return; }
            if (lastTapUp && e.timeStamp - lastTapUp <= timeoutMs) {
                lastTapUp = 0;
                // Firefox activates its menubar on a bare Alt keyup — we consumed it.
                if (mod === 'Alt') e.preventDefault();
                if (jumpOpen) { closeJump(); return; }
                // Deliberately NOT guarded on text-input focus: jumping OUT of a
                // field is a core use case, and a bare modifier tap never types.
                if (document.querySelector('[data-mouseless-recording]')) return;
                if (gotoOpen) return;
                const overlay = document.querySelector('[data-mouseless-overlay]');
                if (overlay && isVisible(overlay)) return;
                openJump();
            } else {
                lastTapUp = e.timeStamp;
            }
        }, true);

        // Mouse activity voids pending taps (ctrl+click) and closes the mode.
        // Scroll/resize REPOSITION the badges (they follow their targets);
        // a grabbed sortable row survives scrolling too but drops on
        // click/blur/navigation.
        document.addEventListener('mousedown', () => { tapDirty = true; lastTapUp = 0; if (jumpOpen) closeJump(); endJumpMove(); clearJumpPlaced(); }, true);
        window.addEventListener('blur', () => { tapDirty = true; lastTapUp = 0; closeJump(); endJumpMove(); });
        window.addEventListener('scroll', () => { if (jumpOpen) scheduleJumpReposition(); }, { capture: true, passive: true });
        window.addEventListener('resize', () => { if (jumpOpen) scheduleJumpReposition(); });
        document.addEventListener('livewire:navigated', () => { closeJump(); endJumpMove(); clearJumpPlaced(); });

        // Entering table reorder mode is THE moment the badges are wanted —
        // pop them without demanding a double-tap first. Poll for the drag
        // handles (cheap: one selector every 300ms); rising edge opens,
        // falling edge retracts everything the mode left behind. Initial
        // state is read at boot so a page that somehow loads mid-reorder
        // doesn't surprise with badges.
        let hadReorderHandles = !!document.querySelector('.fi-ta-reorder-handle');
        setInterval(() => {
            const has = !!document.querySelector('.fi-ta-reorder-handle');
            if (has === hadReorderHandles) return;
            hadReorderHandles = has;
            if (!has) {
                endJumpMove();
                clearJumpPlaced();
                if (jumpOpen) closeJump();
                return;
            }
            if (jumpOpen || gotoOpen || jumpMoveContainer) return;
            if (document.querySelector('[data-mouseless-recording]')) return;
            const overlay = document.querySelector('[data-mouseless-overlay]');
            if (overlay && isVisible(overlay)) return;
            console.log(TAG, 'jump: reorder mode started — opening badges');
            openJump();
        }, 300);
    }

    // FNV-1a 32-bit — tiny, deterministic, good spread for short DOM keys.
    function jumpHash(str) {
        let h = 0x811c9dc5;
        for (let i = 0; i < str.length; i++) {
            h ^= str.charCodeAt(i);
            h = Math.imul(h, 0x01000193);
        }
        return h >>> 0;
    }

    // A control's language-independent identity. Order matters: Livewire state
    // paths and wire:click expressions are the most stable names Filament gives
    // us; id/name/href degrade gracefully; the last resort is positional.
    function jumpStableKey(el, index) {
        for (const attr of el.attributes) {
            if (attr.name.startsWith('wire:model')) {
                // Radio groups / checkbox lists share one statePath — the
                // option value is what tells their members apart.
                const t = (el.type || '').toLowerCase();
                const opt = (t === 'radio' || t === 'checkbox') && el.value ? ':' + el.value : '';
                return 'wm:' + attr.value + opt;
            }
            if (attr.name.startsWith('wire:click')) return 'wc:' + attr.value;
            // Alpine click handlers: a copyable's handler embeds the copied
            // value itself — as stable as identity gets for a plain span.
            if (attr.name.startsWith('x-on:click') || attr.name.startsWith('@click')) return 'xc:' + attr.value;
        }
        if (el.id && jumpIdLooksStable(el.id)) return 'id:' + el.id;
        if (el.getAttribute('name')) return 'nm:' + el.getAttribute('name');
        const href = el.getAttribute('href');
        if (href) return 'href:' + href.split('?')[0];
        // Nearest ancestor with an id — Filament schema components carry
        // their statePath as id ("form.radioactivity_level"), which names
        // widgets whose focusable part is an anonymous div (sliders). The
        // twin index keeps e.g. a range slider's two handles apart.
        let anc = el.parentElement;
        for (let i = 0; anc && anc !== document.body && i < 8; i++, anc = anc.parentElement) {
            if (anc.id && jumpIdLooksStable(anc.id)) {
                const role = el.getAttribute('role');
                const twins = Array.from(anc.querySelectorAll(el.tagName)).filter(t => t.getAttribute('role') === role);
                return 'anc:' + anc.id + ':' + twins.indexOf(el);
            }
        }
        return 'pos:' + el.tagName + ':' + index;
    }

    // Generated ids change every page load (filepond--browser-tmfxy9pku,
    // Livewire component hashes) — an id only counts as identity when its
    // last segment isn't a digit-containing random hash. A single unstable
    // key would shuffle OTHER controls' letters too, via collision groups.
    function jumpIdLooksStable(id) {
        return !/(^|[-_:.])(?=[a-z0-9]*\d)[a-z0-9]{7,}$/i.test(id);
    }

    // The td's own fi-ta-cell-<name> class — Filament emits the column name
    // (kebab-cased) on every data cell, so cell labels survive column reorder.
    function jumpCellColumn(cell) {
        for (const c of cell.classList) {
            const m = c.match(/^fi-ta-cell-(.+)$/);
            if (m) return m[1];
        }
        return null;
    }

    function jumpCandidates() {
        // An open modal makes the rest of the page inert — scope to it.
        const modal = Array.from(document.querySelectorAll('.fi-modal-window')).find(isVisible);
        const root = modal || document.body;
        const out = [];

        // Stronger than isVisible(): inactive tab/wizard panels keep their
        // layout size but hide via CSS visibility — their fields must never
        // be badged. Deliberately NOT opacity-aware: Filament's page-fade
        // leaves ancestors at computed opacity 0 while fully visible, and
        // truly invisible leftovers fail the hit-probe below anyway.
        const visuallyShown = (el) => (el.checkVisibility
            ? el.checkVisibility({ checkVisibilityCSS: true, visibilityProperty: true })
            : isVisible(el));

        // The element must actually be hittable near where its badge points —
        // kills leftovers that pass the CSS checks but render nowhere, and
        // things buried under other layers.
        const hittable = (el, rect) => {
            const pts = [
                [rect.left + rect.width / 2, rect.top + rect.height / 2],
                [rect.left + Math.min(rect.width, 24) / 2, rect.top + Math.min(rect.height, 24) / 2],
            ];
            for (let [x, y] of pts) {
                x = Math.min(Math.max(x, 1), innerWidth - 2);
                y = Math.min(Math.max(y, 1), innerHeight - 2);
                const hit = document.elementFromPoint(x, y);
                if (hit && (hit === el || el.contains(hit) || hit.contains(el))) return true;
            }
            return false;
        };

        const push = (el, key, commit) => {
            if (!el) return;
            // A hidden or covered input rendered as a styled label (toggle
            // buttons, custom radios — the input hides via opacity/clip and
            // the label paints over it): badge and click the LABEL instead.
            // The key stays the input's, so the label inherits stability.
            const labelSwap = () => {
                if (!el.labels || !el.labels.length) return false;
                const lab = Array.from(el.labels).find(l => visuallyShown(l) && hittable(l, l.getBoundingClientRect()));
                if (!lab) return false;
                el = lab;
                commit = 'click';
                return true;
            };
            if (!visuallyShown(el) && !labelSwap()) return;
            let rect = el.getBoundingClientRect();
            if (!rect.width && !rect.height) return;
            if (rect.bottom <= 0 || rect.top >= innerHeight || rect.right <= 0 || rect.left >= innerWidth) return;
            if (el.closest(JUMP_EXCLUDE)) return;
            if (!hittable(el, rect)) {
                if (!labelSwap()) return;
                rect = el.getBoundingClientRect();
                if (rect.bottom <= 0 || rect.top >= innerHeight || rect.right <= 0 || rect.left >= innerWidth) return;
                if (el.closest(JUMP_EXCLUDE)) return;
            }
            for (const seen of out) {
                // Containment dedupe: parents come out of querySelectorAll
                // first, so a dropdown trigger wrapper wins over its button.
                if (seen.el === el || seen.el.contains(el) || el.contains(seen.el)) return;
                // Distinct controls at the same spot would stack unreadable
                // badges — first one wins.
                if (Math.abs(seen.rect.left - rect.left) < 14 && Math.abs(seen.rect.top - rect.top) < 12) return;
            }
            out.push({ el, key, commit, rect });
        };

        // Reorder mode: rows are otherwise skipped below, but each row's drag
        // handle is a target — committing one grabs the row for ↑/↓ moves
        // (commitJump routes anything inside [x-sortable-handle] to move mode).
        // The x-sortable-item value is the record key, so labels stay stable
        // across reorders and reloads.
        for (const handle of root.querySelectorAll('.fi-ta-reorder-handle')) {
            const item = handle.closest('[x-sortable-item]');
            if (!item) continue;
            push(handle, 'reorder:' + item.getAttribute('x-sortable-item'), 'click');
        }

        // Selected rows — and the row the j/k cursor is on (highlight = focus
        // inside the row) — contribute per-cell targets: the selection checkbox,
        // copyable values (span.fi-copyable — clicking it copies) and clickable
        // cells (a/button.fi-ta-col). All other rows get nothing: j/k already
        // walks rows, and badging every cell of every row is pure noise.
        const cellRows = new Set(root.querySelectorAll('.fi-ta-row.fi-selected, .fi-ta-record.fi-selected'));
        const cursorRow = document.activeElement?.closest?.('.fi-ta-row, .fi-ta-record');
        if (cursorRow && root.contains(cursorRow)) cellRows.add(cursorRow);
        for (const row of cellRows) {
            if (!isVisible(row)) continue;
            const rec = rowRecordKey(row) || 'row';
            const checkbox = row.querySelector('input.fi-ta-record-checkbox');
            if (checkbox) push(checkbox, 'chk:' + rec, 'click');
            let cellIdx = 0;
            for (const cell of row.querySelectorAll('.fi-ta-cell')) {
                cellIdx++;
                // The actions cell gets one badge per action — the "⋮" group
                // trigger and/or each inline action button.
                if (cell.matches('.fi-ta-actions-cell') || cell.querySelector('.fi-ta-actions')) {
                    let actIdx = 0;
                    for (const act of cell.querySelectorAll('.fi-dropdown-trigger, button:not([disabled]), a[href]')) {
                        actIdx++;
                        push(act, 'rowact:' + rec + ':' + actIdx, act.closest('.fi-dropdown-trigger') ? 'dropdown' : 'click');
                    }
                    continue;
                }
                // Copy trigger, else an editable column widget (SelectColumn,
                // TextInputColumn, ToggleColumn, …), else the clickable wrapper.
                const target = cell.querySelector('.fi-copyable')
                    || cell.querySelector('select:not([disabled]), input:not([type="hidden"]):not([disabled]), textarea:not([disabled]), [role="switch"]:not([disabled]), [role="checkbox"]:not([disabled])')
                    || cell.querySelector('a.fi-ta-col, button.fi-ta-col');
                if (!target) continue;
                const commit = (isTextInputFocus(target) || target.tagName === 'SELECT') ? 'focus' : 'click';
                push(target, 'cell:' + rec + ':' + (jumpCellColumn(cell) ?? cellIdx), commit);
            }
        }

        for (const el of root.querySelectorAll(JUMP_SELECTOR)) {
            if (el.closest('.fi-ta-row, .fi-ta-record')) continue; // rows: selected-only, handled above
            const commit = el.closest('.fi-dropdown-trigger') ? 'dropdown'
                : (isTextInputFocus(el) || el.tagName === 'SELECT' || el.matches('[role="slider"]')) ? 'focus'
                    : 'click';
            push(el, jumpStableKey(el, out.length), commit);
        }

        // Chart.js widgets: segments/points live inside a canvas, not the DOM
        // — ask the chart instance for their positions. Committing one shows
        // its tooltip (the hover equivalent).
        let canvasIdx = 0;
        for (const canvas of root.querySelectorAll('canvas')) {
            canvasIdx++;
            if (!isVisible(canvas) || canvas.closest(JUMP_EXCLUDE)) continue;
            let chart = null;
            try {
                const alp = window.Alpine && window.Alpine.$data ? window.Alpine.$data(canvas) : null;
                chart = (window.Chart && window.Chart.getChart ? window.Chart.getChart(canvas) : null)
                    ?? (alp ? (typeof alp.getChart === 'function' ? alp.getChart() : alp.chart) : null);
            } catch { /* not a chart canvas */ }
            if (!chart || !chart.getDatasetMeta || !chart.data) continue;
            const cRect = canvas.getBoundingClientRect();
            let added = 0;
            for (let d = 0; d < (chart.data.datasets || []).length && added < 20; d++) {
                const meta = chart.getDatasetMeta(d);
                if (!meta || meta.hidden) continue;
                for (let i = 0; i < meta.data.length && added < 20; i++) {
                    const pt = meta.data[i];
                    const pos = pt && pt.tooltipPosition ? pt.tooltipPosition() : pt;
                    if (!pos || typeof pos.x !== 'number') continue;
                    const x = cRect.left + pos.x;
                    const y = cRect.top + pos.y;
                    if (x <= 0 || x >= innerWidth || y <= 0 || y >= innerHeight) continue;
                    const rect = { left: x, top: y, right: x, bottom: y, width: 0, height: 0 };
                    if (out.some(seen => Math.abs(seen.rect.left - rect.left) < 14 && Math.abs(seen.rect.top - rect.top) < 12)) continue;
                    out.push({
                        el: canvas,
                        // Content-derived key: dataset + point label, so a
                        // segment keeps its letter as long as its data exists.
                        key: 'chart:' + canvasIdx + ':' + (chart.data.datasets[d].label ?? d) + ':' + ((chart.data.labels || [])[i] ?? i),
                        commit: 'chart',
                        rect,
                        chart,
                        chartDataset: d,
                        chartIndex: i,
                    });
                    added++;
                }
            }
            if (added >= 20) console.log(TAG, 'jump: chart badge cap reached on canvas', canvasIdx);

            // Legend entries are canvas-drawn too — badge their hit boxes;
            // committing toggles the dataset/segment like a legend click.
            const hitBoxes = chart.legend && chart.legend.legendHitBoxes;
            if (hitBoxes && chart.legend.legendItems) {
                for (let i = 0; i < hitBoxes.length; i++) {
                    const hb = hitBoxes[i];
                    const item = chart.legend.legendItems[i];
                    if (!hb || !item) continue;
                    const x = cRect.left + hb.left;
                    const y = cRect.top + hb.top + hb.height / 2;
                    if (x <= 0 || x >= innerWidth || y <= 0 || y >= innerHeight) continue;
                    const rect = { left: x, top: y, right: x, bottom: y, width: 0, height: 0 };
                    if (out.some(seen => Math.abs(seen.rect.left - rect.left) < 14 && Math.abs(seen.rect.top - rect.top) < 12)) continue;
                    out.push({
                        el: canvas,
                        key: 'chartlegend:' + canvasIdx + ':' + (item.text ?? i),
                        commit: 'chartlegend',
                        rect,
                        chart,
                        chartLegendIndex: i,
                    });
                }
            }
        }

        // Label capacity is 36 singles + collision pairs; 200 is far beyond any
        // usable screen — cap hard so a pathological page can't wedge the mode.
        return out.slice(0, 200);
    }

    function jumpAssignLabels(cands) {
        const N = JUMP_ALPHABET.length;
        const groups = new Map();
        for (const c of cands) {
            c.hash = jumpHash(c.key);
            const letter = JUMP_ALPHABET[c.hash % N];
            if (!groups.has(letter)) groups.set(letter, []);
            groups.get(letter).push(c);
        }
        const out = [];
        for (const [letter, members] of groups) {
            if (members.length === 1) {
                members[0].label = letter;
                out.push(members[0]);
                continue;
            }
            // Collision group: the letter becomes a prefix and EVERY member gets
            // letter+second — prefix-free because a letter is a single OR a
            // prefix, never both. The second char is hashed too; ties probe
            // deterministically over key-sorted members, so the outcome doesn't
            // depend on enumeration order.
            members.sort((a, b) => (a.key < b.key ? -1 : 1));
            const used = new Set();
            for (const c of members.slice(0, N)) {
                let s = Math.floor(c.hash / N) % N;
                while (used.has(s)) s = (s + 1) % N;
                used.add(s);
                c.label = letter + JUMP_ALPHABET[s];
                out.push(c);
            }
            if (members.length > N) console.warn(TAG, 'jump: dropped', members.length - N, 'target(s) in group', letter);
        }
        return out;
    }

    function openJump() {
        const cands = jumpAssignLabels(jumpCandidates());
        if (!cands.length) {
            console.log(TAG, 'jump: no targets on screen');
            toast(cfg.jump?.strings?.no_targets);
            return;
        }
        const container = document.createElement('div');
        container.id = 'fi-mouseless-jump';
        jumpTargets = cands.map((c) => {
            const badge = document.createElement('div');
            badge.className = 'fi-mouseless-jump-badge';
            const typed = document.createElement('span');
            typed.className = 'fi-mouseless-jump-typed';
            const rest = document.createElement('span');
            rest.className = 'fi-mouseless-jump-rest';
            rest.textContent = c.label;
            badge.append(typed, rest);
            badge.style.left = `${Math.max(4, c.rect.left - 6)}px`;
            badge.style.top = `${Math.max(4, c.rect.top - 10)}px`;
            container.appendChild(badge);
            return {
                el: c.el, label: c.label, commit: c.commit, badge, typed, rest,
                chart: c.chart, chartDataset: c.chartDataset, chartIndex: c.chartIndex,
                chartLegendIndex: c.chartLegendIndex,
            };
        });
        document.body.appendChild(container);
        jumpOpen = true;
        jumpQuery = '';
        // Debug aid: lets a console (or a bug report) show WHY a control got
        // its letter — labels are pure functions of these keys.
        window.__mouselessJumpTargets = cands.map(c => ({ key: c.key, label: c.label }));
        console.log(TAG, 'jump: open,', jumpTargets.length, 'target(s)');
    }

    function closeJump() {
        jumpOpen = false;
        jumpQuery = '';
        jumpTargets = [];
        document.getElementById('fi-mouseless-jump')?.remove();
    }

    // Badges follow their targets while the user scrolls or resizes. Targets
    // scrolled out of view hide their badge; targets that only BECOME visible
    // after scrolling weren't enumerated (viewport-clipped at open time) — a
    // second double-tap re-enumerates.
    let jumpRepositionRaf = 0;

    function scheduleJumpReposition() {
        if (jumpRepositionRaf) return;
        jumpRepositionRaf = requestAnimationFrame(() => {
            jumpRepositionRaf = 0;
            repositionJump();
        });
    }

    function repositionJump() {
        console.log(TAG, 'jump: repositioning', jumpTargets.length, 'badge(s)');
        for (const t of jumpTargets) {
            let pos = null;
            if (t.commit === 'chart') {
                try {
                    const p = t.chart.getDatasetMeta(t.chartDataset).data[t.chartIndex].tooltipPosition();
                    const cr = t.el.getBoundingClientRect();
                    pos = { left: cr.left + p.x, top: cr.top + p.y };
                } catch { pos = null; }
            } else if (t.commit === 'chartlegend') {
                try {
                    const hb = t.chart.legend.legendHitBoxes[t.chartLegendIndex];
                    const cr = t.el.getBoundingClientRect();
                    pos = { left: cr.left + hb.left, top: cr.top + hb.top + hb.height / 2 };
                } catch { pos = null; }
            } else if (t.el.isConnected) {
                const r = t.el.getBoundingClientRect();
                pos = { left: r.left, top: r.top };
            }
            const off = !pos || pos.top < -24 || pos.top > innerHeight || pos.left < -24 || pos.left > innerWidth;
            t.badge.style.display = off ? 'none' : '';
            if (pos) {
                t.badge.style.left = `${Math.max(4, pos.left - 6)}px`;
                t.badge.style.top = `${Math.max(4, pos.top - 10)}px`;
            }
        }
    }

    function filterJump() {
        for (const t of jumpTargets) {
            if (t.label.startsWith(jumpQuery)) {
                t.badge.classList.remove('fi-mouseless-jump-dim');
                t.typed.textContent = jumpQuery;
                t.rest.textContent = t.label.slice(jumpQuery.length);
            } else {
                t.badge.classList.add('fi-mouseless-jump-dim');
            }
        }
    }

    function handleJumpKey(e) {
        if (e.isComposing) return;
        // While the badges are up NOTHING may leak — not to the focused field,
        // not to the browser, not to the combo engine.
        e.preventDefault();
        e.stopPropagation();

        if (e.key === 'Escape') { closeJump(); return; }
        if (e.key === 'Backspace') {
            if (!jumpQuery) { closeJump(); return; }
            jumpQuery = jumpQuery.slice(0, -1);
            filterJump();
            return;
        }
        if (e.ctrlKey || e.metaKey || e.altKey) return;
        const ch = e.key.length === 1 ? e.key.toLowerCase() : '';
        if (!/^[a-z0-9]$/.test(ch)) return;

        const candidate = jumpQuery + ch;
        const exact = jumpTargets.find(t => t.label === candidate);
        if (exact) { commitJump(exact); return; }
        if (jumpTargets.some(t => t.label.startsWith(candidate))) {
            jumpQuery = candidate;
            filterJump();
            return;
        }
        // Dead key: swallow it, keep the query, shake the badges.
        shakeJump();
    }

    // ---- move mode: a grabbed x-sortable row follows ↑/↓ until Enter/Esc ----

    function startJumpMove(handle) {
        const item = handle.closest('[x-sortable-item]');
        const container = handle.closest('[x-sortable]');
        if (!item || !container) { handle.click(); return; }
        clearJumpPlaced();
        jumpMoveItem = item.getAttribute('x-sortable-item');
        jumpMoveContainer = container;
        jumpMoveFromIndex = jumpMoveItems().indexOf(item);
        styleJumpMove(true);
        ensureJumpOutlineWatch();
        toast(cfg.jump?.strings?.move_hint);
        console.log(TAG, 'jump: move mode on', jumpMoveItem);
    }

    function jumpMoveItems() {
        return Array.from(jumpMoveContainer.children).filter(c => c.matches && c.matches('[x-sortable-item]'));
    }

    // Livewire re-renders after every reorder, so the grabbed row is tracked
    // by its x-sortable-item key, never by element reference.
    function jumpSortableEl(container, item) {
        if (!container || !container.isConnected) return null;
        return container.querySelector(`[x-sortable-item="${CSS.escape(item)}"]`);
    }

    function jumpMoveEl() {
        return jumpSortableEl(jumpMoveContainer, jumpMoveItem);
    }

    function setRowOutline(el, on) {
        el.style.outline = on ? '2px solid #f59e0b' : '';
        el.style.outlineOffset = on ? '1px' : '';
    }

    function styleJumpMove(on) {
        const el = jumpMoveEl();
        if (el) setRowOutline(el, on);
    }

    // The morph after a reorder round-trip strips client-set inline styles.
    // A MutationObserver re-asserts the outline the moment the style attr
    // is wiped — mutation callbacks run before the next paint, so the bare
    // state never becomes visible. The 200ms timer is the belt-and-braces
    // fallback; both retire when neither marker is active. The observer
    // only writes when the outline is actually missing, or its own write
    // would re-trigger it forever.
    function ensureJumpOutlineWatch() {
        if (!jumpOutlineTimer) jumpOutlineTimer = setInterval(jumpOutlineTick, 200);
        if (!jumpOutlineObserver) jumpOutlineObserver = new MutationObserver(jumpOutlineEnforce);
        jumpOutlineObserver.observe(jumpMoveContainer, {
            attributes: true, attributeFilter: ['style'], subtree: true, childList: true,
        });
    }

    function jumpOutlineEnforce() {
        if (jumpMoveContainer) {
            const el = jumpMoveEl();
            if (el && !el.style.outline) setRowOutline(el, true);
        }
        if (jumpPlacedContainer) {
            const el = jumpSortableEl(jumpPlacedContainer, jumpPlacedItem);
            if (el) { if (!el.style.outline) setRowOutline(el, true); }
            else if (!jumpPlacedContainer.isConnected) { jumpPlacedItem = null; jumpPlacedContainer = null; }
        }
    }

    function jumpOutlineTick() {
        jumpOutlineEnforce();
        if (!jumpMoveContainer && !jumpPlacedContainer) {
            clearInterval(jumpOutlineTimer);
            jumpOutlineTimer = 0;
            if (jumpOutlineObserver) { jumpOutlineObserver.disconnect(); jumpOutlineObserver = null; }
        }
    }

    function clearJumpPlaced() {
        if (!jumpPlacedContainer) return;
        const el = jumpSortableEl(jumpPlacedContainer, jumpPlacedItem);
        if (el) setRowOutline(el, false);
        jumpPlacedItem = null;
        jumpPlacedContainer = null;
    }

    // One synthetic SortableJS 'end' per GESTURE, not per arrow step —
    // per-step dispatch fired a Livewire round-trip and full table morph on
    // every press, which flickered the whole table. Like a real drag, the
    // handlers see grab-index → drop-index plus the final DOM order.
    function commitJumpMoveOrder() {
        const item = jumpMoveEl();
        if (!item) return;
        const j = jumpMoveItems().indexOf(item);
        if (j < 0 || j === jumpMoveFromIndex) return;
        const ev = new CustomEvent('end', { bubbles: false });
        ev.item = item;
        ev.from = jumpMoveContainer;
        ev.to = jumpMoveContainer;
        ev.oldIndex = jumpMoveFromIndex;
        ev.newIndex = j;
        ev.oldDraggableIndex = jumpMoveFromIndex;
        ev.newDraggableIndex = j;
        jumpMoveContainer.dispatchEvent(ev);
    }

    // Escape: nothing was dispatched yet, so the grabbed row just slides
    // back to where it was picked up — a true cancel.
    function cancelJumpMove() {
        const item = jumpMoveEl();
        if (item) {
            const siblings = jumpMoveItems().filter(el => el !== item);
            if (jumpMoveFromIndex >= siblings.length) {
                if (siblings.length) siblings[siblings.length - 1].after(item);
            } else {
                siblings[jumpMoveFromIndex].before(item);
            }
            setRowOutline(item, false);
        }
        jumpMoveItem = null;
        jumpMoveContainer = null;
        console.log(TAG, 'jump: move mode cancelled');
    }

    // keepOutline: an Enter-drop hands the outline over to the "placed"
    // marker instead of clearing it — the user keeps sight of the row they
    // just positioned while the badges are back up for the next grab.
    function endJumpMove(keepOutline = false) {
        if (!jumpMoveContainer) return;
        commitJumpMoveOrder();
        if (keepOutline) {
            jumpPlacedItem = jumpMoveItem;
            jumpPlacedContainer = jumpMoveContainer;
        } else {
            styleJumpMove(false);
        }
        jumpMoveItem = null;
        jumpMoveContainer = null;
        console.log(TAG, 'jump: move mode off');
    }

    function handleJumpMoveKey(e) {
        const dir = (e.key === 'ArrowUp' || e.key === 'k') ? -1
            : (e.key === 'ArrowDown' || e.key === 'j') ? 1 : 0;
        if (dir) {
            e.preventDefault();
            e.stopPropagation();
            const item = jumpMoveEl();
            if (!item) { endJumpMove(); return; }
            const items = jumpMoveItems();
            const i = items.indexOf(item);
            const j = i + dir;
            if (i < 0 || j < 0 || j >= items.length) return;
            // Pure DOM move — the synthetic 'end' event waits until the
            // gesture completes (commitJumpMoveOrder), so arrow steps cost
            // no round-trips and nothing re-renders under the user.
            if (dir < 0) items[j].before(item); else items[j].after(item);
            styleJumpMove(true);
            return;
        }
        if (['Enter', 'Escape', ' ', 'Tab'].includes(e.key)) {
            e.preventDefault();
            e.stopPropagation();
            // Enter means "row placed" — the accumulated move is dispatched,
            // the row keeps its outline, and the badges reopen once the
            // reorder round-trip settles, so the next row is one letter away
            // instead of another double-tap. Escape cancels (row slides back,
            // nothing dispatched); Space/Tab commit and end the gesture.
            const reopen = e.key === 'Enter';
            const container = jumpMoveContainer;
            if (e.key === 'Escape') {
                cancelJumpMove();
                clearJumpPlaced();
                return;
            }
            endJumpMove(reopen);
            if (!reopen) clearJumpPlaced();
            if (reopen) {
                setTimeout(() => {
                    if (jumpOpen || gotoOpen || jumpMoveContainer) return;
                    if (!container || !container.isConnected) return;
                    const overlay = document.querySelector('[data-mouseless-overlay]');
                    if (overlay && isVisible(overlay)) return;
                    openJump();
                }, 150);
            }
            return;
        }
        // Any other key just drops the row where it is.
        endJumpMove();
    }

    function shakeJump() {
        const box = document.getElementById('fi-mouseless-jump');
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

    function commitJump(target) {
        const el = target.el;
        console.log(TAG, 'jump: commit', target.label, '->', el);
        closeJump();
        // Every commit is a click avoided — and three commits teach ui.jump.
        teachRecordUsed('ui.jump');
        statsRecord('ui.jump', 'kb');
        if (!el.isConnected) return; // Livewire morphed it away mid-mode
        // A sortable drag handle can't be clicked into anything — grab its row
        // instead and let ↑/↓ move it (repeater items, key-value rows,
        // reorderable tables all share the x-sortable machinery).
        const handle = el.closest('[x-sortable-handle]');
        if (handle) { startJumpMove(handle); return; }
        if (target.commit === 'chart') {
            // Hover equivalent: activate the segment and show its tooltip.
            try {
                const { chart, chartDataset: d, chartIndex: i } = target;
                const pos = chart.getDatasetMeta(d).data[i].tooltipPosition();
                chart.setActiveElements([{ datasetIndex: d, index: i }]);
                if (chart.tooltip && chart.tooltip.setActiveElements) chart.tooltip.setActiveElements([{ datasetIndex: d, index: i }], pos);
                chart.update();
            } catch (err) {
                console.warn(TAG, 'jump: chart tooltip failed', err);
            }
            return;
        }
        if (target.commit === 'chartlegend') {
            // Same effect as clicking the legend entry: Chart.js's own legend
            // onClick toggles the dataset (or the pie segment) and updates.
            try {
                const { chart, chartLegendIndex: i } = target;
                const item = chart.legend.legendItems[i];
                const onClick = chart.legend.options && chart.legend.options.onClick;
                if (typeof onClick === 'function') {
                    onClick.call(chart.legend, null, item, chart.legend);
                } else if (typeof item.datasetIndex === 'number') {
                    chart.setDatasetVisibility(item.datasetIndex, !chart.isDatasetVisible(item.datasetIndex));
                    chart.update();
                } else {
                    chart.toggleDataVisibility(item.index);
                    chart.update();
                }
            } catch (err) {
                console.warn(TAG, 'jump: chart legend toggle failed', err);
            }
            return;
        }
        if (target.commit === 'focus') {
            el.focus();
            el.scrollIntoView?.({ block: 'nearest' });
            // Date/time inputs: focusing alone would still need a click on the
            // calendar icon (a shadow-DOM part, unreachable as a target) — pop
            // the native picker directly while we still hold the keypress's
            // user activation. Throws without activation → ignore.
            const pickable = ['date', 'month', 'week', 'time', 'datetime-local'];
            if (el.tagName === 'INPUT' && pickable.includes((el.type || '').toLowerCase()) && typeof el.showPicker === 'function') {
                try { el.showPicker(); } catch { /* no user activation (or unsupported) — focus is enough */ }
            }
            return;
        }
        if (target.commit === 'dropdown') {
            openDropdown(el);
            focusOpenedPanel(el);
            return;
        }
        el.click();
        if (typeof el.focus === 'function') el.focus();
    }

    // Nudge stashed by a navigating click, shown on the destination page.
    const TEACH_PENDING_KEY = 'mouseless_teach_pending';

    // ---- teach missed shortcuts (plugin ->teach(), per-user state in DB) ----
    // A real mouse click on something that has a shortcut earns a Filament
    // notification: "you could have just hit ⌥E" with change / not-this-action
    // / never buttons. Rules that keep it helpful instead of annoying:
    //   - at most ONE nudge per page view,
    //   - repeat nudges per action back off (hourly → daily → weekly, server-side),
    //   - 3 keyboard uses in a row mark the action taught — never nudged again
    //     (a mouse click on its button breaks the streak),
    //   - only trusted pointer clicks count: our synthetic clicks and
    //     keyboard-activated buttons (detail === 0) never nudge.
    function setupTeach() {
        const teach = cfg.teach;
        if (!teach || typeof window.FilamentNotification !== 'function') return;
        const states = teach.states || {};
        const t = teach.strings || {};
        // Statistics-informed focus: the server ranks the user's most-clicked
        // still-teachable actions. Empty (statistics off, or nothing ranked
        // yet) = no restriction — teach behaves exactly as without stats.
        const clickPriority = Array.isArray(teach.clickPriority) ? teach.clickPriority : [];
        let muted = !!teach.muted;
        let nudgedThisPage = false;
        document.addEventListener('livewire:navigated', () => {
            nudgedThisPage = false;
            replayPendingNudge();
        });
        console.log(TAG, 'teach: armed,', Object.keys(states).length, 'action state(s), muted=', muted);

        // The notification's buttons $dispatch Livewire events (persisted by
        // the TeachNudges component). Mirror them locally so the running page
        // honors the choice without a reload.
        window.addEventListener('mouseless-teach-dismiss', (e) => {
            const id = e.detail?.actionId;
            if (id) (states[id] ??= {}).dismissed = true;
        });
        window.addEventListener('mouseless-teach-mute', () => { muted = true; });

        // Keyboard use advances the taught streak (dispatch() calls this hook).
        teachRecordUsed = (actionId) => {
            const st = states[actionId] ??= {};
            if (st.learned) return;
            st.streak = (st.streak || 0) + 1;
            if (st.streak >= 3) {
                st.learned = true;
                console.log(TAG, 'teach:', actionId, 'used 3× in a row — taught');
            }
            try { window.Livewire?.dispatch?.('mouseless-teach-used', { actionId }); } catch {}
        };

        document.addEventListener('click', (e) => {
            if (!e.isTrusted || e.detail === 0) return; // synthetic / keyboard "click"
            // Never nudge for clicks on mouseless's own chrome.
            if (e.target.closest?.('.fi-no, [data-mouseless-overlay], [data-mouseless-goto], [data-mouseless-teach]')) return;

            const hit = teachClickHit(e);
            if (!hit) { maybeJumpNudge(e); return; }
            const { actionId, combo, target, keyText } = hit;
            const st = states[actionId] ??= {};

            // The mouse was used — the "3 in a row" chain is broken.
            if (!st.learned && (st.streak || 0) > 0) {
                st.streak = 0;
                try { window.Livewire?.dispatch?.('mouseless-teach-clicked', { actionId }); } catch {}
            }

            if (muted || nudgedThisPage || st.dismissed || st.learned) return;
            // Spend the one nudge per page on a frequent offender: actions
            // outside the click-priority top list wait their turn.
            if (clickPriority.length && !clickPriority.includes(actionId)) {
                console.log(TAG, 'teach:', actionId, 'not among the most-clicked actions, saving the nudge');
                return;
            }
            if (st.nextAt && Date.now() < st.nextAt) {
                console.log(TAG, 'teach:', actionId, 'backing off until', new Date(st.nextAt).toISOString());
                return;
            }

            nudgedThisPage = true;
            const payload = nudgePayload(actionId, combo, target, keyText);

            // A link click unloads this page (SPA morph or full load) — the
            // notification would die unseen while still burning its backoff.
            // Stash the nudge and let the DESTINATION page show it.
            const link = e.target.closest?.('a[href]');
            const navigates = link
                && !e.metaKey && !e.ctrlKey && !e.shiftKey
                && link.getAttribute('target') !== '_blank'
                && !(link.getAttribute('href') || '').startsWith('#');
            if (navigates) {
                console.log(TAG, 'teach: deferring nudge across navigation', actionId);
                try { sessionStorage.setItem(TEACH_PENDING_KEY, JSON.stringify({ ...payload, at: Date.now() })); } catch {}
                return;
            }

            renderNudge(payload);
        }, true);

        // Tab-reaching a control IS keyboard navigation — it advances the
        // ui.jump streak (one count per burst, so a single form walk isn't
        // an instant "taught"). Combined with the click-side streak break
        // above, the jump nudge only ever targets users whose controls get
        // reached by mouse.
        let lastTabAt = 0;
        let lastTabCountAt = 0;
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Tab') lastTabAt = Date.now();
        }, true);
        document.addEventListener('focusin', (e) => {
            if (cfg.debug) console.log(TAG, 'teach: focusin', e.target?.tagName, 'tabAge=', Date.now() - lastTabAt);
            if (Date.now() - lastTabAt > 150) return; // focus not caused by Tab
            if (Date.now() - lastTabCountAt < 10_000) return; // one per burst
            const el = e.target?.closest?.('input, select, textarea, button, a[href], [contenteditable], [role="button"], [role="tab"], [role="slider"]');
            if (!el) return;
            lastTabCountAt = Date.now();
            console.log(TAG, 'teach: Tab navigation counts as ui.jump keyboard use');
            teachRecordUsed('ui.jump');
        }, true);

        // ---- cheatsheet nudge: teach that the overlay exists at all ----
        // No click to hook on (nothing on the page IS the cheatsheet), so it
        // fires on a timer once the page has settled — same one-per-page slot,
        // backoff, dismiss and mute as every click nudge. dispatch() already
        // advances the ui.help streak on every keyboard open, so three opens
        // mark it taught like any other action.
        if (teach.helpNudge !== false) {
            const armHelpNudge = () => setTimeout(maybeHelpNudge, 12_000);
            armHelpNudge();
            document.addEventListener('livewire:navigated', armHelpNudge);
        }

        function maybeHelpNudge() {
            const st = states['ui.help'] ??= {};
            if (muted || nudgedThisPage || st.dismissed || st.learned) return;
            if (st.nextAt && Date.now() < st.nextAt) return;
            if (document.visibilityState !== 'visible') return; // backgrounded tab — retry next page
            const combo = bindingOf('ui.help');
            if (!combo) return;
            // They're literally looking at the cheatsheet (or mid-gesture).
            const overlay = document.querySelector('[data-mouseless-overlay]');
            if (overlay && isVisible(overlay)) return;
            if (jumpOpen || gotoOpen) return;
            nudgedThisPage = true;
            console.log(TAG, 'teach: cheatsheet nudge');
            renderNudge({
                actionId: 'ui.help',
                combo,
                key: displayCombo(combo),
                label: '',
                title: t.help_title,
            });
        }

        // A nudge stashed by a navigating click on the previous page: show it
        // here, where the user actually is. Stale entries (reopened tab) drop.
        function replayPendingNudge() {
            let pending = null;
            try {
                pending = JSON.parse(sessionStorage.getItem(TEACH_PENDING_KEY) || 'null');
                sessionStorage.removeItem(TEACH_PENDING_KEY);
            } catch {}
            if (!pending || Date.now() - (pending.at || 0) > 15_000) return;
            if (muted || nudgedThisPage) return;
            const st = states[pending.actionId] ??= {};
            if (st.dismissed || st.learned) return;
            nudgedThisPage = true;
            // Let the destination page settle (and Livewire finish booting).
            setTimeout(() => renderNudge(pending), 600);
        }
        replayPendingNudge();

        // Fallback nudge: the click had no dedicated shortcut, but jump mode
        // could have reached the target. Deliberately LOW priority — it only
        // ever fires for clicks no other nudge claims — with the same
        // dismiss/mute/backoff/learn mechanics as every other action.
        function maybeJumpNudge(e) {
            if (!cfg.jump) return;
            const el = e.target.closest?.('button, a[href], input, select, textarea, [role="button"], [role="tab"], .fi-copyable, [x-sortable-handle]');
            if (!el || el.closest('.fi-sidebar, .fi-breadcrumbs, .phpdebugbar')) return;
            const st = states['ui.jump'] ??= {};
            // The mouse reached a control — that breaks the keyboard streak,
            // exactly like clicking any other action's target.
            if (!st.learned && (st.streak || 0) > 0) {
                st.streak = 0;
                try { window.Livewire?.dispatch?.('mouseless-teach-clicked', { actionId: 'ui.jump' }); } catch {}
            }
            if (muted || nudgedThisPage || st.dismissed || st.learned) return;
            if (st.nextAt && Date.now() < st.nextAt) return;
            nudgedThisPage = true;
            const mod = (String(cfg.jump.chord || 'ctrl,ctrl').split(',')[0] || 'ctrl').trim();
            renderNudge({
                actionId: 'ui.jump',
                combo: cfg.jump.chord || 'ctrl,ctrl',
                key: '2× ' + displayCombo(mod),
                label: '',
            });
        }

        // What shortcut the click bypassed. Bindings resolve via the same
        // targets the dispatcher uses; on top of that, structural clicks
        // (rows, tabs, sidebar, breadcrumbs) teach their keyboard equivalent.
        function teachClickHit(e) {
            const el = e.target;
            const clickRow = el.closest?.('.fi-ta-row, .fi-ta-record') ?? null;

            // Buttons first — an action button inside a row must win over the
            // row-click rule below.
            for (const [combo, actionId] of Object.entries(keyToAction)) {
                let target = null;
                try { target = resolveActionTarget(actionId, clickRow); } catch { continue; }
                if (target && (target === el || target.contains(el))) {
                    return { actionId, combo, target };
                }
            }

            // Row checkbox → Space (with the row cursor on it).
            if (clickRow && el.closest?.('.fi-ta-record-checkbox, input[type="checkbox"]')) {
                const combo = bindingOf('list.toggle-row');
                if (combo) {
                    const jk = bindingOf('list.next-row');
                    return {
                        actionId: 'list.toggle-row',
                        combo,
                        target: clickRow,
                        keyText: (jk ? displayCombo(jk) + ' + ' : '') + displayCombo(combo),
                    };
                }
                return null;
            }

            // Row click opens the record → j/k to move the cursor, Enter to open.
            if (clickRow && el.closest?.('a, button')) {
                const j = bindingOf('list.next-row');
                const k = bindingOf('list.prev-row');
                if (j || k) {
                    return {
                        actionId: 'list.next-row',
                        combo: j || k,
                        target: clickRow,
                        keyText: [j, k].filter(Boolean).map(displayCombo).join('/') + ' + Enter',
                    };
                }
            }

            // Tab click → ⌥1–⌥9 (engine-level, so synthesize the combo).
            const tab = el.closest?.('.fi-tabs-item');
            if (tab) {
                const index = tabItems().indexOf(tab);
                if (index >= 0 && index < 9) {
                    return { actionId: 'ui.tab-jump', combo: `alt+${index + 1}`, target: tab };
                }
                return null;
            }

            // Sidebar navigation → the "go to" palette.
            if (el.closest?.('.fi-sidebar-nav a, .fi-sidebar a.fi-sidebar-item-btn')) {
                const combo = bindingOf('nav.goto');
                if (combo) return { actionId: 'nav.goto', combo, target: el.closest('a') };
                return null;
            }

            // Breadcrumb back to the list from a record page → Escape.
            if (el.closest?.('.fi-breadcrumbs a')
                && /^(\/[^\/]+\/[^\/]+)\/(create|\d+(\/(edit|view))?)$/.test(window.location.pathname)) {
                const combo = bindingOf('ui.close');
                if (combo) return { actionId: 'ui.close', combo, target: el.closest('.fi-breadcrumbs a') };
            }

            return null;
        }

        // The combo bound to an action, if any (reverse of keyToAction).
        function bindingOf(actionId) {
            for (const [combo, id] of Object.entries(keyToAction)) {
                if (id === actionId) return combo;
            }
            return null;
        }

        // Visible text of an element WITHOUT its count badges — a tab renders
        // as "Radioactive 12" (label + .fi-badge), only "Radioactive" is the name.
        function elementLabel(el) {
            const clone = el.cloneNode(true);
            clone.querySelectorAll('.fi-badge').forEach(b => b.remove());
            return (clone.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 60);
        }

        // Which body string fits the action. The generic '":label" works
        // without the mouse' only reads well when the label IS the clicked
        // button — structural targets (tabs, links, rows, chevrons, column
        // headers) describe what the shortcut DOES instead.
        const TEACH_BODY_KEYS = {
            'ui.tab-jump': 'body_tab',
            'nav.goto': 'body_nav',
            'nav.dashboard': 'body_nav',
            'list.next-row': 'body_row_open',
            'list.prev-row': 'body_row_open',
            'list.toggle-row': 'body_row_select',
            'list.select-next-row': 'body_row_select',
            'list.select-prev-row': 'body_row_select',
            'ui.close': 'body_back',
            'list.search': 'body_search',
            'nav.command-palette': 'body_search_global',
            'list.next-page': 'body_page',
            'list.prev-page': 'body_page',
            'list.sort': 'body_sort',
            'ui.jump': 'body_jump',
            'ui.help': 'body_help',
        };

        // Everything a nudge needs, resolved while the clicked element still
        // exists — deferred nudges render on the NEXT page, after this DOM
        // is gone.
        function nudgePayload(actionId, combo, target, keyText = null) {
            const key = keyText || displayCombo(combo);
            // A target inside a table row has no usable text of its own — its
            // textContent is every cell of the row concatenated ("Bröseli von
            // Emmental AGT-FETA-610 Fondue Fighters …"). Name the ACTION there
            // (translated: "Bearbeiten", "Zeile aus-/abwählen"); element text
            // only makes sense for real labeled buttons outside rows — minus
            // any count badge ("Radioactive 12" is a tab label plus its badge).
            const inRow = !!target.closest?.('.fi-ta-row, .fi-ta-record');
            const name = (cfg.actionNames?.[actionId] || '').trim();
            const label = inRow
                ? name
                : (elementLabel(target) || target.getAttribute?.('aria-label') || name || '');
            return { actionId, combo, key, label };
        }

        // `title` overrides the "you could have just hit" template — the
        // cheatsheet nudge is proactive, nothing was clicked.
        function renderNudge({ actionId, combo, key, label, title }) {
            console.log(TAG, 'teach: nudging', actionId, '=', combo);

            const actions = [];
            // Engine-level combos (⌥n tab jump) aren't rows on /my-shortcuts —
            // no point deep-linking a search that can't find them.
            if (teach.shortcutsUrl && actionId in bindings) {
                actions.push(new window.FilamentNotificationAction('change')
                    .label(t.change || 'Change shortcut')
                    .button()
                    .url(`${teach.shortcutsUrl}?search=${encodeURIComponent(combo)}`));
            }
            actions.push(new window.FilamentNotificationAction('dismiss')
                .label(t.dismiss || 'Not for this action')
                .link()
                .dispatch('mouseless-teach-dismiss', { actionId })
                .close());
            actions.push(new window.FilamentNotificationAction('mute')
                .label(t.mute || 'Never notify')
                .link()
                .color('gray')
                .dispatch('mouseless-teach-mute')
                .close());

            const notification = new window.FilamentNotification()
                .title((title || t.title || 'You could have just hit :key').replace(':key', key))
                .icon('heroicon-o-cursor-arrow-ripple')
                .seconds(12)
                .actions(actions);
            const bodyTemplate = t[TEACH_BODY_KEYS[actionId] || 'body']
                || t.body || '“:label” works without the mouse.';
            // Templates without :label (e.g. "Opening a row works without the
            // mouse.") render even when no usable label was extractable.
            if (label || !bodyTemplate.includes(':label')) {
                notification.body(bodyTemplate.replace(':label', label));
            }
            notification.send();

            // Advance the backoff locally (a reload must not double-nudge)
            // and persist it. Same doubling as Nudge::backoffHours().
            const st = states[actionId] ??= {};
            st.shown = (st.shown || 0) + 1;
            st.nextAt = Date.now() + Math.min(2 ** Math.min(st.shown - 1, 10), 168) * 3_600_000;
            try { window.Livewire?.dispatch?.('mouseless-teach-shown', { actionId }); } catch {}
        }
    }

    // "alt+shift+e" → "⌥⇧E" on the Mac, "Alt+Shift+E" elsewhere. Combos are
    // pre-normalized ('mod' already resolved to cmd/ctrl by normalize()).
    function displayCombo(combo) {
        const mods = IS_MAC_PLATFORM
            ? { cmd: '⌘', meta: '⌘', ctrl: '⌃', alt: '⌥', shift: '⇧' }
            : { cmd: 'Ctrl', meta: 'Meta', ctrl: 'Ctrl', alt: 'Alt', shift: 'Shift' };
        const keys = {
            enter: 'Enter', escape: 'Esc', space: 'Space', tab: 'Tab',
            arrowup: '↑', arrowdown: '↓', arrowleft: '←', arrowright: '→',
        };
        const parts = combo.split('+').map((p) => {
            const lower = p.toLowerCase();
            return mods[lower] ?? keys[lower] ?? (p.length === 1 ? p.toUpperCase() : p);
        });
        return parts.join(IS_MAC_PLATFORM ? '' : '+');
    }

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

        // A language switcher / recently-viewed list only exists when the app
        // provides one — they opt in via a data-mouseless attribute on their
        // trigger. Without it the binding has nothing to press.
        if (id === 'nav.language' || id === 'nav.recently-viewed') {
            const el = document.querySelector(`[data-mouseless="${id}"]`);
            return !!(el && isVisible(el));
        }

        // Logout exists whenever Filament's user menu renders its POST form.
        if (id === 'nav.logout') {
            return !!(document.querySelector('[data-mouseless="nav.logout"]')
                || document.querySelector('form[action$="/logout"]'));
        }

        // The notifications bell only renders with ->databaseNotifications().
        if (id === 'nav.notifications') {
            if (document.querySelector('[data-mouseless="nav.notifications"]')) return true;
            return !!Array.from(document.querySelectorAll(
                '.fi-topbar-database-notifications-btn, .fi-sidebar-database-notifications-btn',
            )).find(isVisible);
        }

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

        if (id === 'list.next-page' || id === 'list.prev-page') {
            const rel = id === 'list.next-page' ? 'next' : 'prev';
            const sel = `.fi-pagination [rel="${rel}"], .fi-pagination-${rel === 'next' ? 'next' : 'previous'}-btn`;
            return !!Array.from(document.querySelectorAll(sel)).find(isVisible);
        }

        if (id === 'list.search') {
            return !!findTableSearchInput(document);
        }

        if (id === 'list.filter') {
            if (document.querySelector('.fi-ta-filters-trigger-action-ctn button')
                || document.querySelector('.fi-ta-filters-dropdown .fi-dropdown-trigger button')) return true;
            // Fall through: modal-style filters render a plain action button.
        }

        // The bulk-actions trigger only shows while rows are selected — treat
        // it as available whenever the table selects at all (checkbox column).
        if (id === 'list.bulk-action') {
            if (document.querySelector('[data-mouseless="list.bulk-action"]')) return true;
            if (Array.from(document.querySelectorAll('.fi-ta-header-toolbar .fi-dropdown-trigger .fi-ac-btn-group')).find(isVisible)) return true;
            return !!document.querySelector('.fi-ta-record-checkbox');
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

        // AltGr characters (Windows reports them as Ctrl+Alt, Linux as a true
        // AltGraph modifier) are typing, never shortcuts — @ on a German layout
        // must not trigger ctrl+alt+q (logout). macOS is exempt: there the
        // browsers set AltGraph for the Option key itself, which IS our
        // shortcut modifier (typing safety on Mac comes from input suppression).
        if (!IS_MAC_PLATFORM && e.getModifierState && e.getModifierState('AltGraph')) {
            console.log(TAG, 'ignored AltGr combo (typing, not a shortcut)');
            return;
        }

        // The "go to" palette owns every keystroke while it's open.
        if (gotoOpen) { handleGotoKey(e); return; }

        // Jump mode owns every keystroke while its badges are up.
        if (jumpOpen) { handleJumpKey(e); return; }

        // Move mode (grabbed sortable row) owns arrows/enter until dropped.
        if (jumpMoveContainer) { handleJumpMoveKey(e); return; }

        // An open action menu claims ↑/↓/j/k/Home/End for walking its items.
        if (dropdownMenuNav(e)) return;

        // The my-shortcuts page is capturing a combo (recording / key search)
        // — stay out of the way so the captured key never triggers an action.
        if (document.querySelector('[data-mouseless-recording]')) return;

        // Keys the FOCUSED control natively consumes must reach it, never the
        // engine: a <select> needs arrows/Enter/Space/type-ahead, a checkbox
        // or radio needs Space (+ arrows for group nav), a button needs
        // Space/Enter to activate, a slider handle needs arrows. Escape blurs
        // a <select> so the escape cascade continues on the next press.
        if (keysStayNative(e)) {
            if (e.key === 'Escape' && e.target.tagName === 'SELECT') e.target.blur();
            console.log(TAG, 'key stays native on', e.target.tagName, e.target.type || e.target.getAttribute('role') || '');
            return;
        }

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

        // Editable contexts suppress the whole Alt family (⌥ / ⌥⇧ / ⌥⌃), not just
        // bare keys: on the Mac every ⌥+letter types a character (Swiss ⌥G = @,
        // German ⌥L = @, ⌥E = €), and ⌥+arrows are word-jumps. Only the mod tier
        // (⌘/Ctrl without Alt) and named keys stay live while typing.
        if (inInput && (isBare || e.altKey)) {
            console.log(TAG, 'suppressed key inside input/textarea (typing context)');
            return;
        }

        // ⌥1–⌥9 jump straight to the Nth tab — browser-tab muscle memory.
        // Engine-level (not rebindable) and only claimed when the page has tabs.
        if (e.altKey && !e.ctrlKey && !e.metaKey && !e.shiftKey && !inInput && /^Digit[1-9]$/.test(e.code)) {
            const tabs = tabItems();
            const tab = tabs[Number(e.code.slice(5)) - 1];
            if (tab) {
                console.log(TAG, 'tab jump ->', tab.textContent.trim());
                e.preventDefault();
                e.stopPropagation();
                teachRecordUsed('ui.tab-jump');
                statsRecord('ui.tab-jump', 'kb');
                tab.click();
                tab.focus();
                return;
            }
        }

        // Bare ↑/↓ continue row navigation once a row is focused (⇧ extends the
        // selection) — everywhere else the arrows keep their native scrolling.
        // Not a rebindable action: it's the arrow-continuation of j/k.
        if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && !e.altKey && !e.ctrlKey && !e.metaKey) {
            const row = document.activeElement?.closest?.('.fi-ta-row, .fi-ta-record');
            // A modal/slide-over showing rows claims the arrows outright — ↓
            // enters its list at the top like a first j. And from a table
            // search box, ↓ drops into the result rows (combobox convention);
            // all other inputs keep their caret keys.
            const arrowModal = Array.from(document.querySelectorAll('.fi-modal-window')).find(isVisible);
            const modalHasRows = !!arrowModal && Array.from(arrowModal.querySelectorAll('.fi-ta-row, .fi-ta-record')).some(isVisible);
            const ae = document.activeElement;
            const fromSearch = e.key === 'ArrowDown' && inInput && ae && (
                (ae.type || '').toLowerCase() === 'search'
                || Array.from(ae.attributes || []).some(a => a.name.startsWith('wire:model') && /search/i.test(a.value))
            );
            if ((!inInput && (row || modalHasRows)) || fromSearch) {
                const dir = e.key === 'ArrowDown' ? 'next' : 'prev';
                console.log(TAG, 'arrow row-nav ->', dir, e.shiftKey ? '(extend selection)' : '');
                e.preventDefault();
                e.stopPropagation();
                dispatch(e.shiftKey && !fromSearch ? `list.select-${dir}-row` : `list.${dir}-row`);
                return;
            }
        }

        if (!actionId) return;

        // Space only means "toggle row" while the cursor is on a row — anywhere
        // else (a column-manager checkbox, a button) it keeps its native job.
        if (actionId === 'list.toggle-row' && !document.activeElement?.closest?.('.fi-ta-row, .fi-ta-record')) {
            console.log(TAG, 'list.toggle-row: no focused row, leaving Space to the browser');
            return;
        }

        // Guarded mod-tier combos — claim the key only when the context is right,
        // otherwise stay out of the browser's way entirely (no preventDefault).
        if (actionId === 'list.select-all' && (inInput || !document.querySelector('.fi-ta-record-checkbox'))) {
            console.log(TAG, 'list.select-all: not in list context, leaving mod+a to the browser');
            return;
        }
        if (actionId === 'record.copy-markdown') {
            const sel = window.getSelection?.();
            if (inInput || (sel && !sel.isCollapsed)) {
                console.log(TAG, 'record.copy-markdown: text selected / in input, leaving mod+c to the browser');
                return;
            }
        }
        // F5 only means "refresh the table" when the page has a refresh action
        // — otherwise it stays the browser's reload key. Swallowing it with a
        // "no match" toast would break the most muscle-memoried key there is.
        if (actionId === 'list.refresh'
            && !findActionButton('refresh', document, true)
            && !document.querySelector('[data-mouseless="list.refresh"]')) {
            console.log(TAG, 'list.refresh: no refresh action here, leaving F5 to the browser');
            return;
        }

        if (actionId === 'record.print') {
            // Overlay open → let the browser print; the print stylesheet renders
            // the cheatsheet. No print action on the page → browser print too.
            const overlay = document.querySelector('[data-mouseless-overlay]');
            if (overlay && isVisible(overlay)) {
                console.log(TAG, 'record.print: help overlay open, browser print = cheatsheet');
                return;
            }
            if (!findActionButton('print', document, true)) {
                console.log(TAG, 'record.print: no print action here, leaving mod+p to the browser');
                return;
            }
        }

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
        // Pages pre-render every closed modal/slideover (actions, the column
        // manager, filters …), so "the first .fi-modal-window" is usually a
        // hidden one — any VISIBLE window counts.
        return Array.from(document.querySelectorAll('.fi-modal-window')).some(isVisible);
    }

    function dispatch(actionId) {
        console.log(TAG, 'dispatch start', actionId);
        teachRecordUsed(actionId);
        statsRecord(actionId, 'kb');

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
            //   3. Typing in a field → release focus only; the NEXT Esc
            //      escalates. Navigating away mid-keystroke (step 4) would
            //      throw the user's form input away on a single Esc.
            if (isTextInputFocus(document.activeElement)) {
                console.log(TAG, 'dispatch -> ui.close: blurring text input (next Esc escalates)');
                document.activeElement.blur?.();
                return;
            }
            const path = window.location.pathname;
            const m = path.match(/^(\/[^\/]+\/[^\/]+)\/(create|\d+(\/(edit|view))?)$/);
            if (m) {
                console.log(TAG, 'dispatch -> ui.close: back to', m[1]);
                return goUrl(m[1]);
            }
            //   5. Something has focus → release it; the NEXT Esc escalates.
            const focused = document.activeElement;
            if (focused && focused !== document.body) {
                console.log(TAG, 'dispatch -> ui.close (blur active element)');
                focused.blur?.();
                return;
            }
            //   6. escapeToDashboard() (opt-in): nothing left to close — leave for
            //      the configured page, unless the list carries filter/search/
            //      tab/group state a navigation would silently throw away.
            if (cfg.escapeTo) {
                const target = new URL(cfg.escapeTo, window.location.origin);
                if (target.pathname === window.location.pathname) {
                    console.log(TAG, 'dispatch -> ui.close: already on the escape target');
                    return;
                }
                if (listLooksFiltered()) {
                    console.log(TAG, 'dispatch -> ui.close: list is filtered, staying put');
                    return;
                }
                console.log(TAG, 'dispatch -> ui.close: escaping to', cfg.escapeTo);
                return goUrl(cfg.escapeTo);
            }
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
            // Filament renders logout as a POST form in the user menu
            // (Action::postToUrl()) — there is no URL to navigate to, and the
            // form exists in the DOM even while the dropdown is closed.
            if (actionId === 'nav.logout') {
                const form = document.querySelector('form[action$="/logout"]');
                if (form) {
                    console.log(TAG, 'dispatch -> nav.logout: submitting', form.action);
                    form.requestSubmit ? form.requestSubmit() : form.submit();
                    return;
                }
                console.warn(TAG, 'nav.logout: no logout form on this page');
                toast(strings.no_match);
                return;
            }
            // The database-notifications bell (topbar or sidebar variant) —
            // only rendered when the panel enables ->databaseNotifications().
            if (actionId === 'nav.notifications') {
                const bell = Array.from(document.querySelectorAll(
                    '.fi-topbar-database-notifications-btn, .fi-sidebar-database-notifications-btn',
                )).find(isVisible);
                if (bell) {
                    console.log(TAG, 'dispatch -> nav.notifications: opening bell', bell);
                    bell.click();
                    return;
                }
                console.warn(TAG, 'nav.notifications: no database-notifications trigger (panel feature off?)');
                toast(strings.no_match);
                return;
            }
            if (actionId === 'nav.profile') {
                const url = cfg.shortcutsUrl || '/admin/my-shortcuts';
                console.log(TAG, 'dispatch -> nav.profile:', url);
                return goUrl(url);
            }
            if (actionId === 'nav.dashboard') {
                // Prefer the panel's real home URL (where Filament lands you
                // after login). It need not be "/admin" — any panel path works.
                if (cfg.homeUrl) {
                    console.log(TAG, 'dispatch -> nav.dashboard: home URL', cfg.homeUrl);
                    return goUrl(cfg.homeUrl);
                }
                const navLinks = document.querySelectorAll('.fi-sidebar-nav a.fi-sidebar-item-button, .fi-sidebar a.fi-sidebar-item-button, .fi-sidebar-nav a.fi-sidebar-item-btn, .fi-sidebar a.fi-sidebar-item-btn');
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
                // Panel base from the server-provided home URL — panels need
                // not live at /admin (the demo runs at the domain root).
                let base = '/admin';
                try { base = new URL(cfg.homeUrl || '/admin', window.location.origin).pathname.replace(/\/$/, ''); } catch {}
                const url = `${base}/${actionId.replace('nav.resource.', '')}`;
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
            // An open modal makes the page behind it inert — its rows must
            // never catch the cursor.
            const rowNavModal = Array.from(document.querySelectorAll('.fi-modal-window')).find(isVisible);
            const rowElements = Array.from((rowNavModal || document).querySelectorAll('.fi-ta-row, .fi-ta-record')).filter(isVisible);
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

        // list.next-page / list.prev-page — step through Filament's paginator.
        // Filament v5 marks every page-nav control with rel="next"/rel="prev":
        // the labeled "Next"/"Previous" <button> (shown on narrow viewports) and
        // the chevron <li> in the numbered list (shown on wide ones) both carry
        // it, and a direction's control is only rendered when that page exists.
        // So we match on rel, keep the one that's actually visible in the current
        // layout, and prefer the table the cursor is in when several share a page.
        if (actionId === 'list.next-page' || actionId === 'list.prev-page') {
            const rel = actionId === 'list.next-page' ? 'next' : 'prev';
            const sel = `.fi-pagination [rel="${rel}"], .fi-pagination-${rel === 'next' ? 'next' : 'previous'}-btn`;
            const scope = document.activeElement?.closest?.('[x-data*="filamentTable"]');
            const btn = (scope && Array.from(scope.querySelectorAll(sel)).find(isVisible))
                || Array.from(document.querySelectorAll(sel)).find(isVisible);
            console.log(TAG, 'dispatch -> list page nav', actionId, 'btn=', btn);
            if (btn) {
                btn.click();
                return;
            }
            // No control in this direction = already on the first / last page
            // (or pagination is disabled on this table).
            console.warn(TAG, actionId, ': no pagination control (first/last page or pagination disabled)');
            toast(strings.no_match);
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

        // list.filter — Filament v5 wraps the funnel icon-button in a dedicated
        // container; older builds bound it via toggleFiltersDropdown. Neither is
        // a mountAction wire-click, so the generic action finder misses it.
        if (actionId === 'list.filter') {
            const trigger = document.querySelector('.fi-ta-filters-trigger-action-ctn button')
                || document.querySelector('.fi-ta-filters-dropdown .fi-dropdown-trigger button')
                || document.querySelector('[x-on\\:click*="toggleFiltersDropdown"]');
            console.log(TAG, 'dispatch -> list.filter, trigger=', trigger);
            if (trigger && isVisible(trigger)) {
                openDropdown(trigger);
                focusOpenedPanel(trigger);
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

        // ui.next-tab / ui.prev-tab — cycle through the active tab bar (list-page
        // filter tabs and form Tabs components share the .fi-tabs markup).
        if (actionId === 'ui.next-tab' || actionId === 'ui.prev-tab') {
            const tabs = tabItems();
            console.log(TAG, 'dispatch ->', actionId, 'tabs=', tabs.length);
            if (!tabs.length) {
                toast(strings.no_match);
                return;
            }
            const active = tabs.findIndex(t => t.classList.contains('fi-active')
                || (t.hasAttribute('aria-selected') && t.getAttribute('aria-selected') !== 'false'));
            const dir = actionId === 'ui.next-tab' ? 1 : -1;
            const next = tabs[((active === -1 ? 0 : active) + dir + tabs.length) % tabs.length];
            next.click();
            next.focus();
            return;
        }

        // crud.submit — submit the open form (save on edit pages, create on create
        // pages). Prefers the form the cursor is in; mod+Enter from any field.
        if (actionId === 'crud.submit') {
            const form = document.activeElement?.closest?.('form[wire\\:submit]')
                || Array.from(document.querySelectorAll('form[wire\\:submit]')).find(isVisible);
            const submit = form?.querySelector('button[type="submit"]');
            console.log(TAG, 'dispatch -> crud.submit, form=', form, 'button=', submit);
            if (submit && isVisible(submit) && !submit.disabled) {
                submit.click();
                return;
            }
            for (const name of ['save', 'create']) {
                const btn = findActionButton(name, document);
                if (btn) { btn.click(); return; }
            }
            console.warn(TAG, 'crud.submit: no submittable form on this page');
            toast(strings.no_match);
            return;
        }

        // list.select-all — mirror of the Escape deselect path: call the Alpine
        // method on the (focused) table component directly.
        if (actionId === 'list.select-all') {
            const scope = document.activeElement?.closest?.('[x-data*="filamentTable"]');
            const tables = scope ? [scope] : Array.from(document.querySelectorAll('[x-data*="filamentTable"]'));
            console.log(TAG, 'dispatch -> list.select-all across', tables.length, 'table(s)');
            for (const el of tables) {
                const data = window.Alpine?.$data?.(el);
                if (typeof data?.selectAllRecords === 'function') {
                    data.selectAllRecords();
                    return;
                }
            }
            console.warn(TAG, 'list.select-all: no filamentTable Alpine component responded');
            toast(strings.no_match);
            return;
        }

        // record.copy-markdown — serialize the focused row (or the open record)
        // to Markdown and put it on the clipboard.
        if (actionId === 'record.copy-markdown') {
            copyRecordAsMarkdown();
            return;
        }

        // list.sort — desktop Filament sorts via the per-column header buttons,
        // so there is no single trigger to click. Focus the first sort button
        // (of the focused table when several share the page): Tab moves along
        // the sortable columns, Enter sorts. Mobile builds render a "Sort by"
        // select instead — focus that when present.
        if (actionId === 'list.sort') {
            const scope = document.activeElement?.closest?.('[x-data*="filamentTable"]') || document;
            const target = Array.from(scope.querySelectorAll('.fi-ta-header-cell-sort-btn')).find(isVisible)
                || Array.from(scope.querySelectorAll('select[wire\\:model*="tableSort"]')).find(isVisible);
            console.log(TAG, 'dispatch -> list.sort, target=', target);
            if (target) {
                target.focus();
                return;
            }
            console.warn(TAG, 'list.sort: no sortable column header on this page');
            toast(strings.no_match);
            return;
        }

        // list.group — the "Group by" select above the table. Focus it: arrows
        // and typing pick the group column with the select's native keyboard UX.
        if (actionId === 'list.group') {
            const scope = document.activeElement?.closest?.('[x-data*="filamentTable"]') || document;
            const select = Array.from(scope.querySelectorAll('.fi-ta-grouping-settings-fields select, select[wire\\:model*="tableGrouping"]')).find(isVisible);
            console.log(TAG, 'dispatch -> list.group, select=', select);
            if (select) {
                select.focus();
                return;
            }
            console.warn(TAG, 'list.group: this table has no grouping');
            toast(strings.no_match);
            return;
        }

        // list.columns — the column-manager dropdown trigger (verified against
        // Filament v5: .fi-ta-col-manager-dropdown > .fi-dropdown-trigger > button).
        if (actionId === 'list.columns') {
            const selectors = [
                '.fi-ta-col-manager-dropdown .fi-dropdown-trigger button',
                '.fi-ta-col-manager-trigger button',
                '[data-mouseless="list.columns"]',
            ];
            for (const sel of selectors) {
                const el = Array.from(document.querySelectorAll(sel)).find(isVisible);
                if (el) {
                    console.log(TAG, 'dispatch -> list.columns via', sel);
                    openDropdown(el);
                    focusOpenedPanel(el);
                    return;
                }
            }
            console.warn(TAG, 'list.columns: no column-manager trigger found on this page');
            toast(strings.no_match);
            return;
        }

        // list.bulk-action — the bulk-actions dropdown in the table toolbar.
        // It's a grouped-action trigger (no mountAction wire-click), rendered
        // only while rows are selected, so the generic finder can't see it.
        if (actionId === 'list.bulk-action') {
            const trigger = Array.from(document.querySelectorAll(
                '[data-mouseless="list.bulk-action"], .fi-ta-header-toolbar .fi-dropdown-trigger .fi-ac-btn-group',
            )).find(isVisible);
            if (trigger) {
                console.log(TAG, 'dispatch -> list.bulk-action: opening bulk group', trigger);
                openDropdown(trigger);
                focusOpenedPanel(trigger);
                return;
            }
            console.warn(TAG, 'list.bulk-action: no bulk-actions trigger (select rows first)');
            toast(strings.no_match);
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

        if (rowScoped && !focusedRow && document.querySelector('.fi-ta-row, .fi-ta-record')) {
            console.warn(TAG, actionId, 'requires a focused row — press j/k first');
            toast(strings.no_match);
            return;
        }

        // Record actions (history, approve, …) also render as row actions.
        // With a cursored row, search it first so ⌥H opens THAT row's history
        // — a document-wide search would always hit row 1's button. Page-level
        // actions still resolve via the document pass.
        const scopes = rowScoped
            ? [focusedRow ?? document]
            : (focusedRow ? [focusedRow, document] : [document]);
        console.log(TAG, 'looking for Filament action button. Candidates:', candidates, 'scopes=', scopes.map(s => s === document ? 'document' : 'focusedRow'));

        for (const scope of scopes) {
            for (const name of candidates) {
                const btn = findActionButton(name, scope);
                console.log(TAG, '  candidate', name, scope === document ? '(document)' : '(row)', '->', btn ? 'FOUND' : 'not found');
                if (btn) {
                    console.log(TAG, '  button el=', btn);
                    btn.click();
                    console.log(TAG, '  click dispatched');
                    return;
                }
            }
        }

        if (clickByData(actionId, false)) {
            console.log(TAG, 'fallback: clicked [data-mouseless="' + actionId + '"]');
            return;
        }

        console.warn(TAG, 'NO MATCH for', actionId, '— no button, no data-attribute target');
        toast(strings.no_match);
    }

    const ROW_LEVEL_ACTIONS = new Set([
        'crud.edit', 'crud.delete', 'crud.view', 'crud.duplicate',
        'crud.force-delete', 'crud.restore', 'crud.detach',
    ]);

    // ---------- copy as markdown ----------
    // Focused table row → one-row Markdown table; open record page → title +
    // label/value bullet list from the infolist or form fields.

    function copyRecordAsMarkdown() {
        const row = document.activeElement?.closest?.('.fi-ta-row, .fi-ta-record');
        const md = (row && rowToMarkdown(row)) || pageToMarkdown();
        console.log(TAG, 'copy-markdown, source=', row ? 'row' : 'page', 'length=', md?.length);
        if (!md) {
            toast(strings.no_match);
            return;
        }
        if (navigator.clipboard?.writeText) {
            navigator.clipboard.writeText(md).then(
                () => toast(strings.copied),
                () => toast(legacyCopy(md) ? strings.copied : strings.no_match),
            );
            return;
        }
        // http:// contexts have no Clipboard API — fall back to execCommand.
        toast(legacyCopy(md) ? strings.copied : strings.no_match);
    }

    function legacyCopy(text) {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.cssText = 'position:fixed;top:-1000px;opacity:0';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        let ok = false;
        try { ok = document.execCommand('copy'); } catch { ok = false; }
        ta.remove();
        return ok;
    }

    function rowToMarkdown(row) {
        const cleanText = s => (s || '').replace(/\s+/g, ' ').trim();
        const cells = Array.from(row.querySelectorAll('td')).map(td => cleanText(td.textContent)).filter(Boolean);
        if (!cells.length) return cleanText(row.textContent) || null;
        const table = row.closest('table');
        const headers = table
            ? Array.from(table.querySelectorAll('thead th')).map(th => cleanText(th.textContent))
            : [];
        if (headers.filter(Boolean).length && headers.length >= cells.length) {
            const cols = headers.slice(0, cells.length);
            return `| ${cols.join(' | ')} |\n| ${cols.map(() => '---').join(' | ')} |\n| ${cells.join(' | ')} |`;
        }
        return cells.join(' · ');
    }

    function pageToMarkdown() {
        const cleanText = s => (s || '').replace(/\s+/g, ' ').trim();
        const title = cleanText(document.querySelector('.fi-header-heading, h1')?.textContent) || cleanText(document.title);
        const lines = [`# ${title}`, ''];

        // View pages: infolist entries render label + content pairs.
        document.querySelectorAll('.fi-in-entry').forEach(entry => {
            const label = cleanText(entry.querySelector('.fi-in-entry-label, dt, label')?.textContent);
            const value = cleanText(entry.querySelector('.fi-in-entry-content, dd')?.textContent);
            if (label || value) lines.push(`- **${label}**: ${value}`);
        });

        // Edit/create pages: read the current field values.
        if (lines.length <= 2) {
            document.querySelectorAll('.fi-fo-field').forEach(field => {
                const label = cleanText(field.querySelector('label')?.textContent);
                const input = field.querySelector('input:not([type="hidden"]), textarea, select');
                if (!label || !input) return;
                const value = input.tagName === 'SELECT'
                    ? cleanText(input.selectedOptions?.[0]?.textContent)
                    : (input.type === 'checkbox' ? (input.checked ? '✓' : '✗') : cleanText(input.value));
                lines.push(`- **${label}**: ${value}`);
            });
        }

        return lines.length > 2 ? lines.join('\n') : null;
    }

    function findActionButton(actionName, scope = document, quiet = false) {
        const log = (...args) => { if (!quiet) console.log(...args); };

        // Strategy 1: wire:click="mountAction('name'…)" — modal/method actions
        const wireSelectors = [
            `[wire\\:click^="mountAction('${actionName}'"]`,
            `[wire\\:click^="mountAction(&quot;${actionName}&quot;"]`,
            `[wire\\:click="${actionName}"]`,
            `[wire\\:click^="${actionName}("]`,
        ];
        // Actions collapsed into a "…" ActionGroup live inside a closed
        // dropdown panel — present in the DOM, invisible on screen. A
        // programmatic click still reaches their wire:click handler, so keep
        // the first such candidate as a last resort behind visible buttons.
        let groupedFallback = null;
        for (const sel of wireSelectors) {
            const all = scope.querySelectorAll(sel);
            if (all.length) log(TAG, '    [wire] selector matched', all.length, 'el(s):', sel);
            for (const el of all) {
                if (isVisible(el) && !el.disabled) return el;
                if (!groupedFallback && !el.disabled && el.closest('.fi-dropdown-panel')) groupedFallback = el;
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

        if (groupedFallback) {
            log(TAG, '    [group] no visible button — using grouped ("…") action', groupedFallback);
            return groupedFallback;
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

    // True when the focused control natively consumes this key — the engine
    // must not steal it (Space is bound to list.toggle-row, which would eat
    // a checkbox's toggle or a button's activation). Row LINKS deliberately
    // stay with the engine: Space on a link would only scroll the page, the
    // engine's row-toggle is more useful there. ⌘/Ctrl combos always stay
    // with the engine.
    function keysStayNative(e) {
        const t = e.target;
        if (!t || !t.tagName || e.ctrlKey || e.metaKey) return false;
        if (t.tagName === 'SELECT') return true; // arrows, Enter, Space, type-ahead
        const role = t.getAttribute('role');
        const type = t.tagName === 'INPUT' ? (t.type || '').toLowerCase() : null;
        if ((type === 'checkbox' || type === 'radio' || role === 'checkbox' || role === 'radio' || role === 'switch')
            && (e.key === ' ' || e.key.startsWith('Arrow'))) return true;
        if ((t.tagName === 'BUTTON' || role === 'button' || role === 'tab' || role === 'menuitem')
            && (e.key === ' ' || e.key === 'Enter')) return true;
        if (role === 'slider' && (e.key.startsWith('Arrow') || ['Home', 'End', 'PageUp', 'PageDown'].includes(e.key))) return true;
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

    // "Filtered" in the escape-to-dashboard sense: any table state the user
    // built up that a navigation would silently throw away.
    function listLooksFiltered() {
        const search = findTableSearchInput(document);
        if (search && search.value.trim() !== '') return true;

        const filterBadge = document.querySelector('.fi-ta-filters-trigger-action-ctn .fi-badge');
        if (filterBadge && parseInt(filterBadge.textContent, 10) > 0) return true;

        const group = document.querySelector('.fi-ta-grouping-settings-fields select');
        if (group && group.value) return true;

        const tabs = tabItems();
        const activeTab = tabs.findIndex(t => t.classList.contains('fi-active')
            || (t.hasAttribute('aria-selected') && t.getAttribute('aria-selected') !== 'false'));
        if (activeTab > 0) return true; // any tab beyond the default counts as a filter

        return false;
    }

    // The page's active tab bar: the one containing focus, else the first
    // visible one. Returns its tab buttons in visual order.
    function tabItems() {
        const focusedBar = document.activeElement?.closest?.('.fi-tabs');
        const bar = focusedBar || Array.from(document.querySelectorAll('.fi-tabs')).find(isVisible);
        return bar ? Array.from(bar.querySelectorAll('.fi-tabs-item')).filter(isVisible) : [];
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
        // Candidate order doubles as a fallback chain: crud.attach presses the
        // Attach button when the active table has one, else the Associate button
        // — a BelongsToMany manager has one pair, a HasMany manager the other,
        // never both on the same table.
        const map = {
            'crud.create':        ['create'],
            'crud.edit':          ['edit'],
            'crud.delete':        ['delete'],
            'crud.force-delete':  ['forceDelete'],
            'crud.restore':       ['restore'],
            'crud.save':          ['save'],
            'crud.create-another': ['createAnother', 'create_another'],
            'crud.view':          ['view'],
            'crud.duplicate':     ['replicate', 'duplicate'],
            'crud.attach':        ['attach', 'associate'],
            'crud.detach':        ['detach', 'dissociate'],
            'crud.cancel':        ['cancel'],
            'list.filter':        ['openFiltersModal', 'filter'],
            'list.search':        ['search'],
            'list.refresh':       ['refresh'],
            'list.export':        ['export'],
            'list.import':        ['import'],
            'list.select-all':    ['selectAll'],
            'list.bulk-action':   ['openBulkActionsModal'],
            'record.print':       ['print'],
            'record.history':     ['history', 'activityLog', 'timeline', 'activities'],
            'record.comment':     ['comment'],
            'record.archive':     ['archive'],
            'record.approve':     ['approve'],
            'record.reject':      ['reject'],
            'record.merge':       ['merge'],
            'record.split':       ['split'],
            'record.share':       ['share'],
            'record.favorite':    ['favorite', 'bookmark', 'star'],
            'record.watch':       ['watch', 'subscribe', 'follow'],
            'record.lock':        ['lock', 'unlock'],
        };
        return map[actionId] ?? [];
    }

    // Filament's dropdown trigger toggles on x-on:mousedown, not click — a
    // programmatic .click() leaves it shut. Send a real left-button mousedown
    // (bubbling up to the trigger wrapper), then a click for plain buttons.
    function openDropdown(el) {
        el.dispatchEvent(new MouseEvent('mousedown', { button: 0, bubbles: true, cancelable: true }));
        el.click();
    }

    // While an action menu is open (row actions, bulk actions, user menu — any
    // visible .fi-dropdown-panel containing a .fi-dropdown-list), ↑/↓ and j/k
    // walk its items with wrap-around, Home/End jump to the edges, and Enter
    // activates the focused item natively. Panels without a list (filters,
    // column manager) keep their native Tab/caret behavior, and j/k stay
    // typable inside a panel's text inputs.
    function dropdownMenuNav(e) {
        if (e.altKey || e.ctrlKey || e.metaKey) return false;
        const dir = (e.key === 'ArrowDown' || e.key === 'j') ? 1
            : (e.key === 'ArrowUp' || e.key === 'k') ? -1 : 0;
        if (!dir && e.key !== 'Home' && e.key !== 'End') return false;

        const panel = Array.from(document.querySelectorAll('.fi-dropdown-panel'))
            .find(p => isVisible(p) && p.querySelector('.fi-dropdown-list'));
        if (!panel) return false;
        if ((e.key === 'j' || e.key === 'k') && isTextInputFocus(e.target)) return false;

        const items = Array.from(panel.querySelectorAll('.fi-dropdown-list-item'))
            .filter(el => isVisible(el) && !el.matches('.fi-disabled, [disabled], [aria-disabled="true"]'));
        if (!items.length) return false;

        const current = items.indexOf(document.activeElement?.closest?.('.fi-dropdown-list-item'));
        const next = e.key === 'Home' ? 0
            : e.key === 'End' ? items.length - 1
            : current === -1 ? (dir === 1 ? 0 : items.length - 1)
            : (current + dir + items.length) % items.length;

        e.preventDefault();
        e.stopPropagation();
        const item = items[next];
        if (!item.matches('a[href], button, [tabindex]')) item.tabIndex = -1;
        item.focus();
        console.log(TAG, 'dropdown menu nav ->', item.textContent.trim());
        return true;
    }

    // Once a trigger has opened its panel, move focus inside so Tab walks the
    // controls (column checkboxes, filter fields) without touching the mouse.
    // The panel renders/positions asynchronously — retry briefly until visible.
    function focusOpenedPanel(trigger, attempt = 0) {
        const local = trigger.closest('.fi-dropdown')?.querySelector('.fi-dropdown-panel');
        const panel = (local && isVisible(local) ? local : null)
            ?? Array.from(document.querySelectorAll('.fi-dropdown-panel, .fi-ta-filters')).find(isVisible);
        // Prefer the panel's content (the first checkbox / field) over chrome
        // like the "Reset" button, so Tab starts walking the actual options.
        const target = panel?.querySelector('input:not([type="hidden"]):not([disabled]), select:not([disabled])')
            ?? panel?.querySelector('button:not([disabled]), [tabindex]:not([tabindex="-1"])');
        if (target) {
            console.log(TAG, 'focusing opened panel control', target);
            target.focus();
            return;
        }
        if (attempt < 10) setTimeout(() => focusOpenedPanel(trigger, attempt + 1), 30);
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
        const parts = key.split('+')
            .map(s => s.trim().toLowerCase())
            // 'mod' is the platform-neutral primary modifier: ⌘ on macOS, Ctrl elsewhere.
            .map(p => p === 'mod' ? (IS_MAC_PLATFORM ? 'cmd' : 'ctrl') : p);
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

    // ---- usage statistics (plugin ->statistics(), per-user daily rows) ----
    // Counts two things per action: keyboard invocations (each one = a click
    // avoided) and trusted mouse clicks on targets that HAVE a shortcut (the
    // other side of the ratio, feeds the "untapped actions" list). Counts are
    // buffered and flushed in batches to the StatisticsFlush Livewire
    // component; unsent counts survive tab closes via localStorage.
    function setupStats() {
        if (!cfg.stats) return;
        const flushMs = cfg.stats.flushMs || 10000;
        const BUFFER_KEY = 'mouseless_stats_buffer';
        let buffer = {};
        let flushTimer = null;

        // Counts a closed tab never flushed: fold them into this session.
        try {
            const stale = JSON.parse(localStorage.getItem(BUFFER_KEY) || 'null');
            localStorage.removeItem(BUFFER_KEY);
            if (stale && typeof stale === 'object') buffer = stale;
        } catch {}

        const merge = (events) => {
            for (const [id, counts] of Object.entries(events)) {
                const entry = buffer[id] ??= { kb: 0, click: 0 };
                entry.kb += counts.kb || 0;
                entry.click += counts.click || 0;
            }
        };

        statsRecord = (actionId, kind) => {
            const entry = buffer[actionId] ??= { kb: 0, click: 0 };
            entry[kind]++;
            if (!flushTimer) flushTimer = setTimeout(flush, flushMs);
        };

        function flush() {
            flushTimer = null;
            const events = buffer;
            if (!Object.keys(events).length) return;
            buffer = {};
            try {
                window.Livewire.dispatch('mouseless-stats-flush', { events });
            } catch {
                merge(events); // Livewire not ready — retry with the next batch
            }
        }

        // Leaving the page for real (close, hard navigation): an XHR flush
        // can't be trusted to finish — park unsent counts in localStorage,
        // the next page load sends them. SPA morphs keep this context alive,
        // so the timer keeps working there.
        window.addEventListener('pagehide', () => {
            if (flushTimer) { clearTimeout(flushTimer); flushTimer = null; }
            if (!Object.keys(buffer).length) return;
            try {
                localStorage.setItem(BUFFER_KEY, JSON.stringify(buffer));
                buffer = {};
            } catch {}
        });

        // Same guards as the teach layer: only trusted pointer clicks count,
        // never our own synthetic clicks or keyboard-activated buttons, and
        // never clicks on mouseless's own chrome.
        document.addEventListener('click', (e) => {
            if (!e.isTrusted || e.detail === 0) return;
            if (e.target.closest?.('.fi-no, [data-mouseless-overlay], [data-mouseless-goto], [data-mouseless-teach]')) return;
            const actionId = statsClickAction(e);
            if (actionId) statsRecord(actionId, 'click');
        }, true);

        // Flush the buffered pre-navigation counts shortly after each SPA
        // morph settles — keeps "invoked" fresh when the user lands on
        // /my-shortcuts right after using shortcuts elsewhere.
        document.addEventListener('livewire:navigated', () => {
            if (flushTimer) clearTimeout(flushTimer);
            flushTimer = setTimeout(flush, 1000);
        });

        console.log(TAG, 'stats: armed, flushing every', flushMs, 'ms');
    }

    // What shortcut a trusted mouse click bypassed. The stats counterpart of
    // the teach layer's hit test — same rules, but it only needs the action
    // id (no notification text), and it must work with teach disabled.
    function statsClickAction(e) {
        const el = e.target;
        const clickRow = el.closest?.('.fi-ta-row, .fi-ta-record') ?? null;
        const bound = (id) => Object.values(keyToAction).includes(id);

        // Action buttons first — a button inside a row wins over the row rule.
        for (const actionId of Object.values(keyToAction)) {
            let target = null;
            try { target = resolveActionTarget(actionId, clickRow); } catch { continue; }
            if (target && (target === el || target.contains(el))) return actionId;
        }

        // Row checkbox → Space toggles the row.
        if (clickRow && el.closest?.('.fi-ta-record-checkbox, input[type="checkbox"]')) {
            return bound('list.toggle-row') ? 'list.toggle-row' : null;
        }

        // Row click opens the record → j/k + Enter.
        if (clickRow && el.closest?.('a, button')) {
            return bound('list.next-row') ? 'list.next-row' : null;
        }

        // Tab click → ⌥1–⌥9 (engine-level; only the first nine have a key).
        if (el.closest?.('.fi-tabs-item')) {
            const index = tabItems().indexOf(el.closest('.fi-tabs-item'));
            return index >= 0 && index < 9 ? 'ui.tab-jump' : null;
        }

        // Sidebar navigation → the "go to" palette.
        if (el.closest?.('.fi-sidebar-nav a, .fi-sidebar a.fi-sidebar-item-btn')) {
            return bound('nav.goto') ? 'nav.goto' : null;
        }

        // Breadcrumb back to the list from a record page → Escape.
        if (el.closest?.('.fi-breadcrumbs a')
            && /^(\/[^\/]+\/[^\/]+)\/(create|\d+(\/(edit|view))?)$/.test(window.location.pathname)) {
            return bound('ui.close') ? 'ui.close' : null;
        }

        return null;
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
