<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 05/11/2026
 * DESCRIPTION : POST-only API Activity Audit Log — last 30 days, action filters
 */
require_once base_path('app/services/ApiAuditLogService.php');
$records = is_array($records ?? null) ? $records : [];
$home = sap_reports_evol_url('portal_dashboard.php');
$catalogHome = url('/');
$auditApiUrl = url('api/audit-log');
$logoUrl = sap_reports_logo_url();
$total = (int) ($total ?? count($records));
$retentionDays = (int) ($retentionDays ?? 30);
$actionFilters = is_array($actionFilters ?? null) ? $actionFilters : ApiAuditLogService::ACTION_FILTERS;
$todayMax = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
$retentionFrom = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))
    ->modify('-' . max(1, $retentionDays) . ' days')
    ->format('d/m/Y');

$normalizeCreated = static function ($v): string {
    if ($v === null || $v === '') {
        return '';
    }
    if ($v instanceof DateTimeInterface) {
        return function_exists('sap_reports_to_mumbai')
            ? sap_reports_to_mumbai($v)
            : $v->format('Y-m-d H:i:s');
    }
    $s = trim((string) $v);
    if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/', $s, $m)) {
        return $m[1] . ' ' . $m[2];
    }
    return $s;
};

$fmtWhen = static function ($v) use ($normalizeCreated): string {
    $s = $normalizeCreated($v);
    if ($s === '') {
        return '—';
    }
    try {
        $dt = new DateTimeImmutable($s, new DateTimeZone('Asia/Kolkata'));
        return $dt->format('d/m/Y, h:i:s a') . ' IST';
    } catch (Throwable $e) {
        return $s . ' IST';
    }
};

$methodClass = static function ($m): string {
    $m = strtoupper(trim((string) $m));
    return match ($m) {
        'GET' => 'is-get',
        'POST' => 'is-post',
        'PUT' => 'is-put',
        'PATCH' => 'is-patch',
        'DELETE' => 'is-delete',
        default => 'is-other',
    };
};

$usersInLog = [];
foreach ($records as $r) {
    $u = trim((string) ($r['username'] ?? ''));
    if ($u !== '') {
        $usersInLog[strtolower($u)] = $u;
    }
}
ksort($usersInLog);

$utf8 = static function ($v): string {
    $s = (string) ($v ?? '');
    if ($s === '') {
        return '';
    }
    if (function_exists('mb_check_encoding') && !mb_check_encoding($s, 'UTF-8')) {
        $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
    }
    return $s;
};

$clipPayload = static function (string $s, int $max = 4000) use ($utf8): string {
    $s = $utf8($s);
    if (strlen($s) <= $max) {
        return $s;
    }
    return substr($s, 0, $max) . '…[truncated]';
};

$shortPreview = static function (?string $s, int $max = 80) use ($utf8): string {
    $s = trim($utf8($s ?? ''));
    if ($s === '') {
        return '—';
    }
    if (strlen($s) <= $max) {
        return $s;
    }
    return substr($s, 0, $max) . '…';
};

$encodeAuditRow = static function (array $row) use ($normalizeCreated, $utf8, $clipPayload): string {
    $method = strtoupper(trim((string) ($row['method'] ?? '')));
    $payload = [
        'id' => $row['id'] ?? null,
        'created_at' => $normalizeCreated($row['created_at'] ?? $row['createdon'] ?? ''),
        'createdon' => $normalizeCreated($row['created_at'] ?? $row['createdon'] ?? ''),
        'username' => $utf8($row['username'] ?? ''),
        'user_id' => $utf8($row['user_id'] ?? ''),
        'user_email' => $utf8($row['user_email'] ?? $row['usermailid'] ?? ''),
        'usermailid' => $utf8($row['user_email'] ?? $row['usermailid'] ?? ''),
        'method' => $method !== '' ? $method : 'POST',
        'page_name' => $utf8($row['page_name'] ?? ''),
        'action_key' => $utf8($row['action_key'] ?? ''),
        'action_label' => $utf8($row['action_label'] ?? ''),
        'url' => $utf8($row['url'] ?? $row['portal_api_url'] ?? ''),
        'portal_api_url' => $utf8($row['url'] ?? $row['portal_api_url'] ?? ''),
        'sap_api_url' => $utf8($row['sap_api_url'] ?? ''),
        'status' => (int) ($row['status'] ?? 0),
        'status_message' => $utf8($row['status_message'] ?? ''),
        'ip' => $utf8($row['ip'] ?? ''),
        'request_payload' => $clipPayload((string) ($row['request_payload'] ?? $row['portal_api_payload'] ?? '')),
        'response_payload' => $clipPayload((string) ($row['response_payload'] ?? $row['portal_api_response'] ?? '')),
        'portal_api_payload' => $clipPayload((string) ($row['request_payload'] ?? $row['portal_api_payload'] ?? '')),
        'portal_api_response' => $clipPayload((string) ($row['response_payload'] ?? $row['portal_api_response'] ?? '')),
        'sap_api_payload' => $clipPayload((string) ($row['sap_api_payload'] ?? '')),
        'sap_api_response' => $clipPayload((string) ($row['sap_api_response'] ?? '')),
    ];
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE;
    $json = json_encode($payload, $flags);
    if ($json !== false) {
        return $json;
    }
    foreach (['request_payload', 'response_payload', 'portal_api_payload', 'portal_api_response', 'sap_api_payload', 'sap_api_response'] as $k) {
        $payload[$k] = '';
    }
    $json = json_encode($payload, $flags);
    return $json !== false ? $json : '{}';
};

$colCount = 12;
?>
<div class="audit-page" id="auditPageRoot" data-tz="Asia/Kolkata" data-retention="<?= (int) $retentionDays ?>">
    <header class="audit-topbar">
        <div class="rpt-brand">
            <a class="rpt-brand-link" href="<?= e($home) ?>" title="EVOLV Portal Dashboard">
                <img src="<?= e($logoUrl) ?>" alt="EVOLV" class="rpt-logo">
            </a>
            <div class="rpt-brand-text">
                <span class="rpt-kicker">Administration · POST only · <?= (int) $retentionDays ?> days</span>
                <h1>API Activity Log</h1>
            </div>
        </div>
        <div class="audit-topbar-actions">
            <label class="audit-header-search" title="Search">
                <span class="material-icons-round">search</span>
                <input type="search" id="auditPageSearch" placeholder="User, page, URL, email, IP…" autocomplete="off" aria-label="Search">
            </label>
            <a class="hub-admin-btn hub-admin-btn-ghost" href="<?= e($catalogHome) ?>">
                <span class="material-icons-round">arrow_back</span>
                Back
            </a>
            <button type="button" class="hub-admin-btn hub-admin-btn-primary" id="auditPageRefreshBtn">
                <span class="material-icons-round">refresh</span>
                Refresh
            </button>
            <?php require base_path('app/views/partials/header_nav_right.php'); ?>
        </div>
    </header>

    <main class="audit-main">
        <div class="audit-filter-bar is-compact" id="auditFilters">
            <label class="audit-filter-field">
                <span>From (IST)</span>
                <input type="date" id="auditFromFilter" max="<?= e($todayMax) ?>" aria-label="From date IST">
            </label>
            <label class="audit-filter-field">
                <span>To (IST)</span>
                <input type="date" id="auditToFilter" max="<?= e($todayMax) ?>" aria-label="To date IST">
            </label>
            <label class="audit-filter-field">
                <span>Action</span>
                <select id="auditActionFilter" aria-label="Action filter">
                    <option value="">All actions</option>
                    <?php foreach ($actionFilters as $key => $label): ?>
                        <option value="<?= e((string) $key) ?>"><?= e((string) $label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="audit-filter-field">
                <span>Username</span>
                <select id="auditUserFilter" aria-label="Username filter">
                    <option value="">All users</option>
                    <?php foreach ($usersInLog as $key => $label): ?>
                        <option value="<?= e($key) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="audit-filter-meta" id="auditFilterMeta">
                Showing <b id="auditVisibleCount"><?= e((string) count($records)) ?></b> of <b id="auditTotalCount"><?= e((string) count($records)) ?></b>
                · POST only · kept from <b><?= e($retentionFrom) ?></b> · Mumbai IST
            </div>
        </div>

        <div class="audit-table-panel">
            <div class="audit-table-scroll">
                <table class="audit-page-table is-activity" id="auditPageTable">
                    <thead>
                        <tr>
                            <th>Created On (IST)</th>
                            <th>Username</th>
                            <th>User ID</th>
                            <th>User Mail ID</th>
                            <th>IP</th>
                            <th>Method</th>
                            <th>Page Name</th>
                            <th>Portal API URL</th>
                            <th>SAP API URL</th>
                            <th>Status</th>
                            <th>Payloads</th>
                            <th class="audit-col-action">Details</th>
                        </tr>
                    </thead>
                    <tbody id="auditPageBody">
                        <?php if (!$records): ?>
                            <tr id="auditEmptyState"><td colspan="<?= (int) $colCount ?>" class="hub-audit-empty">No POST API activity in the last <?= (int) $retentionDays ?> days.</td></tr>
                        <?php else: ?>
                            <tr id="auditNoMatchRow" class="is-filtered-out" hidden>
                                <td colspan="<?= (int) $colCount ?>" class="hub-audit-empty">No records match the current filters.</td>
                            </tr>
                            <?php foreach ($records as $row):
                                $status = (int) ($row['status'] ?? 0);
                                $statusCls = ($status >= 200 && $status < 300) ? 'is-ok' : (($status >= 400) ? 'is-bad' : '');
                                $methodRaw = strtoupper(trim((string) ($row['method'] ?? '')));
                                $method = $methodRaw !== '' ? $methodRaw : 'POST';
                                $pageName = (string) ($row['page_name'] ?? '');
                                $statusMsg = (string) ($row['status_message'] ?? '');
                                $urlFull = (string) ($row['url'] ?? $row['portal_api_url'] ?? '');
                                $sapUrl = (string) ($row['sap_api_url'] ?? '');
                                $username = (string) ($row['username'] ?? '');
                                $userEmail = (string) ($row['user_email'] ?? $row['usermailid'] ?? '');
                                $actionKey = (string) ($row['action_key'] ?? '');
                                $createdAt = $normalizeCreated($row['created_at'] ?? $row['createdon'] ?? '');
                                $dateKey = $createdAt !== '' ? substr($createdAt, 0, 10) : '';
                                $hay = strtolower(implode(' ', [
                                    $username,
                                    (string) ($row['user_id'] ?? ''),
                                    $userEmail,
                                    $method,
                                    $pageName,
                                    $actionKey,
                                    (string) ($row['action_label'] ?? ''),
                                    $urlFull,
                                    $sapUrl,
                                    (string) $status,
                                    $statusMsg,
                                    (string) ($row['ip'] ?? ''),
                                ]));
                                $jsonRow = $encodeAuditRow($row);
                                $hasPortalPayload = trim((string) ($row['request_payload'] ?? $row['portal_api_payload'] ?? '')) !== ''
                                    || trim((string) ($row['response_payload'] ?? $row['portal_api_response'] ?? '')) !== '';
                                $hasSapPayload = trim((string) ($row['sap_api_payload'] ?? '')) !== ''
                                    || trim((string) ($row['sap_api_response'] ?? '')) !== '';
                            ?>
                            <tr class="audit-row"
                                data-hay="<?= e($hay) ?>"
                                data-method="<?= e($method) ?>"
                                data-action="<?= e($actionKey) ?>"
                                data-user="<?= e(strtolower($username)) ?>"
                                data-date="<?= e($dateKey) ?>"
                                data-created="<?= e($createdAt) ?>"
                                data-audit="<?= e($jsonRow) ?>"
                                tabindex="0" role="button" aria-label="Open audit detail">
                                <td class="audit-col-when"><?= e($fmtWhen($createdAt)) ?></td>
                                <td><?= e($username !== '' ? $username : '—') ?></td>
                                <td><?= e((string) ($row['user_id'] ?? '—')) ?></td>
                                <td class="audit-col-email" title="<?= e($userEmail) ?>"><?= e($userEmail !== '' ? $userEmail : '—') ?></td>
                                <td class="audit-col-ip"><?= e((string) ($row['ip'] ?? '—')) ?></td>
                                <td><span class="audit-method <?= e($methodClass($method)) ?>"><?= e($method) ?></span></td>
                                <td class="audit-col-page" title="<?= e($pageName) ?>"><?= e($pageName !== '' ? $pageName : '—') ?></td>
                                <td class="hub-audit-url" title="<?= e($urlFull) ?>"><?= e($shortPreview($urlFull, 64)) ?></td>
                                <td class="hub-audit-url" title="<?= e($sapUrl) ?>"><?= e($shortPreview($sapUrl, 64)) ?></td>
                                <td>
                                    <span class="hub-audit-status <?= e($statusCls) ?>"><?= e((string) $status) ?></span>
                                    <?php if ($statusMsg !== ''): ?>
                                        <small class="audit-status-msg" title="<?= e($statusMsg) ?>"><?= e($shortPreview($statusMsg, 40)) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="audit-col-payload-flags">
                                    <?= $hasPortalPayload ? '<span class="audit-chip is-portal">Portal</span>' : '' ?>
                                    <?= $hasSapPayload ? '<span class="audit-chip is-sap">SAP</span>' : '' ?>
                                    <?= !$hasPortalPayload && !$hasSapPayload ? '—' : '' ?>
                                </td>
                                <td class="audit-col-action">
                                    <span class="audit-view-btn">
                                        View
                                        <span class="material-icons-round">chevron_right</span>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<div class="hub-modal-backdrop audit-detail-backdrop" id="auditDetailModal" hidden role="dialog" aria-modal="true" aria-labelledby="auditDetailTitle">
    <div class="hub-modal-card audit-detail-card is-wide">
        <div class="hub-modal-header">
            <div>
                <span class="hub-modal-kicker">API activity detail</span>
                <h3 id="auditDetailTitle">Request details</h3>
            </div>
            <button type="button" class="hub-modal-close" id="auditDetailClose" aria-label="Close detail">
                <span class="material-icons-round">close</span>
            </button>
        </div>
        <div class="hub-modal-body audit-detail-body">
            <div class="audit-detail-grid" id="auditDetailMeta"></div>

            <div class="audit-detail-payload-head">
                <h4>Portal API payload</h4>
                <button type="button" class="hub-admin-btn hub-admin-btn-ghost" id="auditCopyRequestBtn" title="Copy portal request">
                    <span class="material-icons-round">content_copy</span>
                    Copy
                </button>
            </div>
            <pre class="audit-detail-payload is-request" id="auditDetailRequest">—</pre>

            <div class="audit-detail-payload-head">
                <h4>Portal API response</h4>
                <button type="button" class="hub-admin-btn hub-admin-btn-ghost" id="auditCopyPayloadBtn" title="Copy portal response">
                    <span class="material-icons-round">content_copy</span>
                    Copy
                </button>
            </div>
            <pre class="audit-detail-payload" id="auditDetailPayload">—</pre>

            <div class="audit-detail-payload-head">
                <h4>SAP API payload</h4>
                <button type="button" class="hub-admin-btn hub-admin-btn-ghost" id="auditCopySapReqBtn" title="Copy SAP request">
                    <span class="material-icons-round">content_copy</span>
                    Copy
                </button>
            </div>
            <pre class="audit-detail-payload is-request" id="auditDetailSapRequest">—</pre>

            <div class="audit-detail-payload-head">
                <h4>SAP API response</h4>
                <button type="button" class="hub-admin-btn hub-admin-btn-ghost" id="auditCopySapResBtn" title="Copy SAP response">
                    <span class="material-icons-round">content_copy</span>
                    Copy
                </button>
            </div>
            <pre class="audit-detail-payload" id="auditDetailSapPayload">—</pre>
        </div>
    </div>
</div>

<script>
(function () {
    var apiUrl = <?= json_encode($auditApiUrl, JSON_UNESCAPED_SLASHES) ?>;
    var colCount = <?= (int) $colCount ?>;
    var retentionDays = <?= (int) $retentionDays ?>;
    var body = document.getElementById('auditPageBody');
    var search = document.getElementById('auditPageSearch');
    var actionFilter = document.getElementById('auditActionFilter');
    var fromFilter = document.getElementById('auditFromFilter');
    var toFilter = document.getElementById('auditToFilter');
    var userFilter = document.getElementById('auditUserFilter');
    var modal = document.getElementById('auditDetailModal');
    var metaEl = document.getElementById('auditDetailMeta');
    var payloadEl = document.getElementById('auditDetailPayload');
    var requestEl = document.getElementById('auditDetailRequest');
    var sapReqEl = document.getElementById('auditDetailSapRequest');
    var sapResEl = document.getElementById('auditDetailSapPayload');
    var titleEl = document.getElementById('auditDetailTitle');
    var currentPayload = '';
    var currentRequest = '';
    var currentSapReq = '';
    var currentSapRes = '';
    var cachedRows = [];

    function esc(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function normalizeCreated(raw) {
        if (raw == null || raw === '') return '';
        if (typeof raw === 'object' && raw.date) raw = raw.date;
        var s = String(raw).trim();
        var m = s.match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/);
        if (m) return m[1] + ' ' + m[2];
        return s;
    }

    function dateKeyFromCreated(raw) {
        var s = normalizeCreated(raw);
        return s ? s.slice(0, 10) : '';
    }

    function inDateRange(dateKey) {
        if (!dateKey) return !(fromFilter && fromFilter.value) && !(toFilter && toFilter.value);
        var from = String((fromFilter && fromFilter.value) || '');
        var to = String((toFilter && toFilter.value) || '');
        if (from && dateKey < from) return false;
        if (to && dateKey > to) return false;
        return true;
    }

    function fmtWhen(raw) {
        var s = normalizeCreated(raw);
        if (!s) return '—';
        try {
            var parts = s.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/);
            if (!parts) return s + ' IST';
            var y = +parts[1], mo = +parts[2], da = +parts[3];
            var h = +parts[4], mi = +parts[5], se = +parts[6];
            var ampm = h >= 12 ? 'pm' : 'am';
            var h12 = h % 12; if (h12 === 0) h12 = 12;
            var pad = function (n) { return String(n).padStart(2, '0'); };
            return pad(da) + '/' + pad(mo) + '/' + y + ', ' + pad(h12) + ':' + pad(mi) + ':' + pad(se) + ' ' + ampm + ' IST';
        } catch (e) {
            return s + ' IST';
        }
    }

    function statusClass(status) {
        var n = Number(status);
        if (n >= 200 && n < 300) return 'is-ok';
        if (n >= 400) return 'is-bad';
        return '';
    }

    function methodClass(m) {
        m = String(m || 'POST').toUpperCase();
        if (m === 'GET') return 'is-get';
        if (m === 'POST') return 'is-post';
        if (m === 'PUT') return 'is-put';
        if (m === 'PATCH') return 'is-patch';
        if (m === 'DELETE') return 'is-delete';
        return 'is-other';
    }

    function normalizeMethod(m) {
        m = String(m || '').trim().toUpperCase();
        return m || 'POST';
    }

    function prettyPayload(raw) {
        var text = String(raw == null || raw === '' ? '' : raw);
        if (!text) return '—';
        try { return JSON.stringify(JSON.parse(text), null, 2); }
        catch (e) { return text; }
    }

    function shortPreview(s, max) {
        s = String(s == null ? '' : s).trim();
        if (!s) return '—';
        max = max || 64;
        return s.length <= max ? s : (s.slice(0, max) + '…');
    }

    function fillUserFilter(rows) {
        if (!userFilter) return;
        var current = userFilter.value || '';
        var users = {};
        rows.forEach(function (r) {
            var u = String(r.username || '').trim();
            if (u) users[u.toLowerCase()] = u;
        });
        var names = Object.keys(users).sort();
        userFilter.innerHTML = '<option value="">All users</option>' + names.map(function (k) {
            return '<option value="' + esc(k) + '">' + esc(users[k]) + '</option>';
        }).join('');
        if (current && users[current]) userFilter.value = current;
    }

    function openDetail(row, tr) {
        if (!modal || !row) return;
        currentRequest = prettyPayload(row.portal_api_payload || row.request_payload);
        currentPayload = prettyPayload(row.portal_api_response || row.response_payload);
        currentSapReq = prettyPayload(row.sap_api_payload);
        currentSapRes = prettyPayload(row.sap_api_response);
        if (titleEl) {
            titleEl.textContent = '#' + (row.id != null ? row.id : '—')
                + ' · ' + normalizeMethod(row.method)
                + ' · ' + (row.page_name || row.action_label || 'API')
                + ' · Status ' + (row.status || '—');
        }
        if (metaEl) {
            var fields = [
                ['Created On', fmtWhen(row.created_at || row.createdon)],
                ['Username', row.username || '—'],
                ['User ID', row.user_id || '—'],
                ['User Mail ID', row.user_email || row.usermailid || '—'],
                ['IP', row.ip || '—'],
                ['Method', normalizeMethod(row.method)],
                ['Page Name', row.page_name || '—'],
                ['Action', row.action_label || row.action_key || '—'],
                ['Status', String(row.status != null ? row.status : '—')],
                ['Status Message', row.status_message || '—'],
                ['Portal API URL', row.portal_api_url || row.url || '—'],
                ['SAP API URL', row.sap_api_url || '—']
            ];
            metaEl.innerHTML = fields.map(function (pair) {
                var isUrl = pair[0].indexOf('URL') !== -1;
                var isStatus = pair[0] === 'Status';
                var isMethod = pair[0] === 'Method';
                var valueHtml;
                if (isStatus) valueHtml = '<span class="hub-audit-status ' + statusClass(pair[1]) + '">' + esc(pair[1]) + '</span>';
                else if (isMethod) valueHtml = '<span class="audit-method ' + methodClass(pair[1]) + '">' + esc(pair[1]) + '</span>';
                else valueHtml = esc(pair[1]);
                return '<div class="audit-detail-item' + (isUrl ? ' is-wide' : '') + '">' +
                    '<dt>' + esc(pair[0]) + '</dt>' +
                    '<dd' + (isUrl ? ' class="is-mono"' : '') + '>' + valueHtml + '</dd></div>';
            }).join('');
        }
        if (requestEl) requestEl.textContent = currentRequest;
        if (payloadEl) payloadEl.textContent = currentPayload;
        if (sapReqEl) sapReqEl.textContent = currentSapReq;
        if (sapResEl) sapResEl.textContent = currentSapRes;
        modal.hidden = false;
        modal.classList.add('is-open');
        modal.style.display = 'flex';
        document.body.classList.add('hub-modal-open');
        document.querySelectorAll('#auditPageBody tr.audit-row.is-active').forEach(function (el) {
            el.classList.remove('is-active');
        });
        if (tr) tr.classList.add('is-active');
    }

    function closeDetail() {
        if (!modal) return;
        modal.classList.remove('is-open');
        modal.hidden = true;
        modal.style.display = 'none';
        document.body.classList.remove('hub-modal-open');
        document.querySelectorAll('#auditPageBody tr.audit-row.is-active').forEach(function (tr) {
            tr.classList.remove('is-active');
        });
    }

    function parseRow(tr) {
        try { return JSON.parse(tr.getAttribute('data-audit') || 'null'); }
        catch (e) { return null; }
    }

    function bindRowClicks() {
        if (!body) return;
        body.querySelectorAll('tr.audit-row').forEach(function (tr) {
            tr.onclick = function () {
                var row = parseRow(tr);
                if (row) openDetail(row, tr);
            };
            tr.onkeydown = function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    tr.click();
                }
            };
        });
    }

    function normalizeRow(row) {
        var created = normalizeCreated(row.created_at || row.createdon);
        return {
            id: row.id,
            created_at: created,
            createdon: created,
            username: row.username || '',
            user_id: row.user_id || '',
            user_email: row.user_email || row.usermailid || '',
            usermailid: row.user_email || row.usermailid || '',
            method: normalizeMethod(row.method),
            page_name: row.page_name || '',
            action_key: row.action_key || '',
            action_label: row.action_label || '',
            url: row.url || row.portal_api_url || '',
            portal_api_url: row.portal_api_url || row.url || '',
            sap_api_url: row.sap_api_url || '',
            status: row.status,
            status_message: row.status_message || '',
            ip: row.ip || '',
            request_payload: row.request_payload || row.portal_api_payload || '',
            response_payload: row.response_payload || row.portal_api_response || '',
            portal_api_payload: row.portal_api_payload || row.request_payload || '',
            portal_api_response: row.portal_api_response || row.response_payload || '',
            sap_api_payload: row.sap_api_payload || '',
            sap_api_response: row.sap_api_response || ''
        };
    }

    function render(rows) {
        cachedRows = (Array.isArray(rows) ? rows : []).map(normalizeRow);
        fillUserFilter(cachedRows);
        if (!body) return;
        if (!cachedRows.length) {
            body.innerHTML = '<tr id="auditEmptyState"><td colspan="' + colCount + '" class="hub-audit-empty">No POST API activity in the last ' + retentionDays + ' days.</td></tr>';
            var visEl = document.getElementById('auditVisibleCount');
            var totEl = document.getElementById('auditTotalCount');
            if (visEl) visEl.textContent = '0';
            if (totEl) totEl.textContent = '0';
            return;
        }
        body.innerHTML = '<tr id="auditNoMatchRow" class="is-filtered-out" hidden><td colspan="' + colCount + '" class="hub-audit-empty">No records match the current filters.</td></tr>' +
            cachedRows.map(function (row) {
            var method = row.method;
            var pageName = row.page_name;
            var statusMsg = row.status_message;
            var url = row.portal_api_url || row.url;
            var sapUrl = row.sap_api_url || '';
            var username = row.username;
            var email = row.user_email || row.usermailid || '';
            var created = row.created_at;
            var dKey = dateKeyFromCreated(created);
            var actionKey = row.action_key || '';
            var hay = [username, row.user_id, email, method, pageName, actionKey, row.action_label, url, sapUrl, row.status, statusMsg, row.ip].join(' ').toLowerCase();
            var data = esc(JSON.stringify(row));
            var hasPortal = !!(row.portal_api_payload || row.portal_api_response);
            var hasSap = !!(row.sap_api_payload || row.sap_api_response);
            var chips = '';
            if (hasPortal) chips += '<span class="audit-chip is-portal">Portal</span>';
            if (hasSap) chips += '<span class="audit-chip is-sap">SAP</span>';
            if (!chips) chips = '—';
            return '<tr class="audit-row" data-hay="' + esc(hay) + '" data-method="' + esc(method) + '" data-action="' + esc(actionKey) + '" data-user="' + esc(username.toLowerCase()) + '" data-date="' + esc(dKey) + '" data-created="' + esc(created) + '" data-audit="' + data + '" tabindex="0" role="button">' +
                '<td class="audit-col-when">' + esc(fmtWhen(created)) + '</td>' +
                '<td>' + esc(username || '—') + '</td>' +
                '<td>' + esc(row.user_id || '—') + '</td>' +
                '<td class="audit-col-email" title="' + esc(email) + '">' + esc(email || '—') + '</td>' +
                '<td class="audit-col-ip">' + esc(row.ip || '—') + '</td>' +
                '<td><span class="audit-method ' + methodClass(method) + '">' + esc(method) + '</span></td>' +
                '<td class="audit-col-page" title="' + esc(pageName) + '">' + esc(pageName || '—') + '</td>' +
                '<td class="hub-audit-url" title="' + esc(url) + '">' + esc(shortPreview(url)) + '</td>' +
                '<td class="hub-audit-url" title="' + esc(sapUrl) + '">' + esc(shortPreview(sapUrl)) + '</td>' +
                '<td><span class="hub-audit-status ' + statusClass(row.status) + '">' + esc(row.status) + '</span>' +
                    (statusMsg ? '<small class="audit-status-msg" title="' + esc(statusMsg) + '">' + esc(shortPreview(statusMsg, 40)) + '</small>' : '') +
                '</td>' +
                '<td class="audit-col-payload-flags">' + chips + '</td>' +
                '<td class="audit-col-action"><span class="audit-view-btn">View <span class="material-icons-round">chevron_right</span></span></td>' +
                '</tr>';
        }).join('');
        bindRowClicks();
        applyFilter();
    }

    function applyFilter() {
        if (!body) return;
        var q = String((search && search.value) || '').trim().toLowerCase();
        var action = String((actionFilter && actionFilter.value) || '');
        var user = String((userFilter && userFilter.value) || '').toLowerCase();
        var allRows = body.querySelectorAll('tr.audit-row');
        var visibleRows = [];

        allRows.forEach(function (tr) {
            var hayOk = !q || (tr.getAttribute('data-hay') || '').indexOf(q) !== -1;
            var actionOk = !action || String(tr.getAttribute('data-action') || '') === action;
            var userOk = !user || String(tr.getAttribute('data-user') || '') === user;
            var dateOk = inDateRange(tr.getAttribute('data-date') || dateKeyFromCreated(tr.getAttribute('data-created') || ''));
            var show = hayOk && actionOk && userOk && dateOk;
            tr.classList.toggle('is-filtered-out', !show);
            tr.hidden = !show;
            if (show) {
                var row = parseRow(tr);
                if (row) visibleRows.push(normalizeRow(row));
            }
        });
        var visEl = document.getElementById('auditVisibleCount');
        var totEl = document.getElementById('auditTotalCount');
        if (visEl) visEl.textContent = String(visibleRows.length);
        if (totEl) totEl.textContent = String(allRows.length);
        var noMatch = document.getElementById('auditNoMatchRow');
        if (noMatch) {
            var showNoMatch = allRows.length > 0 && visibleRows.length === 0;
            noMatch.classList.toggle('is-filtered-out', !showNoMatch);
            noMatch.hidden = !showNoMatch;
        }
    }

    function load() {
        if (!body || !apiUrl) return;
        if (window.rptLoader && window.rptLoader.show) {
            window.rptLoader.show({
                title: 'Loading audit log',
                steps: ['Connecting…', 'Fetching POST activity…', 'Preparing table…']
            });
        }
        fetch(apiUrl + (apiUrl.indexOf('?') >= 0 ? '&' : '?') + 'limit=2000&_=' + Date.now(), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store'
        })
            .then(function (res) { return res.json().then(function (json) { return { res: res, json: json }; }); })
            .then(function (pack) {
                if (!pack.res.ok || pack.json.success === false) {
                    throw new Error((pack.json && (pack.json.message || pack.json.error)) || ('HTTP ' + pack.res.status));
                }
                var records = (pack.json.data && pack.json.data.records) ? pack.json.data.records : [];
                render(Array.isArray(records) ? records : []);
            })
            .catch(function (err) {
                body.innerHTML = '<tr><td colspan="' + colCount + '" class="hub-audit-empty">Could not load audit log: ' + esc(err.message || err) + '</td></tr>';
            })
            .finally(function () {
                if (window.rptLoader && window.rptLoader.hide) window.rptLoader.hide(true);
            });
    }

    function copyText(text) {
        if (!text || text === '—') return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).catch(function () {});
        }
    }

    var refreshBtn = document.getElementById('auditPageRefreshBtn');
    var closeBtn = document.getElementById('auditDetailClose');
    var copyResBtn = document.getElementById('auditCopyPayloadBtn');
    var copyReqBtn = document.getElementById('auditCopyRequestBtn');
    var copySapReqBtn = document.getElementById('auditCopySapReqBtn');
    var copySapResBtn = document.getElementById('auditCopySapResBtn');
    if (refreshBtn) refreshBtn.addEventListener('click', load);
    if (search) search.addEventListener('input', applyFilter);
    if (actionFilter) actionFilter.addEventListener('change', applyFilter);
    if (fromFilter) fromFilter.addEventListener('change', applyFilter);
    if (toFilter) toFilter.addEventListener('change', applyFilter);
    if (userFilter) userFilter.addEventListener('change', applyFilter);
    if (closeBtn) closeBtn.addEventListener('click', closeDetail);
    if (modal) modal.addEventListener('click', function (e) { if (e.target === modal) closeDetail(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal && modal.classList.contains('is-open')) closeDetail();
    });
    if (copyResBtn) copyResBtn.addEventListener('click', function () { copyText(currentPayload); });
    if (copyReqBtn) copyReqBtn.addEventListener('click', function () { copyText(currentRequest); });
    if (copySapReqBtn) copySapReqBtn.addEventListener('click', function () { copyText(currentSapReq); });
    if (copySapResBtn) copySapResBtn.addEventListener('click', function () { copyText(currentSapRes); });

    bindRowClicks();
    applyFilter();
    load();
})();
</script>
