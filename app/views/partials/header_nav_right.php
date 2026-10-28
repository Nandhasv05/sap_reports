<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : Header right user profile dropdown (Avatar with dropdown: Back to Card, Back to Portal, Logout)
 */
$user = function_exists('portal_user') ? portal_user() : null;
$userName = !empty($user['username']) ? $user['username'] : 'admin';
$userRole = !empty($user['role']) ? $user['role'] : (!empty($user['department']) ? $user['department'] : 'Administrator');
$userEmail = $user['email'] ?? '';
$cardsUrl = function_exists('url') ? url('/') : '/sap_reports/';
$homeUrl = function_exists('sap_reports_evol_url') ? sap_reports_evol_url('portal_dashboard.php') : '/EVOL/portal_dashboard.php';
$logoutUrl = function_exists('url') ? url('logout.php') : '/sap_reports/logout.php';
$accessUrl = function_exists('sap_reports_evol_url') ? sap_reports_evol_url('user_management.php') : '/EVOL/user_management.php';
$auditPageUrl = function_exists('url') ? url('audit-log') : '/sap_reports/audit-log';
$roleLower = strtolower(trim((string) $userRole));
$userLower = strtolower(trim((string) $userName));
$isAdminMenu = !empty($user['is_admin'])
    || in_array($roleLower, ['admin', 'it_admin', 'super_admin', 'administrator', 'director', 'management'], true);
if ($userLower === 'kayal') {
    $isAdminMenu = false;
}

$accessModules = [
    'utilization' => 'Utilization Report',
    'fabric' => 'Fabric Utilization',
    'trims' => 'Trims Utilization',
    'production' => 'Production Report',
    'procurement' => 'Procurement Report',
    'purchase-order' => 'Purchase Order Report',
];
?>
<div class="rpt-bar-right hub-header-right">
    <div class="hub-profile-dropdown" id="hubProfileDropdown">
        <button type="button" class="hub-profile-trigger" id="hubProfileTrigger" aria-expanded="false" aria-haspopup="true" title="User Menu: <?= e($userName) ?>">
            <div class="hub-avatar-wrap">
                <svg class="hub-avatar-svg" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg" aria-label="Staff Avatar">
                    <circle cx="18" cy="18" r="18" fill="url(#headerStaffAvatarGrad)"/>
                    <path d="M18 9C15.24 9 13 11.24 13 14C13 16.76 15.24 19 18 19C20.76 19 23 16.76 23 14C23 11.24 20.76 9 18 9Z" fill="#ffffff"/>
                    <path d="M9.5 28C9.5 23.86 13.31 20.5 18 20.5C22.69 20.5 26.5 23.86 26.5 28C26.5 28.5 26.1 29 25.5 29H10.5C9.9 29 9.5 28.5 9.5 28Z" fill="#ffffff" fill-opacity="0.92"/>
                    <defs>
                        <linearGradient id="headerStaffAvatarGrad" x1="0" y1="0" x2="36" y2="36" gradientUnits="userSpaceOnUse">
                            <stop stop-color="#0284c7"/>
                            <stop offset="1" stop-color="#4f46e5"/>
                        </linearGradient>
                    </defs>
                </svg>
                <span class="hub-avatar-online-dot" title="User Online"></span>
            </div>
            <div class="hub-user-meta">
                <span class="hub-user-name"><?= e(ucfirst($userName)) ?></span>
                <span class="hub-user-role"><?= e(strtoupper($userRole)) ?></span>
            </div>
            <span class="material-icons-round hub-profile-caret" aria-hidden="true">expand_more</span>
        </button>

        <div class="hub-profile-menu" id="hubProfileMenu" role="menu" aria-labelledby="hubProfileTrigger">
            <div class="hub-profile-header">
                <div class="hub-profile-header-avatar">
                    <svg viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="18" cy="18" r="18" fill="url(#headerStaffAvatarGradLg)"/>
                        <path d="M18 9C15.24 9 13 11.24 13 14C13 16.76 15.24 19 18 19C20.76 19 23 16.76 23 14C23 11.24 20.76 9 18 9Z" fill="#ffffff"/>
                        <path d="M9.5 28C9.5 23.86 13.31 20.5 18 20.5C22.69 20.5 26.5 23.86 26.5 28C26.5 28.5 26.1 29 25.5 29H10.5C9.9 29 9.5 28.5 9.5 28Z" fill="#ffffff" fill-opacity="0.92"/>
                        <defs>
                            <linearGradient id="headerStaffAvatarGradLg" x1="0" y1="0" x2="36" y2="36" gradientUnits="userSpaceOnUse">
                                <stop stop-color="#0284c7"/>
                                <stop offset="1" stop-color="#4f46e5"/>
                            </linearGradient>
                        </defs>
                    </svg>
                </div>
                <div class="hub-profile-header-info">
                    <div class="hub-profile-header-name"><?= e(ucfirst($userName)) ?></div>
                    <div class="hub-profile-header-role"><?= e(strtoupper($userRole)) ?></div>
                    <?php if (!empty($userEmail)): ?>
                        <div class="hub-profile-header-email"><?= e($userEmail) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="hub-profile-divider"></div>

            <div class="hub-profile-items">
                <a href="<?= e($cardsUrl) ?>" class="hub-profile-item" role="menuitem">
                    <span class="material-icons-round hub-profile-item-icon">dashboard</span>
                    <span class="hub-profile-item-text">
                        <span class="hub-profile-item-title">Back to Card</span>
                        <span class="hub-profile-item-sub">Reports catalog hub</span>
                    </span>
                </a>

                <a href="<?= e($homeUrl) ?>" class="hub-profile-item" role="menuitem">
                    <span class="material-icons-round hub-profile-item-icon">home</span>
                    <span class="hub-profile-item-text">
                        <span class="hub-profile-item-title">Back to Portal</span>
                        <span class="hub-profile-item-sub">EVOLV main portal</span>
                    </span>
                </a>

                <?php if ($isAdminMenu): ?>
                <button type="button" class="hub-profile-item" role="menuitem" id="hubOpenAccessBtn" data-hub-modal="hubAccessModal">
                    <span class="material-icons-round hub-profile-item-icon">admin_panel_settings</span>
                    <span class="hub-profile-item-text">
                        <span class="hub-profile-item-title">User Access Control</span>
                        <span class="hub-profile-item-sub">Manage module permissions</span>
                    </span>
                </button>

                <a href="<?= e($auditPageUrl) ?>" class="hub-profile-item" role="menuitem" id="hubOpenAuditBtn">
                    <span class="material-icons-round hub-profile-item-icon">history</span>
                    <span class="hub-profile-item-text">
                        <span class="hub-profile-item-title">Audit Log</span>
                        <span class="hub-profile-item-sub">Full-screen API activity</span>
                    </span>
                </a>
                <?php endif; ?>
            </div>

            <div class="hub-profile-divider"></div>

            <div class="hub-profile-footer">
                <a href="<?= e($logoutUrl) ?>" class="hub-profile-item hub-profile-item-danger" role="menuitem">
                    <span class="material-icons-round hub-profile-item-icon">logout</span>
                    <span class="hub-profile-item-text">
                        <span class="hub-profile-item-title">Logout</span>
                        <span class="hub-profile-item-sub">Sign out safely</span>
                    </span>
                </a>
            </div>
        </div>
    </div>
</div>

<?php if ($isAdminMenu): ?>
<div class="hub-modal-backdrop" id="hubAccessModal" hidden role="dialog" aria-modal="true" aria-labelledby="hubAccessTitle">
    <div class="hub-modal-card hub-admin-modal">
        <div class="hub-modal-header">
            <div>
                <span class="hub-modal-kicker">Administration</span>
                <h3 id="hubAccessTitle">User Access Control</h3>
            </div>
            <button type="button" class="hub-modal-close" data-hub-close="hubAccessModal" aria-label="Close modal">
                <span class="material-icons-round">close</span>
            </button>
        </div>
        <div class="hub-modal-body">
            <p class="hub-admin-lead">Review SAP Reports module access for <strong><?= e(ucfirst($userName)) ?></strong>, then open the full portal console to grant or revoke permissions.</p>
            <div class="hub-access-grid">
                <?php foreach ($accessModules as $modKey => $modLabel):
                    $allowed = function_exists('sap_reports_user_can_access') ? sap_reports_user_can_access($modKey, $user) : true;
                ?>
                <div class="hub-access-chip <?= $allowed ? 'is-on' : 'is-off' ?>">
                    <span class="material-icons-round"><?= $allowed ? 'check_circle' : 'cancel' ?></span>
                    <span><?= e($modLabel) ?></span>
                    <em><?= $allowed ? 'Allowed' : 'Denied' ?></em>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="hub-admin-actions">
                <a class="hub-admin-btn hub-admin-btn-primary" href="<?= e($accessUrl) ?>" target="_blank" rel="noopener">
                    <span class="material-icons-round">open_in_new</span>
                    Open User Access Control
                </a>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<script>
(function() {
    function closeHubModalEl(el) {
        if (!el) return;
        el.classList.remove('is-open');
        el.hidden = true;
        el.style.display = 'none';
        if (!document.querySelector('.hub-modal-backdrop.is-open')) {
            document.body.classList.remove('hub-modal-open');
        }
    }

    function openHubModalEl(el) {
        if (!el) return;
        el.hidden = false;
        el.classList.add('is-open');
        el.style.display = 'flex';
        document.body.classList.add('hub-modal-open');
    }

    function initProfileDropdown() {
        var trigger = document.getElementById('hubProfileTrigger');
        var dropdown = document.getElementById('hubProfileDropdown');
        if (!trigger || !dropdown) return;
        if (trigger.dataset.bound === 'true') return;
        trigger.dataset.bound = 'true';

        trigger.addEventListener('click', function(e) {
            e.stopPropagation();
            var isOpen = dropdown.classList.toggle('is-open');
            trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });

        document.addEventListener('click', function(e) {
            if (!dropdown.contains(e.target)) {
                dropdown.classList.remove('is-open');
                trigger.setAttribute('aria-expanded', 'false');
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                dropdown.classList.remove('is-open');
                trigger.setAttribute('aria-expanded', 'false');
                document.querySelectorAll('.hub-modal-backdrop.is-open').forEach(closeHubModalEl);
            }
        });
    }

    function initHubAdminModals() {
        document.querySelectorAll('[data-hub-modal]').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var dropdown = document.getElementById('hubProfileDropdown');
                var trigger = document.getElementById('hubProfileTrigger');
                if (dropdown) dropdown.classList.remove('is-open');
                if (trigger) trigger.setAttribute('aria-expanded', 'false');
                openHubModalEl(document.getElementById(btn.getAttribute('data-hub-modal')));
            });
        });

        document.querySelectorAll('[data-hub-close]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                closeHubModalEl(document.getElementById(btn.getAttribute('data-hub-close')));
            });
        });

        document.querySelectorAll('.hub-modal-backdrop').forEach(function(modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === modal) closeHubModalEl(modal);
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            initProfileDropdown();
            initHubAdminModals();
        });
    } else {
        initProfileDropdown();
        initHubAdminModals();
    }
})();
</script>
