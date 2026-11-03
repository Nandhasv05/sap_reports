<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/11/2026
 * DESCRIPTION : SAP Reports User Access Control View
 *               Granular RBAC Permissions (Add, View, Edit, Approve) per User across 12 SAP Report Pages
 */

$catalogHome = url('/');
$home = sap_reports_evol_url('portal_dashboard.php');
$logoUrl = sap_reports_logo_url();
$pageTitle = 'User Access Control';

$extraHead = '<link rel="stylesheet" href="' . e(asset('css/user_access_control.css')) . '?v=' . time() . '">';
?>

<div class="rpt-app is-uac-page">
    <!-- Ambient background glows -->
    <div class="hub-ambient-bg" aria-hidden="true">
        <span class="hub-glow-orb orb-1" style="background: radial-gradient(circle, rgba(225,29,72,0.15) 0%, rgba(225,29,72,0) 70%);"></span>
        <span class="hub-glow-orb orb-2" style="background: radial-gradient(circle, rgba(99,102,241,0.12) 0%, rgba(99,102,241,0) 70%);"></span>
    </div>

    <!-- Top Navigation Bar -->
    <header class="rpt-bar">
        <div class="rpt-bar-left">
            <a href="<?= e($catalogHome) ?>" class="rpt-brand" aria-label="EVOLV" title="SAP Reports Catalog">
                <img src="<?= e($logoUrl) ?>" alt="evolv" style="height:28px; max-height:28px; width:auto; max-width:140px; object-fit:contain;">
            </a>
            
            <a href="<?= e($catalogHome) ?>" class="rpt-home" aria-label="All Reports" title="Reports Catalog">
                <span class="material-icons-round">apps</span>
            </a>
            <a href="<?= e($home) ?>" class="rpt-home" aria-label="Portal home" title="Portal Dashboard">
                <span class="material-icons-round">home</span>
            </a>

            <div class="rpt-name">User Access Control</div>
        </div>

        <?php require base_path('app/views/partials/header_nav_right.php'); ?>
    </header>

    <!-- Main Container -->
    <main class="uac-page-wrap">
        <!-- Control Toolbar: User Selector, Search & Bulk Actions -->
        <section class="uac-toolbar-card">
            <div class="uac-toolbar-left">
                <!-- User Selector -->
                <div class="uac-field-group">
                    <label for="uacUserSelect" class="uac-field-label">
                        <i class="fas fa-user"></i> Target User
                    </label>
                    <div class="uac-user-select-wrap">
                        <select id="uacUserSelect" class="uac-user-select" aria-label="Target User">
                            <?php if (empty($users)): ?>
                                <option value="">No active users found</option>
                            <?php else: ?>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?= (int) $u['Id'] ?>" 
                                            data-username="<?= e($u['UserName'] ?? '') ?>"
                                            data-email="<?= e($u['Email'] ?? '') ?>"
                                            data-role="<?= e($u['Role'] ?? 'User') ?>"
                                            data-dept="<?= e($u['Department'] ?? 'General') ?>">
                                        <?= e($u['UserName']) ?> (<?= e($u['Role'] ?? 'User') ?>) — <?= e($u['Email'] ?: 'No Email') ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        <i class="fas fa-chevron-down uac-user-select-icon"></i>
                    </div>
                </div>

                <!-- Live Search: User Name or Email -->
                <div class="uac-field-group">
                    <label for="uacSearchInput" class="uac-field-label">
                        <i class="fas fa-magnifying-glass"></i> Search User Name / Email
                    </label>
                    <div class="uac-search-wrap">
                        <i class="fas fa-search uac-search-icon"></i>
                        <input type="text" id="uacSearchInput" class="uac-search-input" 
                               placeholder="Type user name or mail id to find..." 
                               autocomplete="off" aria-label="Search User Name or Email">
                    </div>
                </div>
            </div>

            <!-- Bulk & Save Actions -->
            <div class="uac-toolbar-right">
                <button type="button" class="uac-btn uac-btn-select-all" id="btnSelectAll" title="Select all Add, View, Edit, Approve permissions across all 12 pages">
                    <i class="fas fa-check-double"></i> Select All
                </button>
                <button type="button" class="uac-btn uac-btn-clear-all" id="btnClearAll" title="Clear all permissions">
                    <i class="fas fa-xmark"></i> Clear All
                </button>
                <button type="button" class="uac-btn uac-btn-save" id="btnSavePermissions" title="Save permissions to dbo.tblSapUseracessControl">
                    <i class="fas fa-floppy-disk"></i> Save Permissions
                </button>
            </div>
        </section>

        <!-- Active Selected User Details Banner -->
        <section class="uac-user-meta-banner" id="uacUserMetaBanner">
            <div class="uac-user-meta-left">
                <div class="uac-avatar-circle" id="uacUserAvatar">A</div>
                <div>
                    <div class="uac-user-name" id="uacUserName">—</div>
                    <div class="uac-user-email" id="uacUserEmail">—</div>
                </div>
                <div class="uac-user-badges">
                    <span class="uac-pill uac-pill-role" id="uacUserRole">—</span>
                    <span class="uac-pill uac-pill-dept" id="uacUserDept">—</span>
                </div>
            </div>
            <div class="uac-user-meta-right">
                <span class="uac-stats-pill" id="uacUserStatsPill">
                    <i class="fas fa-shield-halved" style="color:#e11d48; margin-right:4px;"></i>
                    <strong id="uacGrantedCount">0</strong> of 12 pages viewable
                </span>
            </div>
        </section>

        <!-- Permissions Matrix Table -->
        <div class="uac-table-card">
            <div class="uac-table-responsive">
                <table class="uac-table" id="uacTable">
                    <thead>
                        <tr>
                            <th style="width: 70px;" class="th-center">S.No</th>
                            <th>Page No &amp; Module Details</th>
                            <th class="th-action">
                                <label class="th-toggle-header" title="Toggle All Add Permissions">
                                    <input type="checkbox" id="masterAdd" aria-label="Toggle All Add">
                                    <span>ADD</span>
                                </label>
                            </th>
                            <th class="th-action">
                                <label class="th-toggle-header" title="Toggle All View Permissions">
                                    <input type="checkbox" id="masterView" aria-label="Toggle All View">
                                    <span>VIEW</span>
                                </label>
                            </th>
                            <th class="th-action">
                                <label class="th-toggle-header" title="Toggle All Edit Permissions">
                                    <input type="checkbox" id="masterEdit" aria-label="Toggle All Edit">
                                    <span>EDIT</span>
                                </label>
                            </th>
                            <th class="th-action">
                                <label class="th-toggle-header" title="Toggle All Approve Permissions">
                                    <input type="checkbox" id="masterApprove" aria-label="Toggle All Approve">
                                    <span>APPROVE</span>
                                </label>
                            </th>
                        </tr>
                    </thead>
                    <tbody id="uacTableBody">
                        <?php foreach ($pages as $idx => $p): ?>
                            <tr data-page-no="<?= (int) $p['page_no'] ?>" data-route="<?= e($p['route']) ?>">
                                <td class="td-center">
                                    <span class="uac-sno-badge"><?= $idx + 1 ?></span>
                                </td>
                                <td>
                                    <div class="uac-page-meta">
                                        <div class="uac-page-icon" style="background: <?= e($p['icon_bg']) ?>; color: <?= e($p['icon_color']) ?>;">
                                            <i class="fas <?= e($p['icon']) ?>"></i>
                                        </div>
                                        <div class="uac-page-info">
                                            <div class="uac-page-name-line">
                                                <span class="uac-page-no-pill">Page <?= $p['page_no'] ?></span>
                                                <span class="uac-page-title"><?= e($p['name']) ?></span>
                                                <span class="uac-page-route-pill">/<?= e($p['route']) ?></span>
                                            </div>
                                            <div class="uac-page-desc"><?= e($p['description']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="td-center">
                                    <label class="uac-checkbox-wrap" title="Grant Add permission for <?= e($p['name']) ?>">
                                        <input type="checkbox" class="uac-checkbox col-add" data-page="<?= (int) $p['page_no'] ?>" data-action="add">
                                    </label>
                                </td>
                                <td class="td-center">
                                    <label class="uac-checkbox-wrap" title="Grant View permission for <?= e($p['name']) ?>">
                                        <input type="checkbox" class="uac-checkbox col-view" data-page="<?= (int) $p['page_no'] ?>" data-action="view">
                                    </label>
                                </td>
                                <td class="td-center">
                                    <label class="uac-checkbox-wrap" title="Grant Edit permission for <?= e($p['name']) ?>">
                                        <input type="checkbox" class="uac-checkbox col-edit" data-page="<?= (int) $p['page_no'] ?>" data-action="edit">
                                    </label>
                                </td>
                                <td class="td-center">
                                    <label class="uac-checkbox-wrap" title="Grant Approve permission for <?= e($p['name']) ?>">
                                        <input type="checkbox" class="uac-checkbox col-approve" data-page="<?= (int) $p['page_no'] ?>" data-action="approve">
                                    </label>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Floating Toast Notification -->
    <div id="uacToast" class="uac-toast" role="alert" aria-live="assertive">
        <i class="fas fa-circle-check" id="uacToastIcon"></i>
        <span id="uacToastMsg">Permissions saved successfully</span>
    </div>
</div>

<script>
(function() {
    'use strict';

    const PAGES_CATALOG = <?= json_encode($pages) ?>;
    const GET_API_URL = "<?= url('api/user-access-control/get') ?>";
    const SAVE_API_URL = "<?= url('api/user-access-control/save') ?>";

    const userSelect = document.getElementById('uacUserSelect');
    const searchInput = document.getElementById('uacSearchInput');
    const btnSelectAll = document.getElementById('btnSelectAll');
    const btnClearAll = document.getElementById('btnClearAll');
    const btnSave = document.getElementById('btnSavePermissions');

    const masterAdd = document.getElementById('masterAdd');
    const masterView = document.getElementById('masterView');
    const masterEdit = document.getElementById('masterEdit');
    const masterApprove = document.getElementById('masterApprove');

    const userNameEl = document.getElementById('uacUserName');
    const userEmailEl = document.getElementById('uacUserEmail');
    const userRoleEl = document.getElementById('uacUserRole');
    const userDeptEl = document.getElementById('uacUserDept');
    const userAvatarEl = document.getElementById('uacUserAvatar');
    const grantedCountEl = document.getElementById('uacGrantedCount');
    const toast = document.getElementById('uacToast');
    const toastMsg = document.getElementById('uacToastMsg');
    const toastIcon = document.getElementById('uacToastIcon');

    let toastTimer = null;

    function showToast(message, type = 'success') {
        clearTimeout(toastTimer);
        toastMsg.textContent = message;
        toast.className = 'uac-toast show is-' + type;

        if (type === 'success') {
            toastIcon.className = 'fas fa-circle-check';
        } else if (type === 'error') {
            toastIcon.className = 'fas fa-circle-exclamation';
        } else {
            toastIcon.className = 'fas fa-info-circle';
        }

        toastTimer = setTimeout(() => {
            toast.classList.remove('show');
        }, 3600);
    }

    function updateMasterHeaders() {
        const addBoxes = Array.from(document.querySelectorAll('.col-add'));
        const viewBoxes = Array.from(document.querySelectorAll('.col-view'));
        const editBoxes = Array.from(document.querySelectorAll('.col-edit'));
        const approveBoxes = Array.from(document.querySelectorAll('.col-approve'));

        masterAdd.checked = addBoxes.length > 0 && addBoxes.every(b => b.checked);
        masterView.checked = viewBoxes.length > 0 && viewBoxes.every(b => b.checked);
        masterEdit.checked = editBoxes.length > 0 && editBoxes.every(b => b.checked);
        masterApprove.checked = approveBoxes.length > 0 && approveBoxes.every(b => b.checked);

        // Update granted stats
        const viewCount = viewBoxes.filter(b => b.checked).length;
        grantedCountEl.textContent = viewCount;
    }

    function updateUserMeta() {
        const opt = userSelect.options[userSelect.selectedIndex];
        if (!opt || !opt.value) {
            userNameEl.textContent = 'No user selected';
            userEmailEl.textContent = '—';
            userRoleEl.textContent = '—';
            userDeptEl.textContent = '—';
            userAvatarEl.textContent = '?';
            return;
        }

        const username = opt.getAttribute('data-username') || opt.text;
        const email = opt.getAttribute('data-email') || 'No Email';
        const role = opt.getAttribute('data-role') || 'User';
        const dept = opt.getAttribute('data-dept') || 'General';

        userNameEl.textContent = username;
        userEmailEl.textContent = email;
        userRoleEl.textContent = role;
        userDeptEl.textContent = dept;
        userAvatarEl.textContent = (username.charAt(0) || 'U').toUpperCase();
    }

    async function loadUserPermissions(userId) {
        if (!userId) return;

        // Reset check states first
        document.querySelectorAll('.uac-checkbox').forEach(cb => cb.checked = false);
        updateMasterHeaders();

        try {
            const res = await fetch(`${GET_API_URL}?user_id=${encodeURIComponent(userId)}`);
            const data = await res.json();

            if (data.success && data.permissions) {
                const perms = data.permissions;
                for (const pageNo in perms) {
                    const row = perms[pageNo];
                    const addCb = document.querySelector(`.col-add[data-page="${pageNo}"]`);
                    const viewCb = document.querySelector(`.col-view[data-page="${pageNo}"]`);
                    const editCb = document.querySelector(`.col-edit[data-page="${pageNo}"]`);
                    const appCb = document.querySelector(`.col-approve[data-page="${pageNo}"]`);

                    if (addCb) addCb.checked = !!row.add;
                    if (viewCb) viewCb.checked = !!row.view;
                    if (editCb) editCb.checked = !!row.edit;
                    if (appCb) appCb.checked = !!row.approve;
                }
            }
            updateMasterHeaders();
        } catch (err) {
            console.error('Failed to load user permissions:', err);
            showToast('Failed to load permissions from server', 'error');
        }
    }

    // User Select Change
    userSelect.addEventListener('change', function() {
        updateUserMeta();
        loadUserPermissions(this.value);
    });

    // Master Column Toggles
    masterAdd.addEventListener('change', function() {
        document.querySelectorAll('.col-add').forEach(cb => cb.checked = masterAdd.checked);
        updateMasterHeaders();
    });
    masterView.addEventListener('change', function() {
        document.querySelectorAll('.col-view').forEach(cb => cb.checked = masterView.checked);
        updateMasterHeaders();
    });
    masterEdit.addEventListener('change', function() {
        document.querySelectorAll('.col-edit').forEach(cb => cb.checked = masterEdit.checked);
        updateMasterHeaders();
    });
    masterApprove.addEventListener('change', function() {
        document.querySelectorAll('.col-approve').forEach(cb => cb.checked = masterApprove.checked);
        updateMasterHeaders();
    });

    // Checkbox Click delegation
    document.getElementById('uacTableBody').addEventListener('change', function(e) {
        if (e.target.classList.contains('uac-checkbox')) {
            updateMasterHeaders();
        }
    });

    // Select All Button
    btnSelectAll.addEventListener('click', function() {
        document.querySelectorAll('.uac-checkbox').forEach(cb => cb.checked = true);
        masterAdd.checked = true;
        masterView.checked = true;
        masterEdit.checked = true;
        masterApprove.checked = true;
        updateMasterHeaders();
        showToast('All 12 modules selected (Add, View, Edit, Approve)', 'info');
    });

    // Clear All Button
    btnClearAll.addEventListener('click', function() {
        document.querySelectorAll('.uac-checkbox').forEach(cb => cb.checked = false);
        masterAdd.checked = false;
        masterView.checked = false;
        masterEdit.checked = false;
        masterApprove.checked = false;
        updateMasterHeaders();
        showToast('All permissions cleared', 'info');
    });

    // Live Search across Users
    searchInput.addEventListener('input', function() {
        const query = this.value.trim().toLowerCase();
        const options = Array.from(userSelect.options);
        let firstMatch = null;

        options.forEach(opt => {
            if (!opt.value) return;
            const uName = (opt.getAttribute('data-username') || '').toLowerCase();
            const uMail = (opt.getAttribute('data-email') || '').toLowerCase();
            const text = opt.text.toLowerCase();

            const isMatch = !query || uName.includes(query) || uMail.includes(query) || text.includes(query);
            opt.style.display = isMatch ? '' : 'none';

            if (isMatch && !firstMatch) {
                firstMatch = opt;
            }
        });

        if (query && firstMatch && userSelect.value !== firstMatch.value) {
            userSelect.value = firstMatch.value;
            updateUserMeta();
            loadUserPermissions(firstMatch.value);
        }
    });

    // Save Permissions
    btnSave.addEventListener('click', async function() {
        const userId = parseInt(userSelect.value, 10);
        if (!userId) {
            showToast('Please select a target user first', 'error');
            return;
        }

        const opt = userSelect.options[userSelect.selectedIndex];
        const username = opt.getAttribute('data-username') || '';

        const permissions = PAGES_CATALOG.map(p => {
            const addCb = document.querySelector(`.col-add[data-page="${p.page_no}"]`);
            const viewCb = document.querySelector(`.col-view[data-page="${p.page_no}"]`);
            const editCb = document.querySelector(`.col-edit[data-page="${p.page_no}"]`);
            const appCb = document.querySelector(`.col-approve[data-page="${p.page_no}"]`);

            return {
                page_no: p.page_no,
                page_name: p.name,
                page_route: p.route,
                can_add: addCb && addCb.checked ? 1 : 0,
                can_view: viewCb && viewCb.checked ? 1 : 0,
                can_edit: editCb && editCb.checked ? 1 : 0,
                can_approve: appCb && appCb.checked ? 1 : 0,
            };
        });

        btnSave.disabled = true;
        const originalHtml = btnSave.innerHTML;
        btnSave.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

        try {
            const res = await fetch(SAVE_API_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    user_id: userId,
                    username: username,
                    permissions: permissions
                })
            });

            const data = await res.json();
            if (data.success) {
                showToast(`Permissions saved for ${username}!`, 'success');
            } else {
                showToast(data.error || 'Failed to save permissions', 'error');
            }
        } catch (err) {
            console.error('Save error:', err);
            showToast('Network error while saving permissions', 'error');
        } finally {
            btnSave.disabled = false;
            btnSave.innerHTML = originalHtml;
        }
    });

    // Initialize on load with first user
    document.addEventListener('DOMContentLoaded', function() {
        if (userSelect.options.length > 0 && userSelect.value) {
            updateUserMeta();
            loadUserPermissions(userSelect.value);
        }
    });
})();
</script>

