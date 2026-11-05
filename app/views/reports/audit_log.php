<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 05/11/2026
 * DESCRIPTION : Full-page API Activity Audit Log — one table for all API calls
 */
$records = is_array($records ?? null) ? $records : [];
$catalogHome = url('/');
$auditApiUrl = url('api/audit-log');
$logoUrl = sap_reports_logo_url();
$total = (int) ($total ?? count($records));

$normalizeCreated = static function ($v): string {
    if ($v === null || $v === '') {
        return '';
    }
    if ($v instanceof DateTimeInterface) {
        return $v->format('Y-m-d H:i:s');
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
        return (new DateTimeImmutable($s))->format('d/m/Y, h:i:s a');
    } catch (Throwable $e) {
        return $s;
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

$encodeAuditRow = static function (array $row) use ($normalizeCreated, $utf8, $clipPayload): string {
    $method = strtoupper(trim((string) ($row['method'] ?? '')));
    $payload = [
        'id' => $row['id'] ?? null,
        'created_at' => $normalizeCreated($row['created_at'] ?? ''),
        'username' => $utf8($row['username'] ?? ''),
        'user_id' => $utf8($row['user_id'] ?? ''),
        'method' => $method !== '' ? $method : 'GET',
        'page_name' => $utf8($row['page_name'] ?? ''),
        'url' => $utf8($row['url'] ?? ''),
        'status' => (int) ($row['status'] ?? 0),
        'status_message' => $utf8($row['status_message'] ?? ''),
        'ip' => $utf8($row['ip'] ?? ''),
        // Keep first paint light; full payloads come from API hydrate/refresh.
        'request_payload' => $clipPayload((string) ($row['request_payload'] ?? '')),
        'response_payload' => $clipPayload((string) ($row['response_payload'] ?? '')),
    ];
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE;
    $json = json_encode($payload, $flags);
    if ($json !== false) {
        return $json;
    }
    $payload['request_payload'] = '';
    $payload['response_payload'] = '';
    $json = json_encode($payload, $flags);
    return $json !== false ? $json : '{}';
};
?>
<div class="audit-page">
    <header class="audit-topbar">
        <div class="rpt-brand">
            <a class="rpt-brand-link" href="<?= e($catalogHome) ?>" title="Back to reports catalog">
                <img src="<?= e($logoUrl) ?>" alt="EVOLV" class="rpt-logo">
            </a>
            <div class="rpt-brand-text">
                <span class="rpt-kicker">Administration</span>
                <h1>API Activity Log</h1>
            </div>
        </div>
        <div class="audit-topbar-actions">
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
        <section class="audit-stats" id="auditStats">
            <article class="audit-stat">
                <span class="audit-stat-label">Total</span>
                <strong class="audit-stat-value" id="auditStatTotal"><?= e((string) $total) ?></strong>
            </article>
            <article class="audit-stat is-ok">
                <span class="audit-stat-label">Success</span>
                <strong class="audit-stat-value" id="auditStatOk">—</strong>
            </article>
            <article class="audit-stat is-bad">
                <span class="audit-stat-label">Errors</span>
                <strong class="audit-stat-value" id="auditStatBad">—</strong>
            </article>
            <article class="audit-stat is-method">
                <span class="audit-stat-label">Methods</span>
                <strong class="audit-stat-value" id="auditStatMethods">—</strong>
            </article>
        </section>

        <div class="audit-filter-bar" id="auditFilters">
            <label class="audit-filter-field">
                <span>Date</span>
                <select id="auditDateFilter" aria-label="Date filter">
                    <option value="all" selected>All dates</option>
                    <option value="today">Today</option>
                    <option value="yesterday">Yesterday</option>
                    <option value="last7">Last 7 days</option>
                    <option value="last30">Last 30 days</option>
                </select>
            </label>
            <label class="audit-filter-field">
                <span>Method</span>
                <select id="auditMethodFilter" aria-label="Method filter">
                    <option value="">All methods</option>
                    <option value="GET">GET</option>
                    <option value="POST">POST</option>
                    <option value="PUT">PUT</option>
                    <option value="PATCH">PATCH</option>
                    <option value="DELETE">DELETE</option>
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
            <label class="audit-filter-field is-search">
                <span>Search</span>
                <span class="audit-search">
                    <span class="material-icons-round">search</span>
                    <input type="search" id="auditPageSearch" placeholder="Page, URL, status, IP…" autocomplete="off" aria-label="Search">
                </span>
            </label>
            <button type="button" class="hub-admin-btn hub-admin-btn-ghost audit-filter-clear" id="auditClearFilters" title="Clear filters">
                <span class="material-icons-round">filter_alt_off</span>
                Clear
            </button>
            <div class="audit-filter-meta" id="auditFilterMeta">
                Showing <b id="auditVisibleCount"><?= e((string) count($records)) ?></b> of <b id="auditTotalCount"><?= e((string) count($records)) ?></b> records
            </div>
        </div>

        <div class="audit-table-panel">
            <div class="audit-table-scroll">
                <table class="audit-page-table is-activity" id="auditPageTable">
                    <thead>
                        <tr>
                            <th>Date / Time</th>
                            <th>User ID</th>
                            <th>Username</th>
                            <th>Method</th>
                            <th>Page</th>
                            <th>URL</th>
                            <th>Status</th>
                            <th>Status Message</th>
                            <th>IP</th>
                            <th class="audit-col-action">Details</th>
                        </tr>
                    </thead>
                    <tbody id="auditPageBody">
                        <?php if (!$records): ?>
                            <tr id="auditEmptyState"><td colspan="10" class="hub-audit-empty">No API activity yet. Use any report API to create entries.</td></tr>
                        <?php else: ?>
                            <tr id="auditNoMatchRow" class="is-filtered-out" hidden>
                                <td colspan="10" class="hub-audit-empty">No records match the current filters.</td>
                            </tr>
                            <?php foreach ($records as $row):
                                $status = (int) ($row['status'] ?? 0);
                                $statusCls = ($status >= 200 && $status < 300) ? 'is-ok' : (($status >= 400) ? 'is-bad' : '');
                                $methodRaw = strtoupper(trim((string) ($row['method'] ?? '')));
                                $method = $methodRaw !== '' ? $methodRaw : 'GET';
                                $pageName = (string) ($row['page_name'] ?? '');
                                $statusMsg = (string) ($row['status_message'] ?? '');
                                $urlFull = (string) ($row['url'] ?? '');
                                $username = (string) ($row['username'] ?? '');
                                $createdAt = $normalizeCreated($row['created_at'] ?? '');
                                $dateKey = $createdAt !== '' ? substr($createdAt, 0, 10) : '';
                                $hay = strtolower(implode(' ', [
                                    $username,
                                    (string) ($row['user_id'] ?? ''),
                                    $method,
                                    $pageName,
                                    $urlFull,
                                    (string) $status,
                                    $statusMsg,
                                    (string) ($row['ip'] ?? ''),
                                ]));
                                $jsonRow = $encodeAuditRow($row);
                            ?>
                            <tr class="audit-row"
                                data-hay="<?= e($hay) ?>"
                                data-method="<?= e($method) ?>"
                                data-user="<?= e(strtolower($username)) ?>"
                                data-date="<?= e($dateKey) ?>"
                                data-created="<?= e($createdAt) ?>"
                                data-audit="<?= e($jsonRow) ?>"
                                tabindex="0" role="button" aria-label="Open audit detail">
                                <td class="audit-col-when"><?= e($fmtWhen($createdAt)) ?></td>
                                <td><?= e((string) ($row['user_id'] ?? '—')) ?></td>
                                <td><?= e($username !== '' ? $username : '—') ?></td>
                                <td><span class="audit-method <?= e($methodClass($method)) ?>"><?= e($method) ?></span></td>
                                <td class="audit-col-page" title="<?= e($pageName) ?>"><?= e($pageName !== '' ? $pageName : '—') ?></td>
                                <td class="hub-audit-url" title="<?= e($urlFull) ?>"><?= e($urlFull !== '' ? $urlFull : '—') ?></td>
                                <td><span class="hub-audit-status <?= e($statusCls) ?>"><?= e((string) $status) ?></span></td>
                                <td class="audit-col-msg" title="<?= e($statusMsg) ?>"><?= e($statusMsg !== '' ? $statusMsg : '—') ?></td>
                                <td class="audit-col-ip"><?= e((string) ($row['ip'] ?? '—')) ?></td>
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
    <div class="hub-modal-card audit-detail-card">
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
                <h4>Request payload</h4>
                <button type="button" class="hub-admin-btn hub-admin-btn-ghost" id="auditCopyRequestBtn" title="Copy request">
                    <span class="material-icons-round">content_copy</span>
                    Copy
                </button>
            </div>
            <pre class="audit-detail-payload is-request" id="auditDetailRequest">—</pre>
            <div class="audit-detail-payload-head">
                <h4>Response payload</h4>
                <button type="button" class="hub-admin-btn hub-admin-btn-ghost" id="auditCopyPayloadBtn" title="Copy response">
                    <span class="material-icons-round">content_copy</span>
                    Copy
                </button>
            </div>
            <pre class="audit-detail-payload" id="auditDetailPayload">—</pre>
        </div>
    </div>
</div>

<script>
(function () {
    var apiUrl = <?= json_encode($auditApiUrl, JSON_UNESCAPED_SLASHES) ?>;
    var body = document.getElementById('auditPageBody');
    var search = document.getElementById('auditPageSearch');
    var methodFilter = document.getElementById('auditMethodFilter');
    var dateFilter = document.getElementById('auditDateFilter');
    var userFilter = document.getElementById('auditUserFilter');
    var modal = document.getElementById('auditDetailModal');
    var metaEl = document.getElementById('auditDetailMeta');
    var payloadEl = document.getElementById('auditDetailPayload');
    var requestEl = document.getElementById('auditDetailRequest');
    var titleEl = document.getElementById('auditDetailTitle');
    var currentPayload = '';
    var currentRequest = '';
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
        var d = new Date(s);
        if (!isNaN(d.getTime())) {
            var y = d.getFullYear();
            var mo = String(d.getMonth() + 1).padStart(2, '0');
            var da = String(d.getDate()).padStart(2, '0');
            var h = String(d.getHours()).padStart(2, '0');
            var mi = String(d.getMinutes()).padStart(2, '0');
            var se = String(d.getSeconds()).padStart(2, '0');
            return y + '-' + mo + '-' + da + ' ' + h + ':' + mi + ':' + se;
        }
        return s;
    }

    function dateKeyFromCreated(raw) {
        var s = normalizeCreated(raw);
        return s ? s.slice(0, 10) : '';
    }

    function localDateKey(offsetDays) {
        var d = new Date();
        d.setHours(0, 0, 0, 0);
        if (offsetDays) d.setDate(d.getDate() + offsetDays);
        var y = d.getFullYear();
        var mo = String(d.getMonth() + 1).padStart(2, '0');
        var da = String(d.getDate()).padStart(2, '0');
        return y + '-' + mo + '-' + da;
    }

    function inDateRange(dateKey, preset) {
        if (!preset || preset === 'all') return true;
        if (!dateKey) return false;
        var today = localDateKey(0);
        if (preset === 'today') return dateKey === today;
        if (preset === 'yesterday') return dateKey === localDateKey(-1);
        if (preset === 'last7' || preset === 'last30') {
            var days = preset === 'last7' ? 6 : 29;
            var from = localDateKey(-days);
            return dateKey >= from && dateKey <= today;
        }
        return true;
    }

    function fmtWhen(raw) {
        var s = normalizeCreated(raw);
        if (!s) return '—';
        try {
            var d = new Date(s.replace(' ', 'T'));
            if (isNaN(d.getTime())) return s;
            return d.toLocaleString('en-IN');
        } catch (e) {
            return s;
        }
    }

    function statusClass(status) {
        var n = Number(status);
        if (n >= 200 && n < 300) return 'is-ok';
        if (n >= 400) return 'is-bad';
        return '';
    }

    function methodClass(m) {
        m = String(m || 'GET').toUpperCase();
        if (m === 'GET') return 'is-get';
        if (m === 'POST') return 'is-post';
        if (m === 'PUT') return 'is-put';
        if (m === 'PATCH') return 'is-patch';
        if (m === 'DELETE') return 'is-delete';
        return 'is-other';
    }

    function normalizeMethod(m) {
        m = String(m || '').trim().toUpperCase();
        return m || 'GET';
    }

    function prettyPayload(raw) {
        var text = String(raw == null || raw === '' ? '' : raw);
        if (!text) return '—';
        try { return JSON.stringify(JSON.parse(text), null, 2); }
        catch (e) { return text; }
    }

    function updateStats(rows) {
        var total = rows.length, ok = 0, bad = 0, methods = {};
        rows.forEach(function (r) {
            var s = Number(r.status || 0);
            if (s >= 200 && s < 300) ok++;
            else if (s >= 400) bad++;
            methods[normalizeMethod(r.method)] = true;
        });
        var elT = document.getElementById('auditStatTotal');
        var elO = document.getElementById('auditStatOk');
        var elB = document.getElementById('auditStatBad');
        var elM = document.getElementById('auditStatMethods');
        if (elT) elT.textContent = String(total);
        if (elO) elO.textContent = String(ok);
        if (elB) elB.textContent = String(bad);
        if (elM) elM.textContent = Object.keys(methods).sort().join(' · ') || '—';
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
        currentPayload = prettyPayload(row.response_payload);
        currentRequest = prettyPayload(row.request_payload);
        if (titleEl) {
            titleEl.textContent = '#' + (row.id != null ? row.id : '—')
                + ' · ' + normalizeMethod(row.method)
                + ' · ' + (row.page_name || 'API')
                + ' · Status ' + (row.status || '—');
        }
        if (metaEl) {
            var fields = [
                ['Date / Time', fmtWhen(row.created_at)],
                ['User ID', row.user_id || '—'],
                ['Username', row.username || '—'],
                ['Method', normalizeMethod(row.method)],
                ['Page', row.page_name || '—'],
                ['Status', String(row.status != null ? row.status : '—')],
                ['Status Message', row.status_message || '—'],
                ['IP', row.ip || '—'],
                ['URL', row.url || '—']
            ];
            metaEl.innerHTML = fields.map(function (pair) {
                var isUrl = pair[0] === 'URL';
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
        var created = normalizeCreated(row.created_at);
        return {
            id: row.id,
            created_at: created,
            username: row.username || '',
            user_id: row.user_id || '',
            method: normalizeMethod(row.method),
            page_name: row.page_name || '',
            url: row.url || '',
            status: row.status,
            status_message: row.status_message || '',
            ip: row.ip || '',
            request_payload: row.request_payload || '',
            response_payload: row.response_payload || ''
        };
    }

    function render(rows) {
        cachedRows = (Array.isArray(rows) ? rows : []).map(normalizeRow);
        fillUserFilter(cachedRows);
        if (!body) return;
        if (!cachedRows.length) {
            body.innerHTML = '<tr id="auditEmptyState"><td colspan="10" class="hub-audit-empty">No API activity yet. Use any report API to create entries.</td></tr>';
            updateStats([]);
            var visEl = document.getElementById('auditVisibleCount');
            var totEl = document.getElementById('auditTotalCount');
            if (visEl) visEl.textContent = '0';
            if (totEl) totEl.textContent = '0';
            return;
        }
        body.innerHTML = '<tr id="auditNoMatchRow" class="is-filtered-out" hidden><td colspan="10" class="hub-audit-empty">No records match the current filters.</td></tr>' +
            cachedRows.map(function (row) {
            var method = row.method;
            var pageName = row.page_name;
            var statusMsg = row.status_message;
            var url = row.url;
            var username = row.username;
            var created = row.created_at;
            var dKey = dateKeyFromCreated(created);
            var hay = [username, row.user_id, method, pageName, url, row.status, statusMsg, row.ip].join(' ').toLowerCase();
            var data = esc(JSON.stringify(row));
            return '<tr class="audit-row" data-hay="' + esc(hay) + '" data-method="' + esc(method) + '" data-user="' + esc(username.toLowerCase()) + '" data-date="' + esc(dKey) + '" data-created="' + esc(created) + '" data-audit="' + data + '" tabindex="0" role="button">' +
                '<td class="audit-col-when">' + esc(fmtWhen(created)) + '</td>' +
                '<td>' + esc(row.user_id || '—') + '</td>' +
                '<td>' + esc(username || '—') + '</td>' +
                '<td><span class="audit-method ' + methodClass(method) + '">' + esc(method) + '</span></td>' +
                '<td class="audit-col-page" title="' + esc(pageName) + '">' + esc(pageName || '—') + '</td>' +
                '<td class="hub-audit-url" title="' + esc(url) + '">' + esc(url || '—') + '</td>' +
                '<td><span class="hub-audit-status ' + statusClass(row.status) + '">' + esc(row.status) + '</span></td>' +
                '<td class="audit-col-msg" title="' + esc(statusMsg) + '">' + esc(statusMsg || '—') + '</td>' +
                '<td class="audit-col-ip">' + esc(row.ip || '—') + '</td>' +
                '<td class="audit-col-action"><span class="audit-view-btn">View <span class="material-icons-round">chevron_right</span></span></td>' +
                '</tr>';
        }).join('');
        bindRowClicks();
        applyFilter();
    }

    function applyFilter() {
        if (!body) return;
        var q = String((search && search.value) || '').trim().toLowerCase();
        var method = String((methodFilter && methodFilter.value) || '').toUpperCase();
        var user = String((userFilter && userFilter.value) || '').toLowerCase();
        var datePreset = String((dateFilter && dateFilter.value) || 'all');
        var allRows = body.querySelectorAll('tr.audit-row');
        var visibleRows = [];

        allRows.forEach(function (tr) {
            var hayOk = !q || (tr.getAttribute('data-hay') || '').indexOf(q) !== -1;
            var rowMethod = normalizeMethod(tr.getAttribute('data-method') || '');
            var methodOk = !method || rowMethod === method;
            var userOk = !user || String(tr.getAttribute('data-user') || '') === user;
            var dateOk = inDateRange(tr.getAttribute('data-date') || dateKeyFromCreated(tr.getAttribute('data-created') || ''), datePreset);
            var show = hayOk && methodOk && userOk && dateOk;
            tr.classList.toggle('is-filtered-out', !show);
            tr.hidden = !show;
            if (show) {
                var row = parseRow(tr);
                if (row) visibleRows.push(normalizeRow(row));
            }
        });
        updateStats(visibleRows);
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

    function clearFilters() {
        if (dateFilter) dateFilter.value = 'all';
        if (methodFilter) methodFilter.value = '';
        if (userFilter) userFilter.value = '';
        if (search) search.value = '';
        applyFilter();
    }

    function load() {
        if (!body || !apiUrl) return;
        if (window.rptLoader && window.rptLoader.show) {
            window.rptLoader.show({
                title: 'Loading audit log',
                steps: ['Connecting…', 'Fetching API activity…', 'Preparing table…']
            });
        }
        fetch(apiUrl + (apiUrl.indexOf('?') >= 0 ? '&' : '?') + 'limit=300&_=' + Date.now(), {
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
                body.innerHTML = '<tr><td colspan="10" class="hub-audit-empty">Could not load audit log: ' + esc(err.message || err) + '</td></tr>';
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
    var clearBtn = document.getElementById('auditClearFilters');
    var closeBtn = document.getElementById('auditDetailClose');
    var copyResBtn = document.getElementById('auditCopyPayloadBtn');
    var copyReqBtn = document.getElementById('auditCopyRequestBtn');
    if (refreshBtn) refreshBtn.addEventListener('click', load);
    if (clearBtn) clearBtn.addEventListener('click', clearFilters);
    if (search) search.addEventListener('input', applyFilter);
    if (methodFilter) methodFilter.addEventListener('change', applyFilter);
    if (dateFilter) dateFilter.addEventListener('change', applyFilter);
    if (userFilter) userFilter.addEventListener('change', applyFilter);
    if (closeBtn) closeBtn.addEventListener('click', closeDetail);
    if (modal) modal.addEventListener('click', function (e) { if (e.target === modal) closeDetail(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal && modal.classList.contains('is-open')) closeDetail();
    });
    if (copyResBtn) copyResBtn.addEventListener('click', function () { copyText(currentPayload); });
    if (copyReqBtn) copyReqBtn.addEventListener('click', function () { copyText(currentRequest); });

    // Use server-rendered rows immediately, then hydrate from API (safe JSON encoder).
    bindRowClicks();
    applyFilter();
    load();
})();
</script>
