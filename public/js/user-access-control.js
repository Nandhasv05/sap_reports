/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 04/11/2026
 * DESCRIPTION : SAP Reports User Access Control — Access Studio interactions
 */
(function () {
    'use strict';

    const cfgEl = document.getElementById('uacConfig');
    let cfg = { pages: [], users: [], getUrl: '', saveUrl: '', pageCount: 12, adminOnlyUsers: [] };
    try {
        cfg = JSON.parse(cfgEl?.textContent || '{}');
    } catch (e) {
        cfg = { pages: [], users: [], getUrl: '', saveUrl: '', pageCount: 12, adminOnlyUsers: [] };
    }

    const PAGES = Array.isArray(cfg.pages) ? cfg.pages : [];
    const ALL_USERS = Array.isArray(cfg.users) ? cfg.users : [];
    const GET_API_URL = String(cfg.getUrl || '');
    const SAVE_API_URL = String(cfg.saveUrl || '');
    const ADMIN_ONLY_USERS = (Array.isArray(cfg.adminOnlyUsers) ? cfg.adminOnlyUsers : ['admin', 'nandhakumar', 'saravanan', 'saravan'])
        .map((u) => String(u).toLowerCase());

    const userSelect = document.getElementById('uacUserSelect');
    const searchInput = document.getElementById('uacSearchInput');
    const btnSelectAll = document.getElementById('btnSelectAll');
    const btnClearAll = document.getElementById('btnClearAll');
    const btnSave = document.getElementById('btnSavePermissions');
    const moduleList = document.getElementById('uacModuleList');
    const userNameEl = document.getElementById('uacUserName');
    const userEmailEl = document.getElementById('uacUserEmail');
    const userRoleEl = document.getElementById('uacUserRole');
    const userDeptEl = document.getElementById('uacUserDept');
    const userAvatarEl = document.getElementById('uacUserAvatar');
    const grantedCountEl = document.getElementById('uacGrantedCount');
    const grantedFillEl = document.getElementById('uacGrantedFill');
    const pageTotalEl = document.getElementById('uacPageTotal');
    const toast = document.getElementById('uacToast');
    const toastMsg = document.getElementById('uacToastMsg');
    const toastIcon = document.getElementById('uacToastIcon');

    let toastTimer = null;
    let dirty = false;
    let loadToken = 0;

    function showToast(message, type) {
        if (!toast || !toastMsg) return;
        clearTimeout(toastTimer);
        toastMsg.textContent = message;
        toast.className = 'uac-toast show is-' + (type || 'success');
        if (toastIcon) {
            toastIcon.className = type === 'error'
                ? 'fas fa-circle-exclamation'
                : (type === 'info' ? 'fas fa-info-circle' : 'fas fa-circle-check');
        }
        toastTimer = setTimeout(() => toast.classList.remove('show'), 3600);
    }

    function isAdminOnlyTarget(username) {
        return ADMIN_ONLY_USERS.includes(String(username || '').toLowerCase().trim());
    }

    function visibleMods() {
        return Array.from(document.querySelectorAll('.uac-mod')).filter((mod) => mod.style.display !== 'none');
    }

    function visibleBoxes() {
        return visibleMods().flatMap((mod) => Array.from(mod.querySelectorAll('.uac-checkbox')));
    }

    function syncAdminOnlyModules() {
        const user = selectedUser();
        const allow = user ? isAdminOnlyTarget(user.username) : false;
        document.querySelectorAll('.uac-mod[data-admin-only="1"]').forEach((mod) => {
            mod.style.display = allow ? '' : 'none';
            if (!allow) {
                mod.querySelectorAll('.uac-checkbox').forEach((cb) => { cb.checked = false; });
            }
        });
        if (pageTotalEl) pageTotalEl.textContent = String(visibleMods().length);
        syncModuleStates();
    }

    function grantedModuleCount() {
        return visibleMods().filter(
            (mod) => mod.querySelectorAll('.uac-checkbox:checked').length > 0
        ).length;
    }

    function syncModuleStates() {
        visibleMods().forEach((mod) => {
            const on = mod.querySelectorAll('.uac-checkbox:checked').length > 0;
            mod.classList.toggle('is-granted', on);
        });
        const granted = grantedModuleCount();
        const total = visibleMods().length || 1;
        if (grantedCountEl) grantedCountEl.textContent = String(granted);
        if (grantedFillEl) {
            const pct = Math.min(100, Math.round((granted / total) * 100));
            grantedFillEl.style.width = pct + '%';
        }
    }

    function markDirty() {
        dirty = true;
        if (btnSave) btnSave.classList.add('is-dirty');
        syncModuleStates();
    }

    function clearDirty() {
        dirty = false;
        if (btnSave) btnSave.classList.remove('is-dirty');
        syncModuleStates();
    }

    function selectedUser() {
        const opt = userSelect?.options[userSelect.selectedIndex];
        if (!opt || !opt.value) return null;
        return {
            id: Number(opt.value) || 0,
            username: opt.getAttribute('data-username') || '',
            email: opt.getAttribute('data-email') || '',
            role: opt.getAttribute('data-role') || 'User',
            dept: opt.getAttribute('data-dept') || '',
        };
    }

    function updateUserMeta() {
        const user = selectedUser();
        if (!user) {
            if (userNameEl) userNameEl.textContent = 'No user selected';
            if (userEmailEl) userEmailEl.textContent = '—';
            if (userRoleEl) userRoleEl.textContent = '—';
            if (userDeptEl) userDeptEl.textContent = '—';
            if (userAvatarEl) userAvatarEl.textContent = '?';
            syncAdminOnlyModules();
            return;
        }
        if (userNameEl) userNameEl.textContent = user.username || '—';
        if (userEmailEl) userEmailEl.textContent = user.email || 'No Email';
        if (userRoleEl) userRoleEl.textContent = user.role || 'User';
        if (userDeptEl) userDeptEl.textContent = user.dept || '—';
        if (userAvatarEl) userAvatarEl.textContent = String(user.username || 'U').charAt(0).toUpperCase();
        syncAdminOnlyModules();
    }

    function setAllChecks(on) {
        visibleBoxes().forEach((cb) => {
            cb.checked = !!on;
        });
        markDirty();
    }

    function applyPermissions(perms) {
        visibleBoxes().forEach((cb) => { cb.checked = false; });
        document.querySelectorAll('.uac-mod[data-admin-only="1"] .uac-checkbox').forEach((cb) => {
            cb.checked = false;
        });
        Object.keys(perms || {}).forEach((pageNo) => {
            const row = perms[pageNo] || {};
            document.querySelectorAll('.uac-checkbox[data-page="' + pageNo + '"]').forEach((cb) => {
                const mod = cb.closest('.uac-mod');
                if (mod && mod.style.display === 'none') {
                    cb.checked = false;
                    return;
                }
                const action = cb.getAttribute('data-action') || '';
                const column = cb.getAttribute('data-column') || '';
                const colKey = column.replace(/^can_/, '');
                const on = !!Number(row[action])
                    || !!Number(row[column])
                    || !!Number(row[colKey])
                    || (action === 'pass_po' && !!Number(row.approve))
                    || (action === 'esign' && (!!Number(row.esign) || !!Number(row.copy)))
                    || (action === 'workbench' && (!!Number(row.workbench) || !!Number(row.add)))
                    || (action === 'find_po' && (!!Number(row.find_po) || !!Number(row.can_find_po)))
                    || (action === 'search_po' && (!!Number(row.search_po) || !!Number(row.can_search_po)))
                    || (action === 'related' && (!!Number(row.related) || !!Number(row.can_related)))
                    || (action === 'update' && !!Number(row.edit))
                    || (action === 'assign' && !!Number(row.assign))
                    || (action === 'validate' && !!Number(row.validate));
                cb.checked = on;
            });
        });
        clearDirty();
    }

    async function parseJson(res) {
        const text = await res.text();
        try {
            return JSON.parse(text);
        } catch (e) {
            throw new Error(res.ok ? 'Invalid server response' : ('HTTP ' + res.status));
        }
    }

    async function loadUserPermissions(userId) {
        if (!userId || !GET_API_URL) return;
        const token = ++loadToken;
        syncAdminOnlyModules();
        try {
            const res = await fetch(GET_API_URL + '?user_id=' + encodeURIComponent(userId), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            const data = await parseJson(res);
            if (token !== loadToken) return;
            if (data.success) {
                applyPermissions(data.permissions || {});
            } else {
                applyPermissions({});
                showToast(data.error || 'Failed to load permissions', 'error');
            }
        } catch (err) {
            if (token !== loadToken) return;
            console.error(err);
            showToast('Failed to load permissions from server', 'error');
        }
    }

    function renderUserOptions(filter, preferredId) {
        if (!userSelect) return;
        const q = String(filter || '').trim().toLowerCase();
        const keepId = preferredId != null ? String(preferredId) : String(userSelect.value || '');
        const matched = ALL_USERS.filter((u) => {
            const name = String(u.UserName || u.username || '').toLowerCase();
            const mail = String(u.Email || u.email || '').toLowerCase();
            const role = String(u.Role || u.role || '').toLowerCase();
            return !q || name.includes(q) || mail.includes(q) || role.includes(q);
        });
        userSelect.innerHTML = '';
        if (!matched.length) {
            const empty = document.createElement('option');
            empty.value = '';
            empty.textContent = q ? 'No matching users' : 'No active users found';
            userSelect.appendChild(empty);
            updateUserMeta();
            return;
        }
        matched.forEach((u) => {
            const opt = document.createElement('option');
            const id = String(u.Id ?? u.id ?? '');
            opt.value = id;
            opt.setAttribute('data-username', String(u.UserName || u.username || ''));
            opt.setAttribute('data-email', String(u.Email || u.email || ''));
            opt.setAttribute('data-role', String(u.Role || u.role || 'User'));
            opt.setAttribute('data-dept', String(u.Department || u.department || ''));
            opt.textContent = (u.UserName || u.username || 'User')
                + ' (' + (u.Role || u.role || 'User') + ') — '
                + (u.Email || u.email || 'No Email');
            userSelect.appendChild(opt);
        });
        const hasKeep = Array.from(userSelect.options).some((o) => o.value === keepId);
        userSelect.value = hasKeep ? keepId : userSelect.options[0].value;
        updateUserMeta();
    }

    let searchTimer = null;
    function onSearch() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            const prev = userSelect?.value;
            renderUserOptions(searchInput?.value || '', prev);
            if (userSelect && userSelect.value && userSelect.value !== prev) {
                void loadUserPermissions(userSelect.value);
            }
        }, 160);
    }

    async function savePermissions() {
        const user = selectedUser();
        if (!user || !user.id) {
            showToast('Please select a target user first', 'error');
            return;
        }
        const allowAdminOnly = isAdminOnlyTarget(user.username);
        const permissions = PAGES.map((p) => {
            const pageNo = p.page_no;
            // Only send page_no + flags — server owns names/routes (avoids ODBC truncation)
            const payload = {
                page_no: pageNo,
                can_add: 0,
                can_view: 0,
                can_edit: 0,
                can_approve: 0,
                can_copy: 0,
                can_assign: 0,
                can_validate: 0,
                can_receive: 0,
                can_sent: 0,
                can_workflow: 0,
                can_approved: 0,
                can_related: 0,
                can_find_po: 0,
                can_search_po: 0,
            };
            // Ensure every catalog action column exists on the payload (Related / Find PO / etc.)
            (Array.isArray(p.actions) ? p.actions : []).forEach((act) => {
                const col = String(act.column || '');
                if (col && !Object.prototype.hasOwnProperty.call(payload, col)) {
                    payload[col] = 0;
                }
            });
            if (p.admin_only && !allowAdminOnly) {
                return payload;
            }
            document.querySelectorAll('.uac-checkbox[data-page="' + pageNo + '"]').forEach((cb) => {
                if (!cb.checked) return;
                const column = cb.getAttribute('data-column') || '';
                const action = cb.getAttribute('data-action') || '';
                if (column) {
                    payload[column] = 1;
                }
                if (action) {
                    payload[action] = 1;
                    // Alias: action key → can_<action> so server always sees the column bit
                    const canKey = action.indexOf('can_') === 0 ? action : ('can_' + action);
                    payload[canKey] = 1;
                }
            });
            return payload;
        });

        btnSave.disabled = true;
        const originalHtml = btnSave.innerHTML;
        btnSave.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Saving…</span>';

        try {
            const res = await fetch(SAVE_API_URL, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    user_id: user.id,
                    username: user.username,
                    permissions: permissions,
                }),
            });
            const data = await parseJson(res);
            if (data.success) {
                clearDirty();
                showToast(data.message || ('Permissions saved for ' + user.username), 'success');
                await loadUserPermissions(user.id);
            } else {
                const err = String(data.error || 'Failed to save permissions');
                // Prefer a short user-facing line; full detail stays in console
                console.error('UAC save failed', data);
                showToast(err.length > 160 ? (err.slice(0, 160) + '…') : err, 'error');
            }
        } catch (err) {
            console.error(err);
            showToast(err.message || 'Network error while saving permissions', 'error');
        } finally {
            btnSave.disabled = false;
            btnSave.innerHTML = originalHtml;
        }
    }

    if (userSelect) {
        userSelect.addEventListener('change', () => {
            updateUserMeta();
            void loadUserPermissions(userSelect.value);
        });
    }

    moduleList?.addEventListener('change', (e) => {
        if (e.target.classList.contains('uac-checkbox')) {
            markDirty();
        }
    });

    btnSelectAll?.addEventListener('click', () => {
        setAllChecks(true);
        showToast('All available module rights selected', 'info');
    });
    btnClearAll?.addEventListener('click', () => {
        setAllChecks(false);
        showToast('All permissions cleared', 'info');
    });
    searchInput?.addEventListener('input', onSearch);
    btnSave?.addEventListener('click', () => { void savePermissions(); });
    window.addEventListener('beforeunload', (e) => {
        if (!dirty) return;
        e.preventDefault();
        e.returnValue = '';
    });

    renderUserOptions('', ALL_USERS[0] ? String(ALL_USERS[0].Id ?? ALL_USERS[0].id ?? '') : '');
    updateUserMeta();
    if (userSelect?.value) {
        void loadUserPermissions(userSelect.value);
    }
})();
