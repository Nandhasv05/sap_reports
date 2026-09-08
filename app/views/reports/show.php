<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : Show view
 */
/*
 * Get the query string
 */
$qs = static function (array $extra = []) use ($report, $search, $from, $to, $page, $perPage): string {
    return http_build_query(array_merge([
        'q' => $search,
        'from' => $from,
        'to' => $to,
        'page' => $page,
        'per_page' => $perPage,
    ], $extra));
};
?>
<a class="back" href="<?= e(sap_reports_evol_url('portal_dashboard.php')) ?>">
    <i class="fas fa-arrow-left"></i>
    All SAP Reports
</a>
<h1><?= e($item['title']) ?></h1>
<p class="lede"><?= e($item['blurb']) ?></p>

<?php if ($loadError !== ''): ?>
    <div class="alert"><?= e($loadError) ?></div>
<?php endif; ?>

<form class="card" method="get" action="<?= e(url($report)) ?>">
    <div class="toolbar">
        <div>
            <label for="q">Search</label>
            <input id="q" name="q" value="<?= e($search) ?>" placeholder="Order, style, material, status">
        </div>
        <div>
            <label for="from">From</label>
            <input id="from" type="date" name="from" value="<?= e($from) ?>">
        </div>
        <div>
            <label for="to">To</label>
            <input id="to" type="date" name="to" value="<?= e($to) ?>">
        </div>
        <button class="btn" type="submit"><i class="fas fa-search"></i> Run</button>
        <a class="btn btn-ghost" href="<?= e(url($report) . '?' . $qs(['export' => 'csv'])) ?>"><i class="fas fa-file-download"></i> CSV</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <?php if ($report === 'division'): ?>
                    <tr><th>Division</th><th class="num">Lines</th><th class="num">Qty</th><th class="num">Net Amount</th></tr>
                <?php else: ?>
                    <tr>
                        <th>Sales Order</th>
                        <th>Line</th>
                        <th>Style / Product</th>
                        <th>Material</th>
                        <?php if ($report === 'fabric' || $report === 'trims'): ?>
                            <th>Item Type</th>
                        <?php endif; ?>
                        <th>Division</th>
                        <th>Date</th>
                        <th class="num">Qty</th>
                        <th class="num">Net Amount</th>
                        <th>Status</th>
                    </tr>
                <?php endif; ?>
            </thead>
            <tbody>
                <?php if ($records === []): ?>
                    <tr><td colspan="8">No records for this filter.</td></tr>
                <?php elseif ($report === 'division'): ?>
                    <?php foreach ($records as $row): ?>
                        <tr>
                            <td><span class="pill"><?= e((string) ($row['division'] ?? '—')) ?></span></td>
                            <td class="num"><?= e($model->num($row['lines'] ?? 0)) ?></td>
                            <td class="num"><?= e($model->num($row['qty'] ?? 0)) ?></td>
                            <td class="num"><?= e($model->money($row['net_amount'] ?? 0)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?php foreach ($records as $row): ?>
                        <tr>
                            <td><?= e((string) ($row['sales_order'] ?? '')) ?></td>
                            <td><?= e((string) ($row['line_item'] ?? '')) ?></td>
                            <td>
                                <?= e((string) ($row['style'] ?? '')) ?>
                            </td>
                            <td><?= e((string) ($row['material'] ?? '')) ?></td>
                            <?php if ($report === 'fabric' || $report === 'trims'): ?>
                                <td><?= e((string) ($row['item_type'] ?? $row['item_category'] ?? '—')) ?></td>
                            <?php endif; ?>
                            <td><span class="pill"><?= e((string) ($row['division'] ?? '—')) ?></span></td>
                            <td><?= e((string) ($row['date'] ?? '')) ?></td>
                            <td class="num"><?= e((string) ($row['qty'] ?? '')) ?></td>
                            <td class="num"><?= e($model->money($row['net_amount'] ?? 0)) ?></td>
                            <td><span class="pill status"><?= e((string) ($row['status'] ?? '')) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="footer">
        <div>
            Showing <?= e($model->num($start)) ?>–<?= e($model->num($end)) ?> of <?= e($model->num($total)) ?>
            <?php if ($page > 1): ?>
                <a class="btn btn-ghost" href="<?= e(url($report) . '?' . $qs(['page' => $page - 1])) ?>">Previous</a>
            <?php endif; ?>
            <?php if ($page < $pages): ?>
                <a class="btn btn-ghost" href="<?= e(url($report) . '?' . $qs(['page' => $page + 1])) ?>">Next</a>
            <?php endif; ?>
        </div>
        <label class="per-page">
            Per page
            <select name="per_page" onchange="this.form.submit()">
                <?php foreach ([10, 25, 50, 100] as $n): ?>
                    <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?> / page</option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
</form>
