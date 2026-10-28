<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 28/10/2026
 * DESCRIPTION : Full-page API Audit Log (admin) — row click opens detail modal
 */
$records = is_array($records ?? null) ? $records : [];
$catalogHome = url('/');
$auditApiUrl = url('api/audit-log');
$logoUrl = sap_reports_logo_url();
$total = (int) ($total ?? count($records));

$fmtWhen = static function ($v): string {
    if ($v === null || $v === '') {
        return '—';
    }
    try {
        $dt = $v instanceof DateTimeInterface ? $v : new DateTimeImmutable((string) $v);
        return $dt->format('d/m/Y, h:i:s a');
    } catch (Throwable $e) {
        return (string) $v;
    }
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
                <h1>Audit Log</h1>
            </div>
        </div>
        <div class="audit-topbar-actions">
            <a class="hub-admin-btn hub-admin-btn-ghost" href="<?= e($catalogHome) ?>">
                <span class="material-icons-round">arrow_back</span>
                Back to Card
            </a>
            <button type="button" class="hub-admin-btn hub-admin-btn-primary" id="auditPageRefreshBtn">
                <span class="material-icons-round">refresh</span>
                Refresh
            </button>
            <?php require base_path('app/views/partials/header_nav_right.php'); ?>
        </div>
    </header>

    <main class="audit-main">
        <div class="audit-toolbar">
            <div class="audit-toolbar-left">
                <span class="audit-count-pill"><b id="auditPageCount"><?= e((string) $total) ?></b> entries</span>
                <span class="audit-toolbar-hint">Click a row to view full API details</span>
            </div>
            <label class="audit-search">
                <span class="material-icons-round">search</span>
                <input type="search" id="auditPageSearch" placeholder="Filter by user, URL, status, IP…" autocomplete="off">
            </label>
        </div>

        <div class="audit-table-panel">
            <div class="audit-table-scroll">
                <table class="audit-page-table" id="auditPageTable">
                    <thead>
                        <tr>
                            <th>Date / Time</th>
                            <th>Username</th>
                            <th>User ID</th>
                            <th>URL</th>
                            <th>Status</th>
                            <th>IP</th>
                            <th class="audit-col-action">Details</th>
                        </tr>
                    </thead>
                    <tbody id="auditPageBody">
                        <?php if (!$records): ?>
                            <tr><td colspan="7" class="hub-audit-empty">No API audit rows yet. Call a report data API to create entries.</td></tr>
                        <?php else: ?>
                            <?php foreach ($records as $row):
                                $status = (int) ($row['status'] ?? 0);
                                $statusCls = ($status >= 200 && $status < 300) ? 'is-ok' : (($status >= 400) ? 'is-bad' : '');
                                $payload = (string) ($row['response_payload'] ?? '');
                                $urlFull = (string) ($row['url'] ?? '');
                                $hay = strtolower(implode(' ', [
                                    (string) ($row['username'] ?? ''),
                                    (string) ($row['user_id'] ?? ''),
                                    $urlFull,
                                    (string) ($row['status'] ?? ''),
                                    (string) ($row['ip'] ?? ''),
                                ]));
                                $jsonRow = json_encode([
                                    'id' => $row['id'] ?? null,
                                    'created_at' => $row['created_at'] ?? null,
                                    'username' => $row['username'] ?? '',
                                    'user_id' => $row['user_id'] ?? '',
                                    'url' => $urlFull,
                                    'status' => $status,
                                    'ip' => $row['ip'] ?? '',
                                    'response_payload' => $payload,
                                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP);
                            ?>
                            <tr class="audit-row" data-hay="<?= e($hay) ?>" data-audit="<?= e($jsonRow) ?>" tabindex="0" role="button" aria-label="Open audit detail">
                                <td class="audit-col-when"><?= e($fmtWhen($row['created_at'] ?? null)) ?></td>
                                <td><?= e((string) ($row['username'] ?? '—')) ?></td>
                                <td><?= e((string) ($row['user_id'] ?? '—')) ?></td>
                                <td class="hub-audit-url" title="<?= e($urlFull) ?>"><?= e($urlFull !== '' ? $urlFull : '—') ?></td>
                                <td><span class="hub-audit-status <?= e($statusCls) ?>"><?= e((string) $status) ?></span></td>
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
                <span class="hub-modal-kicker">API audit detail</span>
                <h3 id="auditDetailTitle">Request details</h3>
            </div>
            <button type="button" class="hub-modal-close" id="auditDetailClose" aria-label="Close detail">
                <span class="material-icons-round">close</span>
            </button>
        </div>
        <div class="hub-modal-body audit-detail-body">
            <div class="audit-detail-grid" id="auditDetailMeta"></div>
            <div class="audit-detail-payload-head">
                <h4>Response payload</h4>
                <button type="button" class="hub-admin-btn hub-admin-btn-ghost" id="auditCopyPayloadBtn" title="Copy payload">
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
    var countEl = document.getElementById('auditPageCount');
    var search = document.getElementById('auditPageSearch');
    var modal = document.getElementById('auditDetailModal');
    var metaEl = document.getElementById('auditDetailMeta');
    var payloadEl = document.getElementById('auditDetailPayload');
    var titleEl = document.getElementById('auditDetailTitle');
    var currentPayload = '';

    function esc(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function fmtWhen(iso) {
        if (!iso) return '—';
        try {
            var d = new Date(iso);
            if (isNaN(d.getTime())) return String(iso);
            return d.toLocaleString('en-IN');
        } catch (e) { return String(iso); }
    }
    function statusClass(status) {
        var n = Number(status);
        if (n >= 200 && n < 300) return 'is-ok';
        if (n >= 400) return 'is-bad';
        return '';
    }
    function prettyPayload(raw) {
        var text = String(raw == null || raw === '' ? '' : raw);
        if (!text) return '—';
        try {
            return JSON.stringify(JSON.parse(text), null, 2);
        } catch (e) {
            return text;
        }
    }
    function openDetail(row, tr) {
        if (!modal || !row) return;
        currentPayload = prettyPayload(row.response_payload);
        if (titleEl) {
            titleEl.textContent = 'API #' + (row.id != null ? row.id : '—') + ' · Status ' + (row.status || '—');
        }
        if (metaEl) {
            var fields = [
                ['Date / Time', fmtWhen(row.created_at)],
                ['Username', row.username || '—'],
                ['User ID', row.user_id || '—'],
                ['Status', String(row.status != null ? row.status : '—')],
                ['IP', row.ip || '—'],
                ['URL', row.url || '—']
            ];
            metaEl.innerHTML = fields.map(function (pair) {
                var isUrl = pair[0] === 'URL';
                var isStatus = pair[0] === 'Status';
                var valueHtml = isStatus
                    ? '<span class="hub-audit-status ' + statusClass(pair[1]) + '">' + esc(pair[1]) + '</span>'
                    : esc(pair[1]);
                return '<div class="audit-detail-item' + (isUrl ? ' is-wide' : '') + '">' +
                    '<dt>' + esc(pair[0]) + '</dt>' +
                    '<dd' + (isUrl ? ' class="is-mono"' : '') + '>' + valueHtml + '</dd>' +
                    '</div>';
            }).join('');
        }
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
        try {
            return JSON.parse(tr.getAttribute('data-audit') || 'null');
        } catch (e) {
            return null;
        }
    }
    function bindRowClicks() {
        if (!body) return;
        body.querySelectorAll('tr.audit-row').forEach(function (tr) {
            tr.onclick = function () {
                var row = parseRow(tr);
                if (!row) return;
                openDetail(row, tr);
            };
            tr.onkeydown = function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    tr.click();
                }
            };
        });
    }
    function render(rows) {
        if (!body) return;
        if (!rows.length) {
            body.innerHTML = '<tr><td colspan="7" class="hub-audit-empty">No API audit rows yet. Call a report data API to create entries.</td></tr>';
            if (countEl) countEl.textContent = '0';
            return;
        }
        if (countEl) countEl.textContent = String(rows.length);
        body.innerHTML = rows.map(function (row) {
            var payload = row.response_payload || '';
            var url = row.url || '';
            var hay = [row.username, row.user_id, url, row.status, row.ip].join(' ').toLowerCase();
            var data = esc(JSON.stringify({
                id: row.id,
                created_at: row.created_at,
                username: row.username || '',
                user_id: row.user_id || '',
                url: url,
                status: row.status,
                ip: row.ip || '',
                response_payload: payload
            }));
            return '<tr class="audit-row" data-hay="' + esc(hay) + '" data-audit="' + data + '" tabindex="0" role="button" aria-label="Open audit detail">' +
                '<td class="audit-col-when">' + esc(fmtWhen(row.created_at)) + '</td>' +
                '<td>' + esc(row.username || '—') + '</td>' +
                '<td>' + esc(row.user_id || '—') + '</td>' +
                '<td class="hub-audit-url" title="' + esc(url) + '">' + esc(url || '—') + '</td>' +
                '<td><span class="hub-audit-status ' + statusClass(row.status) + '">' + esc(row.status) + '</span></td>' +
                '<td class="audit-col-ip">' + esc(row.ip || '—') + '</td>' +
                '<td class="audit-col-action"><span class="audit-view-btn">View <span class="material-icons-round">chevron_right</span></span></td>' +
                '</tr>';
        }).join('');
        bindRowClicks();
        applyFilter();
    }
    function applyFilter() {
        if (!body || !search) return;
        var q = String(search.value || '').trim().toLowerCase();
        var visible = 0;
        body.querySelectorAll('tr.audit-row').forEach(function (tr) {
            var show = !q || (tr.getAttribute('data-hay') || '').indexOf(q) !== -1;
            tr.hidden = !show;
            if (show) visible++;
        });
        if (countEl && body.querySelector('tr.audit-row')) countEl.textContent = String(visible);
    }
    function load() {
        if (!body || !apiUrl) return;
        body.innerHTML = '<tr><td colspan="7" class="hub-audit-empty">Loading…</td></tr>';
        fetch(apiUrl + (apiUrl.indexOf('?') >= 0 ? '&' : '?') + 'limit=200&_=' + Date.now(), {
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
                body.innerHTML = '<tr><td colspan="7" class="hub-audit-empty">Could not load audit log: ' + esc(err.message || err) + '</td></tr>';
            });
    }

    document.getElementById('auditPageRefreshBtn')?.addEventListener('click', load);
    search?.addEventListener('input', applyFilter);
    document.getElementById('auditDetailClose')?.addEventListener('click', closeDetail);
    modal?.addEventListener('click', function (e) {
        if (e.target === modal) closeDetail();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal && modal.classList.contains('is-open')) closeDetail();
    });
    document.getElementById('auditCopyPayloadBtn')?.addEventListener('click', function () {
        if (!currentPayload || currentPayload === '—') return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(currentPayload).catch(function () {});
        }
    });
    bindRowClicks();
})();
</script>
