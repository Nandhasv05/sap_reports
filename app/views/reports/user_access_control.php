<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 04/11/2026
 * DESCRIPTION : SAP Reports User Access Control — Access Studio
 *               Reports: View · PO Costing: Pass PO / Assign / Validate / eSign · ZCOST: Add/Update/Copy/Approve
 */

$catalogHome = url('/');
$home = sap_reports_evol_url('portal_dashboard.php');
$logoUrl = sap_reports_logo_url();
$pageTitle = 'User Access Control';
$pageCount = is_array($pages ?? null) ? count($pages) : 12;

$extraHead = '
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Syne:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="' . e(asset('css/user_access_control.css')) . '?v=' . time() . '">
';
?>

<div class="rpt-app is-uac-page">
    <div class="uac-atmosphere" aria-hidden="true">
        <span class="uac-mesh uac-mesh-a"></span>
        <span class="uac-mesh uac-mesh-b"></span>
        <span class="uac-grid-fade"></span>
    </div>

    <header class="rpt-bar uac-topbar">
        <div class="rpt-bar-left">
            <a href="<?= e($catalogHome) ?>" class="rpt-brand" aria-label="EVOLV" title="SAP Reports Catalog">
                <img src="<?= e($logoUrl) ?>" alt="evolv">
            </a>
            <a href="<?= e($catalogHome) ?>" class="rpt-home" aria-label="All Reports" title="Reports Catalog">
                <span class="material-icons-round">apps</span>
            </a>
            <a href="<?= e($home) ?>" class="rpt-home" aria-label="Portal home" title="Portal Dashboard">
                <span class="material-icons-round">home</span>
            </a>
            <div class="rpt-name">Access Studio</div>
        </div>
        <?php require base_path('app/views/partials/header_nav_right.php'); ?>
    </header>

    <main class="uac-shell">
        <aside class="uac-rail" aria-label="Target user">
            <div class="uac-rail-head">
                <p class="uac-kicker">Permission studio</p>
                <h1 class="uac-rail-title">Who gets in</h1>
            </div>

            <div class="uac-field">
                <label for="uacSearchInput">Find user</label>
                <div class="uac-input-shell">
                    <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
                    <input type="text" id="uacSearchInput" placeholder="Name, email, or role…" autocomplete="off" aria-label="Search user">
                </div>
            </div>

            <div class="uac-field">
                <label for="uacUserSelect">Target user</label>
                <div class="uac-select-shell">
                    <select id="uacUserSelect" aria-label="Target User">
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
                    <i class="fas fa-chevron-down" aria-hidden="true"></i>
                </div>
            </div>

            <div class="uac-profile" id="uacUserMetaBanner">
                <div class="uac-avatar" id="uacUserAvatar">?</div>
                <div class="uac-profile-text">
                    <div class="uac-user-name" id="uacUserName">No user selected</div>
                    <div class="uac-user-email" id="uacUserEmail">—</div>
                </div>
                <div class="uac-tags">
                    <span class="uac-tag is-role" id="uacUserRole">—</span>
                    <span class="uac-tag is-dept" id="uacUserDept">—</span>
                </div>
                <div class="uac-meter">
                    <div class="uac-meter-top">
                        <span>Modules granted</span>
                        <strong><span id="uacGrantedCount">0</span><span class="uac-meter-total"> / <span id="uacPageTotal"><?= (int) $pageCount ?></span></span></strong>
                    </div>
                    <div class="uac-meter-track" aria-hidden="true">
                        <span class="uac-meter-fill" id="uacGrantedFill" style="width:0%"></span>
                    </div>
                </div>
            </div>

            <div class="uac-rail-actions">
                <button type="button" class="uac-btn is-ghost" id="btnSelectAll" title="Select all available rights">
                    <i class="fas fa-check-double"></i> Select all
                </button>
                <button type="button" class="uac-btn is-ghost" id="btnClearAll" title="Clear all permissions">
                    <i class="fas fa-eraser"></i> Clear
                </button>
                <button type="button" class="uac-btn is-save" id="btnSavePermissions" title="Save permissions">
                    <i class="fas fa-floppy-disk"></i>
                    <span>Save permissions</span>
                </button>
            </div>
        </aside>

        <section class="uac-stage" aria-label="Module permissions">
            <header class="uac-stage-head">
                <div>
                    <p class="uac-kicker">Module matrix</p>
                    <h2 class="uac-stage-title">Rights by product area</h2>
                </div>
                <div class="uac-legend">
                    <span><i class="uac-dot is-view"></i> Reports · View</span>
                    <span><i class="uac-dot is-pass"></i> PO Costing · View / Pass PO / Assign / Validate / eSign</span>
                    <span><i class="uac-dot is-zcost"></i> ZCOST · Add / Update / Copy / Approve</span>
                </div>
            </header>

            <div id="uacModuleList" class="uac-module-list">
                <?php foreach (($pages ?? []) as $idx => $p):
                    $kind = (string) ($p['kind'] ?? 'report');
                    $actions = is_array($p['actions'] ?? null) ? $p['actions'] : [];
                    $adminOnly = !empty($p['admin_only']);
                ?>
                    <article class="uac-mod<?= $adminOnly ? ' is-admin-only' : '' ?>"
                             data-page-no="<?= (int) $p['page_no'] ?>"
                             data-route="<?= e($p['route']) ?>"
                             data-kind="<?= e($kind) ?>"
                             data-admin-only="<?= $adminOnly ? '1' : '0' ?>"
                             style="--uac-stagger: <?= (int) $idx + 1 ?>">
                        <div class="uac-mod-icon" style="--icon-bg: <?= e($p['icon_bg'] ?? '#f1f5f9') ?>; --icon-fg: <?= e($p['icon_color'] ?? '#475569') ?>;" title="P<?= (int) $p['page_no'] ?>">
                            <i class="fas <?= e($p['icon'] ?? 'fa-file') ?>"></i>
                        </div>
                        <div class="uac-mod-meta">
                            <span class="uac-mod-no">P<?= (int) $p['page_no'] ?></span>
                            <h4 class="uac-mod-name"><?= e($p['name']) ?></h4>
                        </div>
                        <div class="uac-rights" role="group" aria-label="Rights for <?= e($p['name']) ?>">
                            <?php foreach ($actions as $act):
                                $actKey = (string) ($act['key'] ?? '');
                                $actLabel = (string) ($act['label'] ?? $actKey);
                                $actCol = (string) ($act['column'] ?? '');
                            ?>
                                <label class="uac-right" data-action="<?= e($actKey) ?>" title="<?= e($actLabel) ?>">
                                    <input type="checkbox"
                                           class="uac-checkbox col-<?= e($actKey) ?>"
                                           data-page="<?= (int) $p['page_no'] ?>"
                                           data-action="<?= e($actKey) ?>"
                                           data-column="<?= e($actCol) ?>">
                                    <span class="uac-right-ui">
                                        <span class="uac-right-check" aria-hidden="true"></span>
                                        <span class="uac-right-label"><?= e($actLabel) ?></span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </main>

    <?php require base_path('app/views/partials/footer.php'); ?>

    <div id="uacToast" class="uac-toast" role="alert" aria-live="assertive">
        <i class="fas fa-circle-check" id="uacToastIcon"></i>
        <span id="uacToastMsg">Permissions saved successfully</span>
    </div>
</div>

<script type="application/json" id="uacConfig"><?= json_encode([
    'pages'   => array_values(array_map(static function ($p) {
        return [
            'page_no' => (int) ($p['page_no'] ?? 0),
            'name' => (string) ($p['name'] ?? ''),
            'route' => (string) ($p['route'] ?? ''),
            'kind' => (string) ($p['kind'] ?? 'report'),
            'admin_only' => !empty($p['admin_only']),
            'actions' => is_array($p['actions'] ?? null) ? $p['actions'] : [],
        ];
    }, is_array($pages ?? null) ? $pages : [])),
    'users'   => array_values(array_map(static function ($u) {
        return [
            'Id'         => (int) ($u['Id'] ?? $u['id'] ?? 0),
            'UserName'   => (string) ($u['UserName'] ?? $u['username'] ?? ''),
            'Email'      => (string) ($u['Email'] ?? $u['email'] ?? ''),
            'Role'       => (string) ($u['Role'] ?? $u['role'] ?? 'User'),
            'Department' => (string) ($u['Department'] ?? $u['department'] ?? ''),
        ];
    }, is_array($users ?? null) ? $users : [])),
    'getUrl'  => url('api/user-access-control/get'),
    'saveUrl' => url('api/user-access-control/save'),
    'pageCount' => $pageCount,
    'adminOnlyUsers' => ['admin', 'nandhakumar', 'saravanan', 'saravan'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script src="<?= e(asset('js/user-access-control.js')) ?>?v=<?= time() ?>"></script>
