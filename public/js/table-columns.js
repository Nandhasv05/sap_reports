/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 28/09/2026
 * DESCRIPTION : Per-user table column preferences (order + visibility) shared by all report tables.
 *               The server (/api/user/table-preferences/{tableKey}) is the source of truth; localStorage is only a per-user cache.
 */
(function () {
    const SAVE_DELAY_MS = 600;
    const SAVED_FLASH_MS = 1800;
    const CACHE_PREFIX = 'rpt_colprefs_v1:';

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function readConfig() {
        const node = document.getElementById('rptTablePrefs');
        if (!node) return null;
        try { return JSON.parse(node.textContent || 'null'); } catch (e) { return null; }
    }

    // Column settings used to be stored per browser (shared by every user); drop them so they never leak across accounts.
    function clearLegacyStorage() {
        try {
            Object.keys(localStorage)
                .filter(k => k.startsWith('rpt_hidden_cols_') || k.startsWith('rpt_col_order_'))
                .forEach(k => localStorage.removeItem(k));
        } catch (e) {}
    }

    function requestError(status, json) {
        return Object.assign(new Error(errorMessage(status, json)), { status });
    }

    function errorMessage(status, json) {
        if (status === 0) return 'Network error — column settings not saved.';
        if (status === 401) return 'Your session has expired — log in again to save column settings.';
        if (status === 403) return 'You are not allowed to change column settings.';
        if (status === 404) return 'Column settings are not available for this table.';
        if (status === 422) return (json && json.message) || 'The column configuration was rejected.';
        return 'Server error — column settings not saved.';
    }

    /*
     * Preference store for one table (the equivalent of a useTablePreferences hook).
     * onState receives { columnOrder, hiddenColumns, loading, saving, saved, error: { message, retry } | null }.
     * Returns { setPreferences, reset }.
     */
    function createTablePreferences(config, onState) {
        const ids = config.columns.map(c => c.id);
        const locked = config.columns.filter(c => c.locked).map(c => c.id);
        const cacheKey = config.userId ? CACHE_PREFIX + config.userId + ':' + config.tableKey : '';

        function normalize(prefs) {
            const known = new Set(ids);
            const saved = (prefs && Array.isArray(prefs.columnOrder) ? prefs.columnOrder : [])
                .filter(id => known.has(id) && !locked.includes(id));
            const hidden = new Set((prefs && Array.isArray(prefs.hiddenColumns) ? prefs.hiddenColumns : [])
                .filter(id => known.has(id) && !locked.includes(id)));
            // Columns added since the save go right after the column that precedes them in the default order.
            const order = Array.from(new Set([...locked, ...saved]));
            ids.forEach((id, i) => {
                if (order.includes(id)) return;
                const prev = ids.slice(0, i).reverse().find(p => order.includes(p));
                order.splice(prev ? order.indexOf(prev) + 1 : order.length, 0, id);
            });
            return {
                columnOrder: order,
                hiddenColumns: ids.filter(id => hidden.has(id)),
            };
        }
        const defaults = () => normalize({ columnOrder: ids, hiddenColumns: config.defaultHidden || [] });
        const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

        function readCache() {
            if (!cacheKey) return null;
            try { return JSON.parse(localStorage.getItem(cacheKey) || 'null'); } catch (e) { return null; }
        }
        function writeCache(prefs) {
            if (!cacheKey) return;
            try { localStorage.setItem(cacheKey, JSON.stringify(prefs)); } catch (e) {}
        }
        function clearCache() {
            if (!cacheKey) return;
            try { localStorage.removeItem(cacheKey); } catch (e) {}
        }

        let persisted = defaults();
        let current = persisted;
        let touched = false;
        let pendingOps = 0;
        let saveTimer = null;
        let savedTimer = null;
        let queue = Promise.resolve();
        const status = { loading: true, saved: false, error: null };

        function emit() {
            onState({ ...current, ...status, saving: pendingOps > 0 || saveTimer !== null });
        }

        async function request(method, body) {
            let res;
            try {
                res = await fetch(config.apiUrl, {
                    method,
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: Object.assign(
                        { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body ? { 'Content-Type': 'application/json' } : {}
                    ),
                    body: body ? JSON.stringify(body) : undefined,
                });
            } catch (e) {
                throw requestError(0);
            }
            let json = null;
            try { json = await res.json(); } catch (e) {}
            if (!json || json.success !== true) {
                // An HTML login page instead of JSON means the portal session is gone.
                throw requestError(res.ok ? 401 : res.status, json);
            }
            return json;
        }

        // Mutations run one at a time so responses can never be applied out of order.
        function enqueue(op) {
            pendingOps++;
            emit();
            queue = queue.then(op).finally(() => { pendingOps--; emit(); });
            return queue;
        }

        function flashSaved() {
            status.saved = true;
            clearTimeout(savedTimer);
            savedTimer = setTimeout(() => { status.saved = false; emit(); }, SAVED_FLASH_MS);
        }

        function fail(err, retry) {
            status.saved = false;
            const retryable = err.status === 0 || err.status >= 500;
            if (!retryable) current = persisted;
            status.error = { message: retryable ? err.message : err.message + ' Restored your last saved layout.', retry: retryable ? retry : null };
        }

        function save() {
            return enqueue(async () => {
                if (same(current, persisted)) return;
                const body = { columnOrder: current.columnOrder, hiddenColumns: current.hiddenColumns };
                try {
                    persisted = normalize(await request('PUT', body));
                    writeCache(persisted);
                    status.error = null;
                    if (same(body, { columnOrder: current.columnOrder, hiddenColumns: current.hiddenColumns })) current = persisted;
                    flashSaved();
                } catch (err) {
                    fail(err, save);
                }
            });
        }

        function scheduleSave() {
            clearTimeout(saveTimer);
            saveTimer = setTimeout(() => { saveTimer = null; save(); }, SAVE_DELAY_MS);
        }

        function load() {
            status.loading = true;
            status.error = null;
            emit();
            request('GET')
                .then((json) => {
                    persisted = normalize(json);
                    writeCache(persisted);
                    if (!touched) current = persisted;
                })
                .catch((err) => {
                    persisted = defaults();
                    if (!touched) current = persisted;
                    status.error = { message: 'Saved columns unavailable — showing the default layout.', retry: err.status === 0 || err.status >= 500 ? load : null };
                })
                .finally(() => {
                    status.loading = false;
                    emit();
                    if (touched) scheduleSave();
                });
        }

        const cached = readCache();
        if (cached) current = normalize(cached);
        load();

        return {
            setPreferences(next) {
                const normalized = normalize(next);
                if (same(normalized, current)) return;
                touched = true;
                current = normalized;
                status.error = null;
                status.saved = false;
                if (!status.loading) scheduleSave();
                emit();
            },
            reset() {
                clearTimeout(saveTimer);
                saveTimer = null;
                touched = true;
                current = defaults();
                status.error = null;
                const doReset = () => enqueue(async () => {
                    try {
                        persisted = normalize(await request('DELETE'));
                        clearCache();
                        current = persisted;
                        status.error = null;
                        flashSaved();
                    } catch (err) {
                        fail(err, doReset);
                    }
                });
                doReset();
            },
        };
    }

    window.rptCreateTablePreferences = createTablePreferences;

    /*
     * Wires the preference store to a report table: header drag-and-drop, the "Manage columns" menu and the save status.
     * options: onChange (after columns move / hide). Returns { refresh } - call refresh() after adding body rows.
     */
    window.rptSetupColumnFeatures = function (table, options) {
        const opts = options || {};
        const headerRow = table.querySelector('thead tr.rpt-header-row');
        const config = readConfig();
        const menu = document.getElementById('rptColumnToggleMenu');
        const dropdown = document.getElementById('rptColumnToggleDropdown');
        const toggleBtn = document.getElementById('rptColumnToggleBtn');
        if (!headerRow || !config || !Array.isArray(config.columns)) return { refresh() {} };

        const count = headerRow.children.length;
        if (config.columns.length !== count) {
            console.warn(`Column preferences disabled for ${config.tableKey}: the table has ${count} columns, the registry ${config.columns.length}.`);
            return { refresh() {} };
        }
        clearLegacyStorage();

        const columns = config.columns;
        const labels = Object.fromEntries(columns.map(c => [c.id, c.label]));
        const isLocked = (id) => columns.some(c => c.id === id && c.locked);
        let state = null;
        let appliedOrder = columns.map(c => c.id);
        let appliedHidden = '';
        let dragFrom = null;
        let refocus = null;

        function tableRows() {
            return Array.from(table.querySelectorAll('thead tr, tbody tr, tfoot tr')).filter(tr => tr.children.length === count);
        }
        // Rows are built in the default column order, so an untagged cell's position identifies its column.
        function tagCells() {
            tableRows().forEach(tr => Array.from(tr.children).forEach((cell, i) => {
                if (!cell.hasAttribute('data-col-id')) cell.setAttribute('data-col-id', columns[i].id);
            }));
        }
        function applyOrder(order) {
            tableRows().forEach(tr => {
                const byId = {};
                Array.from(tr.children).forEach(cell => { byId[cell.getAttribute('data-col-id')] = cell; });
                const frag = document.createDocumentFragment();
                order.forEach(id => { if (byId[id]) frag.appendChild(byId[id]); });
                tr.appendChild(frag);
            });
        }

        const style = document.createElement('style');
        document.head.appendChild(style);
        function applyHidden(hidden) {
            style.textContent = hidden.map(id => `#${table.id} [data-col-id="${id}"]{display:none !important;}`).join('\n');
            toggleBtn?.classList.toggle('is-active', hidden.length > 0);
        }

        const statusEl = document.createElement('span');
        statusEl.className = 'rpt-col-save';
        statusEl.setAttribute('role', 'status');
        statusEl.setAttribute('aria-live', 'polite');
        statusEl.hidden = true;
        dropdown?.after(statusEl);
        statusEl.addEventListener('click', (e) => {
            if (e.target.closest('[data-col-retry]')) state?.error?.retry?.();
        });

        function renderStatus() {
            let html = '';
            let kind = '';
            if (state.error) {
                kind = 'error';
                html = `<span class="material-icons-round">error_outline</span><span>${escapeHtml(state.error.message)}</span>`
                    + (state.error.retry ? '<button type="button" data-col-retry>Retry</button>' : '');
            } else if (state.saving) {
                kind = 'saving';
                html = '<span class="rpt-col-save-spin" aria-hidden="true"></span><span>Saving columns…</span>';
            } else if (state.saved) {
                kind = 'saved';
                html = '<span class="material-icons-round">check_circle</span><span>Saved</span>';
            }
            statusEl.hidden = kind === '';
            statusEl.className = 'rpt-col-save' + (kind ? ' is-' + kind : '');
            statusEl.title = state.error ? state.error.message : '';
            if (statusEl.innerHTML !== html) statusEl.innerHTML = html;
        }

        function buildMenu() {
            if (!menu) return;
            const order = state.columnOrder;
            const hidden = new Set(state.hiddenColumns);
            const movable = order.filter(id => !isLocked(id));
            const items = order.map(id => {
                const label = escapeHtml(labels[id]);
                if (isLocked(id)) {
                    return `<li class="rpt-colmgr-item is-locked" data-col-item="${id}" title="Always shown first">
                        <span class="material-icons-round rpt-colmgr-grip">lock</span>
                        <span class="rpt-colmgr-label"><input type="checkbox" checked disabled><span>${label}</span></span>
                    </li>`;
                }
                const pos = movable.indexOf(id);
                return `<li class="rpt-colmgr-item${hidden.has(id) ? ' is-hidden' : ''}" data-col-item="${id}" draggable="true">
                    <span class="material-icons-round rpt-colmgr-grip" title="Drag to reorder">drag_indicator</span>
                    <label class="rpt-colmgr-label"><input type="checkbox" data-col-toggle="${id}"${hidden.has(id) ? '' : ' checked'}><span>${label}</span></label>
                    <span class="rpt-colmgr-move">
                        <button type="button" data-col-move="-1" aria-label="Move ${label} up"${pos === 0 ? ' disabled' : ''}><span class="material-icons-round">keyboard_arrow_up</span></button>
                        <button type="button" data-col-move="1" aria-label="Move ${label} down"${pos === movable.length - 1 ? ' disabled' : ''}><span class="material-icons-round">keyboard_arrow_down</span></button>
                    </span>
                </li>`;
            }).join('');
            menu.innerHTML = `
                <div class="rpt-colmgr-head">
                    <span class="rpt-cat-dd-header">Manage columns</span>
                    <span class="rpt-colmgr-count">${order.length - hidden.size} of ${order.length} shown</span>
                </div>
                <p class="rpt-colmgr-hint">Drag or use the arrows to reorder. Saved to your account automatically.</p>
                <ul class="rpt-colmgr-list">${items}</ul>
                <div class="rpt-cat-dd-divider"></div>
                <button type="button" class="rpt-cat-dd-item" data-col-reset>
                    <span class="material-icons-round">restart_alt</span>
                    <span class="rpt-cat-dd-item-text">Reset to default</span>
                </button>`;
            if (refocus) {
                menu.querySelector(`[data-col-item="${refocus.id}"] [data-col-move="${refocus.dir}"]:not(:disabled)`)?.focus();
                refocus = null;
            }
        }

        function render(next) {
            const prev = state;
            state = next;
            const orderKey = next.columnOrder.join(',');
            const hiddenKey = next.hiddenColumns.join(',');
            const layoutChanged = orderKey !== appliedOrder.join(',') || hiddenKey !== appliedHidden;
            if (orderKey !== appliedOrder.join(',')) {
                applyOrder(next.columnOrder);
                appliedOrder = next.columnOrder;
            }
            if (hiddenKey !== appliedHidden) {
                applyHidden(next.hiddenColumns);
                appliedHidden = hiddenKey;
            }
            if (!prev || layoutChanged) buildMenu();
            renderStatus();
            if (layoutChanged && typeof opts.onChange === 'function') opts.onChange();
        }

        tagCells();
        const prefs = createTablePreferences(config, render);

        // Move one column so it lands where `target` is; locked columns stay in front.
        function moveColumn(from, target) {
            if (from === target || isLocked(from)) return;
            const order = state.columnOrder.filter(id => id !== from);
            const fromPos = state.columnOrder.indexOf(from);
            const toPos = order.indexOf(target);
            const insertAt = isLocked(target) ? toPos + 1 : (fromPos <= toPos ? toPos + 1 : toPos);
            order.splice(insertAt, 0, from);
            prefs.setPreferences({ columnOrder: order, hiddenColumns: state.hiddenColumns });
        }

        function bindDrag(root, itemSelector, idOf) {
            const clearMarks = () => root.querySelectorAll('.is-drag-over, .is-dragging').forEach(el => el.classList.remove('is-drag-over', 'is-dragging'));
            root.addEventListener('dragstart', (e) => {
                const item = e.target.closest?.(itemSelector);
                const id = item ? idOf(item) : null;
                if (!id || isLocked(id)) return;
                dragFrom = id;
                item.classList.add('is-dragging');
                if (e.dataTransfer) {
                    e.dataTransfer.effectAllowed = 'move';
                    try { e.dataTransfer.setData('text/plain', id); } catch (err) {}
                }
            });
            root.addEventListener('dragover', (e) => {
                const item = e.target.closest?.(itemSelector);
                if (dragFrom === null || !item) return;
                e.preventDefault();
                if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';
                root.querySelectorAll('.is-drag-over').forEach(el => { if (el !== item) el.classList.remove('is-drag-over'); });
                if (idOf(item) !== dragFrom) item.classList.add('is-drag-over');
            });
            root.addEventListener('drop', (e) => {
                const item = e.target.closest?.(itemSelector);
                if (dragFrom === null || !item) return;
                e.preventDefault();
                const from = dragFrom;
                dragFrom = null;
                clearMarks();
                moveColumn(from, idOf(item));
            });
            root.addEventListener('dragend', () => { dragFrom = null; clearMarks(); });
        }

        Array.from(headerRow.children).forEach(th => {
            if (isLocked(th.getAttribute('data-col-id'))) return;
            th.setAttribute('draggable', 'true');
            th.title = (th.title ? th.title + ' — ' : '') + 'drag to move column';
        });
        bindDrag(headerRow, 'th', th => th.getAttribute('data-col-id'));

        if (menu) {
            bindDrag(menu, '[data-col-item]', li => li.getAttribute('data-col-item'));
            menu.addEventListener('change', (e) => {
                const cb = e.target.closest('input[data-col-toggle]');
                if (!cb) return;
                const id = cb.getAttribute('data-col-toggle');
                const hidden = state.hiddenColumns.filter(h => h !== id);
                if (!cb.checked) hidden.push(id);
                prefs.setPreferences({ columnOrder: state.columnOrder, hiddenColumns: hidden });
            });
            menu.addEventListener('click', (e) => {
                e.stopPropagation();
                if (e.target.closest('[data-col-reset]')) {
                    prefs.reset();
                    return;
                }
                const moveBtn = e.target.closest('[data-col-move]');
                if (!moveBtn) return;
                const id = moveBtn.closest('[data-col-item]').getAttribute('data-col-item');
                const dir = parseInt(moveBtn.getAttribute('data-col-move'), 10);
                const movable = state.columnOrder.filter(c => !isLocked(c));
                const neighbour = movable[movable.indexOf(id) + dir];
                if (!neighbour) return;
                refocus = { id, dir };
                moveColumn(id, neighbour);
            });
        }
        toggleBtn?.addEventListener('click', (e) => {
            e.stopPropagation();
            dropdown?.classList.toggle('is-open');
        });
        document.addEventListener('click', (e) => {
            if (dropdown && !dropdown.contains(e.target)) dropdown.classList.remove('is-open');
        });

        return {
            refresh() {
                tagCells();
                applyOrder(appliedOrder);
            },
        };
    };
})();
