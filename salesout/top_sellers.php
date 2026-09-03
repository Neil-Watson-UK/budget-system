<?php
// top_sellers.php - Top SKUs or product series by quantity or value, with distributor / reseller / AM filters
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=' . urlencode('salesout/top_sellers.php'));
    exit;
}

$pdo = getDBConnection();

$rawFrom = trim($_GET['date_from'] ?? '');
$rawTo = trim($_GET['date_to'] ?? '');
$defaultFrom = date('Y-m-01', strtotime('-12 months'));
$defaultTo = date('Y-m-d');
$dateFrom = $defaultFrom;
$dateTo = $defaultTo;
if ($rawFrom !== '' && strtotime($rawFrom) !== false) {
    $dateFrom = date('Y-m-d', strtotime($rawFrom));
}
if ($rawTo !== '' && strtotime($rawTo) !== false) {
    $dateTo = date('Y-m-d', strtotime($rawTo));
}
if (strtotime($dateFrom) > strtotime($dateTo)) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$distributor = trim($_GET['distributor'] ?? '');
$resellerId = (int) ($_GET['reseller'] ?? 0);
$filterOwner = trim($_GET['owner'] ?? '');
$groupBy = $_GET['group_by'] ?? 'sku';
if (!in_array($groupBy, ['sku', 'series'], true)) {
    $groupBy = 'sku';
}
$rankBy = $_GET['rank_by'] ?? 'value';
if (!in_array($rankBy, ['qty', 'value'], true)) {
    $rankBy = 'value';
}
$topN = (int) ($_GET['top_n'] ?? 50);
if ($topN < 10) {
    $topN = 10;
}
if ($topN > 500) {
    $topN = 500;
}
$exportCsv = isset($_GET['export']) && $_GET['export'] === 'csv';

$valueMode = getSalesOutValueMode();
$valueKey = getSalesOutValueCompareKey($valueMode);
$valueLabels = getSalesOutValueModeLabels();
$valueLabelShort = $valueLabels[$valueMode] ?? 'Value';

$hasOwnerColumn = false;
try {
    $hasOwnerColumn = (bool) $pdo->query("SHOW COLUMNS FROM vendors LIKE 'Owner_Full_Name__c'")->fetch();
} catch (PDOException $e) {
    /* ignore */
}

$params = [$dateFrom, $dateTo];
$where = 's.report_date BETWEEN ? AND ? AND s.sku IS NOT NULL AND TRIM(s.sku) != \'\'';
if ($distributor !== '') {
    $where .= ' AND s.distributor_name = ?';
    $params[] = $distributor;
}
if ($resellerId > 0) {
    $where .= ' AND s.matched_vendor_id = ?';
    $params[] = $resellerId;
}
if ($hasOwnerColumn && $filterOwner !== '') {
    $where .= ' AND s.matched_vendor_id IN (SELECT id FROM vendors WHERE TRIM(COALESCE(Owner_Full_Name__c,\'\')) = ?)';
    $params[] = $filterOwner;
}

$orderQty = 'total_qty DESC, ' . $valueKey . ' DESC';
$orderValue = $valueKey . ' DESC, total_qty DESC';
$orderBy = ($rankBy === 'qty') ? $orderQty : $orderValue;

$distributorsList = [];
$resellersList = [];
$accountOwners = [];
$rows = [];
$dbError = null;

try {
    $distributorsList = $pdo->query(
        "SELECT DISTINCT distributor_name FROM sales_out_raw WHERE distributor_name IS NOT NULL AND TRIM(distributor_name) != '' ORDER BY distributor_name"
    )->fetchAll(PDO::FETCH_COLUMN);

    $resellersList = $pdo->query(
        "SELECT v.id, v.vendor_name FROM sales_out_raw s
        INNER JOIN vendors v ON s.matched_vendor_id = v.id
        GROUP BY v.id, v.vendor_name ORDER BY v.vendor_name"
    )->fetchAll(PDO::FETCH_ASSOC);

    if ($hasOwnerColumn) {
        $accountOwners = $pdo->query(
            "SELECT DISTINCT TRIM(Owner_Full_Name__c) as owner_name FROM vendors
             WHERE Owner_Full_Name__c IS NOT NULL AND TRIM(Owner_Full_Name__c) != '' ORDER BY owner_name"
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    if ($groupBy === 'sku') {
        $sql = "
            SELECT
                TRIM(REPLACE(MAX(s.sku),' ','')) as sku_norm,
                MAX(COALESCE(p.sku, s.sku)) as sku_display,
                MAX(COALESCE(p.product_name, s.product_name, s.sku)) as product_name,
                COALESCE(MAX(NULLIF(TRIM(p.product_series), '')), MAX(NULLIF(TRIM(p.product_line), '')), '') as product_series,
                SUM(s.quantity) as total_qty,
                COALESCE(SUM(s.total_value), 0) as dist_reported,
                COALESCE(SUM(s.quantity * p.trade_price), 0) as at_trade,
                COALESCE(SUM(s.quantity * p.msrp), 0) as at_msrp
            FROM sales_out_raw s
            LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
            WHERE $where
            GROUP BY TRIM(REPLACE(s.sku,' ',''))
            ORDER BY $orderBy
            LIMIT " . (int) $topN;
    } else {
        $seriesExpr = "COALESCE(NULLIF(TRIM(p.product_series), ''), NULLIF(TRIM(p.product_line), ''), 'Unknown series')";
        $sql = "
            SELECT
                $seriesExpr AS series_label,
                COUNT(DISTINCT TRIM(REPLACE(s.sku,' ',''))) as sku_distinct_count,
                SUM(s.quantity) as total_qty,
                COALESCE(SUM(s.total_value), 0) as dist_reported,
                COALESCE(SUM(s.quantity * p.trade_price), 0) as at_trade,
                COALESCE(SUM(s.quantity * p.msrp), 0) as at_msrp
            FROM sales_out_raw s
            LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
            WHERE $where
            GROUP BY $seriesExpr
            ORDER BY $orderBy
            LIMIT " . (int) $topN;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

// Query string preserving all filters for CSV download
$exportQuery = array_filter([
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'distributor' => $distributor,
    'reseller' => $resellerId > 0 ? (string)$resellerId : '',
    'owner' => $filterOwner,
    'group_by' => $groupBy,
    'rank_by' => $rankBy,
    'top_n' => (string) $topN,
    'export' => 'csv',
], static function ($v) {
    return $v !== null && $v !== '';
});
$csvDownloadHref = 'top_sellers.php?' . http_build_query($exportQuery);

if ($exportCsv && !$dbError && !headers_sent()) {
    $basename = $groupBy === 'sku' ? 'top_skus' : 'top_series';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $basename . '_' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    if ($groupBy === 'sku') {
        fputcsv($out, ['Rank', 'SKU', 'Product name', 'Product series', 'Qty', 'Distributor reported', 'Trade value', 'MSRP value']);
        $r = 0;
        foreach ($rows as $row) {
            $r++;
            fputcsv($out, [
                $r,
                $row['sku_display'] ?? '',
                $row['product_name'] ?? '',
                $row['product_series'] ?? '',
                (int) ($row['total_qty'] ?? 0),
                number_format((float) ($row['dist_reported'] ?? 0), 2, '.', ''),
                number_format((float) ($row['at_trade'] ?? 0), 2, '.', ''),
                number_format((float) ($row['at_msrp'] ?? 0), 2, '.', ''),
            ]);
        }
    } else {
        fputcsv($out, ['Rank', 'Product series', 'Distinct SKUs', 'Qty', 'Distributor reported', 'Trade value', 'MSRP value']);
        $r = 0;
        foreach ($rows as $row) {
            $r++;
            fputcsv($out, [
                $r,
                $row['series_label'] ?? '',
                (int) ($row['sku_distinct_count'] ?? 0),
                (int) ($row['total_qty'] ?? 0),
                number_format((float) ($row['dist_reported'] ?? 0), 2, '.', ''),
                number_format((float) ($row['at_trade'] ?? 0), 2, '.', ''),
                number_format((float) ($row['at_msrp'] ?? 0), 2, '.', ''),
            ]);
        }
    }
    fclose($out);
    exit;
}

require_once __DIR__ . '/header.php';

if (!empty($dbError)) {
    echo '<div class="container-xl py-4"><div class="alert alert-danger">' . htmlspecialchars($dbError) . '</div></div></div></div></body></html>';
    exit;
}

?>

<style>
.report-header { background: linear-gradient(135deg, #00353d 0%, #00a399 100%); color: white; padding: 2rem 0; margin-bottom: 1.5rem; border-radius: 10px; box-shadow: 0 4px 14px rgba(0, 163, 153, 0.25); }
.filter-card { background: white; border: 1px solid #D7D2CB; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 1.5rem; overflow: hidden; }
.filter-card .card-header { background: rgb(238, 239, 241); border-bottom: 1px solid #D7D2CB; padding: 1rem 1.25rem; font-weight: 600; color: #0f172a; }
.report-body { background: rgb(238, 239, 241); }
</style>

<div class="container-xl py-4 report-body">
    <div class="report-header">
        <div class="container-fluid">
            <h1 class="h2 mb-1"><i class="ti ti-trophy me-2"></i>Top Sellers</h1>
            <p class="mb-0 opacity-75">
                Rank products by quantity or value over a chosen period.
                Value columns follow your global setting (<?= htmlspecialchars($valueLabelShort) ?> for ranking when "Value" is selected).
                Filter by distributor, matched reseller account, or account manager where data allows.
            </p>
        </div>
    </div>

    <div class="filter-card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div><i class="ti ti-filter me-2"></i>Filters</div>
            <div class="d-flex flex-wrap gap-2">
                <a href="<?= htmlspecialchars('top_sellers.php?' . http_build_query([
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'group_by' => $groupBy,
                    'rank_by' => $rankBy,
                    'top_n' => $topN,
                    'export' => 'csv',
                ])) ?>" class="btn btn-sm btn-outline-secondary"><i class="ti ti-download me-1"></i>Export CSV</a>
                <a href="top_sellers.php" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </div>
        <div class="card-body">
            <form method="get" action="top_sellers.php" class="row g-3 align-items-end">
                <div class="col-md-3 col-lg-2">
                    <label class="form-label">Start date</label>
                    <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
                </div>
                <div class="col-md-3 col-lg-2">
                    <label class="form-label">End date</label>
                    <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
                </div>
                <div class="col-md-6 col-lg-3">
                    <label class="form-label">Distributor</label>
                    <select name="distributor" class="form-select">
                        <option value="">All distributors</option>
                        <?php foreach ($distributorsList as $dn): ?>
                            <option value="<?= htmlspecialchars($dn) ?>" <?= $distributor === $dn ? 'selected' : '' ?>><?= htmlspecialchars($dn) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 col-lg-3">
                    <label class="form-label">Reseller (matched account)</label>
                    <select name="reseller" class="form-select">
                        <option value="0">All resellers</option>
                        <?php foreach ($resellersList as $v): ?>
                            <option value="<?= (int) $v['id'] ?>" <?= $resellerId === (int) $v['id'] ? 'selected' : '' ?>><?= htmlspecialchars($v['vendor_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($hasOwnerColumn): ?>
                <div class="col-md-6 col-lg-3">
                    <label class="form-label">Account manager</label>
                    <select name="owner" class="form-select">
                        <option value="">All managers</option>
                        <?php foreach ($accountOwners as $o): ?>
                            <option value="<?= htmlspecialchars($o) ?>" <?= $filterOwner === $o ? 'selected' : '' ?>><?= htmlspecialchars($o) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Uses matched reseller accounts linked to Salesforce owner.</small>
                </div>
                <?php endif; ?>
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label">View by</label>
                    <select name="group_by" class="form-select">
                        <option value="sku" <?= $groupBy === 'sku' ? 'selected' : '' ?>>SKU</option>
                        <option value="series" <?= $groupBy === 'series' ? 'selected' : '' ?>>Product series</option>
                    </select>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label">Rank by</label>
                    <select name="rank_by" class="form-select">
                        <option value="qty" <?= $rankBy === 'qty' ? 'selected' : '' ?>>Quantity</option>
                        <option value="value" <?= $rankBy === 'value' ? 'selected' : '' ?>>Value (<?= htmlspecialchars($valueLabelShort) ?>)</option>
                    </select>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label">Top N</label>
                    <select name="top_n" class="form-select">
                        <?php foreach ([10, 25, 50, 100, 200, 500] as $n): ?>
                            <option value="<?= $n ?>" <?= $topN === $n ? 'selected' : '' ?>><?= $n ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary"><i class="ti ti-search me-1"></i>Apply</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($groupBy === 'series'): ?>
    <div class="alert alert-info mb-4">
        <strong>Product series</strong> uses <code>product_series</code> from the product master, then <code>product_line</code> if series is blank, otherwise “Unknown series”.
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><strong><?= $groupBy === 'sku' ? 'Top SKUs' : 'Top product series' ?></strong> · <?= htmlspecialchars($dateFrom) ?> to <?= htmlspecialchars($dateTo) ?></div>
        <div class="table-responsive">
            <table class="table table-striped table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <?php if ($groupBy === 'sku'): ?>
                            <th>SKU</th>
                            <th>Product</th>
                            <th>Series</th>
                        <?php else: ?>
                            <th>Series</th>
                            <th class="text-end">Distinct SKUs</th>
                        <?php endif; ?>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Dist. reported</th>
                        <th class="text-end">Trade</th>
                        <th class="text-end">MSRP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $rank = 0;
                    foreach ($rows as $row):
                        $rank++;
                        ?>
                        <tr>
                            <td><?= $rank ?></td>
                            <?php if ($groupBy === 'sku'): ?>
                                <td><a href="product_detail.php?sku=<?= urlencode((string)($row['sku_display'] ?? '')) ?>"><?= htmlspecialchars((string)($row['sku_display'] ?? '')) ?></a></td>
                                <td><?= htmlspecialchars((string)($row['product_name'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string)($row['product_series'] ?? '')) ?></td>
                            <?php else: ?>
                                <td><?= htmlspecialchars((string)($row['series_label'] ?? '')) ?></td>
                                <td class="text-end"><?= (int)($row['sku_distinct_count'] ?? 0) ?></td>
                            <?php endif; ?>
                            <td class="text-end"><?= number_format((int)($row['total_qty'] ?? 0)) ?></td>
                            <td class="text-end"><?= number_format((float)($row['dist_reported'] ?? 0), 2) ?></td>
                            <td class="text-end"><?= number_format((float)($row['at_trade'] ?? 0), 2) ?></td>
                            <td class="text-end"><?= number_format((float)($row['at_msrp'] ?? 0), 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="<?= $groupBy === 'sku' ? 8 : 7 ?>" class="text-muted text-center py-4">No rows for these filters.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div></div></body></html>
