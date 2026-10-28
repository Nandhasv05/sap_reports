<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : Reports catalog — Atelier Launchpad UI
*/
$user = function_exists('portal_user') ? portal_user() : null;
$userName = !empty($user['username']) ? $user['username'] : 'admin';
$userRole = !empty($user['role']) ? $user['role'] : (!empty($user['department']) ? $user['department'] : 'Administrator');
$userEmail = $user['email'] ?? '';
$initial = strtoupper(substr($userName, 0, 1) ?: 'A');

$homeUrl = sap_reports_evol_url('portal_dashboard.php');
$logoutUrl = url('logout.php');
$logoUrl = sap_reports_logo_url();
$currentYear = date('Y');

$modules = [
    [
        'id' => 'openUtilModalBtn',
        'tag' => 'div',
        'href' => null,
        'tone' => 'util',
        'icon' => 'fa-layer-group',
        'title' => 'Utilization Report',
        'category' => 'utilization',
        'keywords' => 'utilization all materials fabric trims bom po grn issue combined',
        'modal' => true,
    ],
    [
        'tag' => 'a',
        'href' => url('fabric') . '?mode=unit',
        'tone' => 'fabric',
        'icon' => 'fa-scroll',
        'title' => 'Fabric Utilization',
        'category' => 'utilization',
        'keywords' => 'fabric utilization unit material sales order bom cutting roll issue live',
    ],
    [
        'tag' => 'a',
        'href' => url('sales'),
        'tone' => 'fabric-sale',
        'icon' => 'fa-tshirt',
        'title' => 'Fabric Sale Report',
        'category' => 'sales',
        'keywords' => 'fabric sale report sales fabric quotations orders revenue buyers',
    ],
    [
        'tag' => 'a',
        'href' => url('trims') . '?mode=unit',
        'tone' => 'trims',
        'icon' => 'fa-tags',
        'title' => 'Trims Utilization',
        'category' => 'utilization',
        'keywords' => 'trims utilization unit buttons zippers thread labels consumables accessories',
    ],
    [
        'tag' => 'a',
        'href' => url('production'),
        'tone' => 'production',
        'icon' => 'fa-industry',
        'title' => 'Production Report',
        'category' => 'operations',
        'keywords' => 'production cut sew wash finish packing shipment stage tracking orders operations',
    ],
    [
        'tag' => 'a',
        'href' => url('procurement'),
        'tone' => 'procurement',
        'icon' => 'fa-cart-shopping',
        'title' => 'Procurement Report',
        'category' => 'operations',
        'keywords' => 'procurement pr purchase requisition po balance stock component requirement allocation',
    ],
    [
        'tag' => 'a',
        'href' => url('purchase-order'),
        'tone' => 'po',
        'icon' => 'fa-file-invoice-dollar',
        'title' => 'Purchase Order Report',
        'category' => 'orders',
        'keywords' => 'purchase order po vendor supplier item pricing net price grn pending currency orders',
    ],
    [
        'tag' => 'a',
        'href' => url('purchase-order') . '?view=costing',
        'tone' => 'po-costing',
        'icon' => 'fa-calculator',
        'title' => 'PO Costing Validation',
        'category' => 'orders',
        'keywords' => 'po costing validation purchase order cost budget actual supplier plant net value orders',
    ],
    [
        'tag' => 'a',
        'href' => url('sales'),
        'tone' => 'sales',
        'icon' => 'fa-chart-line',
        'title' => 'Sales Dashboard',
        'category' => 'sales',
        'keywords' => 'sales dashboard quotations orders sap revenue net value order qty buyers customers pipeline',
    ],
];
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=Source+Sans+3:wght@400;500;600;700&display=swap" rel="stylesheet">

<div class="launch" id="hubWrapper">
    <aside class="launch-rail" aria-label="Brand">
        <a href="<?= e(url('/')) ?>" class="launch-rail-brand" title="SAP Reports">
            <img src="<?= e($logoUrl) ?>" alt="evolv">
        </a>
        <div class="launch-rail-nav">
            <a href="<?= e(url('/')) ?>" class="launch-rail-btn is-active" title="Reports catalog" aria-label="Reports catalog">
                <span class="material-icons-round">apps</span>
            </a>
            <a href="<?= e($homeUrl) ?>" class="launch-rail-btn" title="Portal dashboard" aria-label="Portal dashboard">
                <span class="material-icons-round">home</span>
            </a>
        </div>
        <div class="launch-rail-live" title="SAP services connected">
            <span class="launch-live-core"></span>
            <span class="launch-live-label">SAP</span>
        </div>
    </aside>

    <div class="launch-stage">
        <div class="launch-wash" aria-hidden="true"></div>
        <div class="launch-grain" aria-hidden="true"></div>

        <header class="launch-top">
            <div class="launch-top-copy">
                <p class="launch-kicker">EVOLV · Operational Analytics</p>
                <h1 class="launch-heading">Reports</h1>
            </div>

            <div class="launch-top-tools">
                <label class="launch-search" for="reportSearchInput">
                    <i class="fas fa-search" aria-hidden="true"></i>
                    <input type="search" id="reportSearchInput" placeholder="Find a report…" autocomplete="off" spellcheck="false" aria-label="Search report modules">
                    <kbd title="Press / to focus">/</kbd>
                    <button type="button" id="clearSearchBtn" class="launch-search-clear" hidden title="Clear search" aria-label="Clear search">&times;</button>
                </label>
                <?php require base_path('app/views/partials/header_nav_right.php'); ?>
            </div>
        </header>

        <main class="launch-main">
            <div class="launch-grid" id="reportCardsGrid">
                <?php foreach ($modules as $i => $mod): ?>
                    <?php
                        $tag = $mod['tag'];
                        $attrs = 'class="launch-tile tone-' . e($mod['tone']) . '" style="--i:' . (int) $i . '" data-category="' . e($mod['category']) . '" data-keywords="' . e($mod['keywords']) . '"';
                        if (!empty($mod['modal'])) {
                            $attrs .= ' id="openUtilModalBtn" role="button" tabindex="0" aria-haspopup="dialog"';
                        }
                        if ($tag === 'a') {
                            $attrs .= ' href="' . e($mod['href']) . '"';
                        }
                    ?>
                    <<?= $tag ?> <?= $attrs ?>>
                        <span class="launch-tile-mark" aria-hidden="true"></span>
                        <span class="launch-tile-icon"><i class="fas <?= e($mod['icon']) ?>"></i></span>
                        <span class="launch-tile-title hub-bento-title"><?= e($mod['title']) ?></span>
                    </<?= $tag ?>>
                <?php endforeach; ?>
            </div>

            <div id="noReportsFound" class="launch-empty" hidden>
                <i class="fas fa-search" aria-hidden="true"></i>
                <h3>No matching reports</h3>
                <p>Nothing matched “<span id="emptyQueryText"></span>”.</p>
                <button type="button" class="launch-empty-btn" id="resetSearchBtn">Clear search</button>
            </div>
        </main>

        <footer class="launch-foot">
            <span><b>EVOLV</b> © <?= e($currentYear) ?> EVOLV Clothing</span>
            <span>SAP ERP Operational Analytics Hub</span>
        </footer>
    </div>
</div>

<div class="hub-modal-backdrop" id="utilModal" hidden role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="hub-modal-card">
        <div class="hub-modal-header">
            <div>
                <span class="hub-modal-kicker">Select category</span>
                <h3 id="modalTitle">Utilization Reports</h3>
            </div>
            <button type="button" class="hub-modal-close" id="closeUtilModalBtn" aria-label="Close modal">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="hub-modal-body">
            <div class="hub-modal-grid">
                <a class="hub-modal-tile is-fabric-tile" href="<?= e(url('fabric') . '?mode=all') ?>">
                    <div class="hub-modal-tile-icon"><i class="fas fa-scroll"></i></div>
                    <div class="hub-modal-tile-content">
                        <h4>Fabric Utilization</h4>
                    </div>
                    <div class="hub-modal-tile-action"><span>Open</span><i class="fas fa-arrow-right"></i></div>
                </a>
                <a class="hub-modal-tile is-trims-tile" href="<?= e(url('trims') . '?mode=all') ?>">
                    <div class="hub-modal-tile-icon"><i class="fas fa-tags"></i></div>
                    <div class="hub-modal-tile-content">
                        <h4>Trims Utilization</h4>
                    </div>
                    <div class="hub-modal-tile-action"><span>Open</span><i class="fas fa-arrow-right"></i></div>
                </a>
            </div>
        </div>
    </div>
</div>

<style>
:root {
    --ink: #152028;
    --ink-soft: #24333d;
    --paper: #f3f1ec;
    --paper-2: #e8e4db;
    --line: rgba(21, 32, 40, 0.1);
    --teal: #0f766e;
    --teal-bright: #14b8a6;
    --sand: #c4a574;
}

html, body {
    height: 100%;
    margin: 0;
}

body {
    background: var(--paper);
    overflow: hidden;
}

.launch {
    --font-display: "Syne", Outfit, sans-serif;
    --font-body: "Source Sans 3", Inter, sans-serif;
    height: 100vh;
    display: grid;
    grid-template-columns: 76px 1fr;
    font-family: var(--font-body);
    color: var(--ink);
}

.launch-rail {
    background: linear-gradient(180deg, #101820 0%, #1a2832 100%);
    color: #fff;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 18px 0 16px;
    gap: 18px;
    z-index: 5;
    border-right: 1px solid rgba(255,255,255,0.06);
}

.launch-rail-brand img {
    width: 40px;
    height: auto;
    display: block;
    filter: brightness(1.05);
}

.launch-rail-nav {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-top: 8px;
}

.launch-rail-btn {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    display: grid;
    place-items: center;
    color: rgba(255,255,255,0.7);
    text-decoration: none;
    transition: background .2s ease, color .2s ease, transform .2s ease;
}

.launch-rail-btn .material-icons-round { font-size: 22px; }

.launch-rail-btn:hover,
.launch-rail-btn.is-active {
    background: rgba(20, 184, 166, 0.18);
    color: #5eead4;
}

.launch-rail-live {
    margin-top: auto;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
}

.launch-live-core {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--teal-bright);
    box-shadow: 0 0 0 0 rgba(20, 184, 166, 0.55);
    animation: livePulse 1.8s ease-out infinite;
}

@keyframes livePulse {
    0% { box-shadow: 0 0 0 0 rgba(20, 184, 166, 0.55); }
    70% { box-shadow: 0 0 0 12px rgba(20, 184, 166, 0); }
    100% { box-shadow: 0 0 0 0 rgba(20, 184, 166, 0); }
}

.launch-live-label {
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.14em;
    color: rgba(255,255,255,0.55);
}

.launch-stage {
    position: relative;
    display: flex;
    flex-direction: column;
    min-width: 0;
    overflow: hidden;
}

.launch-wash {
    position: absolute;
    inset: 0;
    background:
        radial-gradient(900px 420px at 12% -10%, rgba(20, 184, 166, 0.16), transparent 60%),
        radial-gradient(700px 380px at 95% 10%, rgba(196, 165, 116, 0.18), transparent 55%),
        linear-gradient(160deg, #f7f5f0 0%, #ebe7de 48%, #e3ece9 100%);
    z-index: 0;
}

.launch-grain {
    position: absolute;
    inset: 0;
    opacity: 0.035;
    pointer-events: none;
    z-index: 0;
    background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
}

.launch-main,
.launch-foot {
    position: relative;
    z-index: 1;
}

.launch-top {
    position: relative;
    z-index: 40; /* keep profile menu above catalog tiles */
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 24px;
    padding: 28px 36px 8px;
}

.launch-kicker {
    margin: 0 0 4px;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: #6b7280;
}

.launch-heading {
    margin: 0;
    font-family: var(--font-display);
    font-size: clamp(40px, 5vw, 56px);
    font-weight: 800;
    letter-spacing: -0.04em;
    line-height: 0.95;
    color: var(--ink);
}

.launch-top-tools {
    display: flex;
    align-items: center;
    gap: 14px;
}

.launch-search {
    position: relative;
    display: flex;
    align-items: center;
    width: min(360px, 42vw);
    height: 44px;
    padding: 0 12px 0 40px;
    border-radius: 999px;
    background: rgba(255,255,255,0.72);
    border: 1px solid var(--line);
    backdrop-filter: blur(10px);
    box-shadow: 0 8px 24px rgba(21, 32, 40, 0.05);
}

.launch-search i {
    position: absolute;
    left: 15px;
    color: #8b95a1;
    font-size: 13px;
}

.launch-search input {
    flex: 1;
    border: 0;
    outline: 0;
    background: transparent;
    font: 500 14px/1.2 var(--font-body);
    color: var(--ink);
}

.launch-search kbd {
    font-size: 11px;
    font-weight: 700;
    color: #94a3b8;
    background: #f1efe8;
    border: 1px solid #e2ddd2;
    border-radius: 6px;
    padding: 2px 6px;
}

.launch-search-clear {
    border: 0;
    background: transparent;
    color: #94a3b8;
    font-size: 18px;
    cursor: pointer;
    padding: 0 4px;
}

.launch-main {
    flex: 1;
    min-height: 0;
    padding: 18px 36px 12px;
    display: flex;
    flex-direction: column;
}

.launch-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
    align-content: start;
}

.launch-tile {
    --accent: var(--teal);
    position: relative;
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    gap: 18px;
    min-height: 148px;
    padding: 22px 20px 20px;
    border-radius: 18px;
    text-decoration: none;
    color: var(--ink);
    background: rgba(255,255,255,0.78);
    border: 1px solid rgba(21, 32, 40, 0.08);
    box-shadow: 0 10px 28px rgba(21, 32, 40, 0.04);
    overflow: hidden;
    cursor: pointer;
    opacity: 0;
    transform: translateY(14px);
    animation: tileIn .45s cubic-bezier(.16,1,.3,1) forwards;
    animation-delay: calc(var(--i, 0) * 45ms);
    transition: transform .22s ease, border-color .22s ease, box-shadow .22s ease, background .22s ease;
}

@keyframes tileIn {
    to { opacity: 1; transform: translateY(0); }
}

.launch-tile-mark {
    position: absolute;
    left: 0;
    top: 18px;
    bottom: 18px;
    width: 4px;
    border-radius: 0 4px 4px 0;
    background: var(--accent);
}

.launch-tile-icon {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    display: grid;
    place-items: center;
    font-size: 18px;
    color: #fff;
    background: var(--accent);
    box-shadow: 0 10px 20px color-mix(in srgb, var(--accent) 28%, transparent);
    transition: transform .22s ease;
}

.launch-tile-title {
    font-family: var(--font-display);
    font-size: 17px;
    font-weight: 700;
    letter-spacing: -0.02em;
    line-height: 1.2;
    margin: 0;
}

.launch-tile::after {
    content: "";
    position: absolute;
    right: -30px;
    top: -40px;
    width: 120px;
    height: 120px;
    border-radius: 50%;
    background: radial-gradient(circle, color-mix(in srgb, var(--accent) 18%, transparent), transparent 70%);
    pointer-events: none;
}

.launch-tile:hover {
    transform: translateY(-4px);
    border-color: color-mix(in srgb, var(--accent) 45%, transparent);
    background: #fff;
    box-shadow: 0 18px 36px rgba(21, 32, 40, 0.08);
}

.launch-tile:hover .launch-tile-icon {
    transform: scale(1.06) rotate(-3deg);
}

.launch-tile:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: 3px;
}

.tone-util { --accent: #0f766e; }
.tone-fabric { --accent: #0369a1; }
.tone-fabric-sale { --accent: #0e7490; }
.tone-trims { --accent: #c2410c; }
.tone-production { --accent: #4338ca; }
.tone-procurement { --accent: #be123c; }
.tone-po { --accent: #1d4ed8; }
.tone-po-costing { --accent: #7c3aed; }
.tone-sales { --accent: #047857; }

.launch-empty {
    margin: auto;
    text-align: center;
    color: #64748b;
    max-width: 360px;
}

.launch-empty[hidden] { display: none !important; }

.launch-empty i { font-size: 28px; margin-bottom: 10px; color: #94a3b8; }
.launch-empty h3 {
    margin: 0 0 6px;
    font-family: var(--font-display);
    color: var(--ink);
}
.launch-empty p { margin: 0 0 14px; }
.launch-empty-btn {
    border: 0;
    border-radius: 999px;
    padding: 10px 18px;
    background: var(--ink);
    color: #fff;
    font-weight: 600;
    cursor: pointer;
}

.launch-foot {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    padding: 10px 36px 16px;
    font-size: 12px;
    color: #7b8490;
}

.launch-foot b { color: var(--ink); font-weight: 700; }

/* Profile dropdown inherited classes — keep readable on new canvas */
.launch .hub-header-right { margin: 0; position: relative; z-index: 50; }
.launch .hub-profile-dropdown { z-index: 50; }
.launch .hub-profile-dropdown.is-open { z-index: 60; }
.launch .hub-profile-menu {
    background: #ffffff;
    backdrop-filter: none;
    -webkit-backdrop-filter: none;
}
.launch .hub-profile-trigger {
    border-radius: 999px;
    background: rgba(255,255,255,0.92);
    border: 1px solid var(--line);
    backdrop-filter: blur(10px);
}

/* Utilization modal */
.hub-modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 80;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: rgba(16, 24, 32, 0.55);
    backdrop-filter: blur(6px);
}
.hub-modal-backdrop.is-open,
.hub-modal-backdrop[style*="flex"] { display: flex; }
.hub-modal-card {
    width: min(720px, 100%);
    background: #fff;
    border-radius: 20px;
    border: 1px solid rgba(21,32,40,0.08);
    box-shadow: 0 30px 80px rgba(0,0,0,0.25);
    overflow: hidden;
}
.hub-modal-header {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    padding: 22px 24px 8px;
}
.hub-modal-kicker {
    display: block;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: #94a3b8;
    margin-bottom: 4px;
}
.hub-modal-header h3 {
    margin: 0;
    font-family: var(--font-display);
    font-size: 24px;
    letter-spacing: -0.03em;
}
.hub-modal-close {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    border: 1px solid #e5e7eb;
    background: #f8fafc;
    cursor: pointer;
}
.hub-modal-body { padding: 12px 24px 24px; }
.hub-modal-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}
.hub-modal-tile {
    display: flex;
    flex-direction: column;
    gap: 14px;
    padding: 18px;
    border-radius: 16px;
    text-decoration: none;
    color: inherit;
    border: 1px solid #e5e7eb;
    background: #fafafa;
    transition: transform .2s ease, border-color .2s ease, box-shadow .2s ease;
}
.hub-modal-tile:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 28px rgba(15,23,42,0.08);
}
.hub-modal-tile-icon {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    display: grid;
    place-items: center;
    color: #fff;
}
.is-fabric-tile .hub-modal-tile-icon { background: #0369a1; }
.is-trims-tile .hub-modal-tile-icon { background: #c2410c; }
.is-fabric-tile:hover { border-color: #0369a1; }
.is-trims-tile:hover { border-color: #c2410c; }
.hub-modal-tile-content h4 {
    margin: 0;
    font-family: var(--font-display);
    font-size: 18px;
}
.hub-modal-tile-action {
    margin-top: auto;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 13px;
    font-weight: 700;
    color: #475569;
}

@media (max-width: 1100px) {
    .launch-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
@media (max-width: 860px) {
    .launch { grid-template-columns: 1fr; }
    .launch-rail {
        flex-direction: row;
        justify-content: space-between;
        padding: 10px 14px;
        gap: 12px;
    }
    .launch-rail-nav { flex-direction: row; margin-top: 0; }
    .launch-rail-live { flex-direction: row; gap: 8px; margin-top: 0; }
    .launch-top { flex-direction: column; align-items: stretch; padding: 18px 18px 6px; }
    .launch-search { width: 100%; }
    .launch-main { padding: 12px 18px; }
    .launch-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .launch-foot { padding: 8px 18px 14px; flex-direction: column; gap: 4px; }
    .hub-modal-grid { grid-template-columns: 1fr; }
}
@media (max-width: 520px) {
    .launch-grid { grid-template-columns: 1fr; }
    .launch-heading { font-size: 36px; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('utilModal');
    var openBtn = document.getElementById('openUtilModalBtn');
    var closeBtn = document.getElementById('closeUtilModalBtn');

    function openModal() {
        if (!modal) return;
        modal.hidden = false;
        modal.style.display = 'flex';
    }

    function closeModal() {
        if (!modal) return;
        modal.hidden = true;
        modal.style.display = 'none';
    }

    if (openBtn) {
        openBtn.addEventListener('click', openModal);
        openBtn.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                openModal();
            }
        });
    }

    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeModal();
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeModal();
    });

    var searchInput = document.getElementById('reportSearchInput');
    var clearBtn = document.getElementById('clearSearchBtn');
    var cards = Array.from(document.querySelectorAll('#reportCardsGrid .launch-tile'));
    var emptyState = document.getElementById('noReportsFound');
    var emptyQueryText = document.getElementById('emptyQueryText');
    var resetSearchBtn = document.getElementById('resetSearchBtn');

    document.addEventListener('keydown', function (e) {
        if ((e.key === '/' || (e.ctrlKey && e.key === 'k')) && document.activeElement !== searchInput) {
            e.preventDefault();
            if (searchInput) {
                searchInput.focus();
                searchInput.select();
            }
        }
    });

    function filterReports() {
        var query = (searchInput ? searchInput.value : '').trim().toLowerCase();
        if (clearBtn) clearBtn.hidden = !query;

        var visibleCount = 0;
        cards.forEach(function (card) {
            var category = (card.getAttribute('data-category') || '').toLowerCase();
            var keywords = (card.getAttribute('data-keywords') || '').toLowerCase();
            var title = (card.querySelector('.launch-tile-title') ? card.querySelector('.launch-tile-title').textContent : '').toLowerCase();
            var matches = !query || title.indexOf(query) !== -1 || keywords.indexOf(query) !== -1 || category.indexOf(query) !== -1;
            card.style.display = matches ? 'flex' : 'none';
            if (matches) visibleCount++;
        });

        if (emptyState) {
            emptyState.hidden = visibleCount !== 0;
            if (emptyQueryText) emptyQueryText.textContent = query;
        }
    }

    if (searchInput) searchInput.addEventListener('input', filterReports);

    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            if (searchInput) {
                searchInput.value = '';
                searchInput.focus();
            }
            filterReports();
        });
    }

    if (resetSearchBtn) {
        resetSearchBtn.addEventListener('click', function () {
            if (searchInput) {
                searchInput.value = '';
                searchInput.focus();
            }
            filterReports();
        });
    }
});
</script>
