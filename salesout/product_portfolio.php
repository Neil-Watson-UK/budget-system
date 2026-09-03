<?php
// product_portfolio.php - Boston Matrix: products by growth vs share (Stars, Cash Cows, Question Marks, Dogs)
session_start();

function _median(array $arr) {
    if (empty($arr)) return 0;
    $arr = array_values($arr);
    sort($arr);
    $c = count($arr);
    return $c % 2 ? $arr[(int)($c / 2)] : ($arr[$c / 2 - 1] + $arr[$c / 2]) / 2;
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=' . urlencode('salesout/product_portfolio.php'));
    exit;
}

$pdo = getDBConnection();

$dateFrom = $_GET['date_from'] ?? date('Y-m-01', strtotime('-24 months'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$distributor = $_GET['distributor'] ?? '';
$reseller = $_GET['reseller'] ?? ''; // matched vendor id
$groupBy = $_GET['group_by'] ?? 'product_name'; // product_name | category | product_line | sku

$distributors = $pdo->query("SELECT DISTINCT distributor_name FROM sales_out_raw ORDER BY distributor_name")->fetchAll(PDO::FETCH_COLUMN);
$resellers = $pdo->query("
    SELECT v.id, v.vendor_name
    FROM vendors v
    INNER JOIN sales_out_raw s ON s.matched_vendor_id = v.id
    GROUP BY v.id, v.vendor_name
    ORDER BY v.vendor_name
")->fetchAll(PDO::FETCH_ASSOC);

$products = [];
$quadrants = ['stars' => [], 'cash_cows' => [], 'question_marks' => [], 'dogs' => []];
$dbError = null;
$dateAdjusted = false;
$rowsInRange = 0;
$grandTotalReal = 0;
$midDate = '';

$hasImageCol = false;
try {
    $hasImageCol = (bool)$pdo->query("SHOW COLUMNS FROM sales_out_products LIKE 'image_thumb'")->fetch();
} catch (PDOException $e) { /* ignore */ }

try {
    $tsFrom = strtotime($dateFrom);
    $tsTo = strtotime($dateTo);
    $midTs = (int) (($tsFrom + $tsTo) / 2);
    $midDate = date('Y-m-d', $midTs);

    $baseWhere = "s.report_date BETWEEN ? AND ?";
    $params = [$dateFrom, $dateTo];
    if (!empty($distributor)) {
        $baseWhere .= " AND s.distributor_name = ?";
        $params[] = $distributor;
    }
    if ($reseller !== '' && is_numeric($reseller)) {
        $baseWhere .= " AND s.matched_vendor_id = ?";
        $params[] = (int) $reseller;
    }

    $groupCol = ($groupBy === 'product_name') ? "COALESCE(NULLIF(TRIM(s.product_name), ''), COALESCE(NULLIF(TRIM(p.product_name), ''), s.sku), 'Unnamed')"
        : (($groupBy === 'product_line') ? "COALESCE(p.product_line, p.product_category, 'Other')"
        : (($groupBy === 'sku') ? "COALESCE(NULLIF(TRIM(p.sku), ''), s.sku)" : "COALESCE(p.product_category, p.product_line, 'Uncategorised')"));

    // Total for share calculation (and diagnostic: check if report_date is the issue)
    $totalStmt = $pdo->prepare("
        SELECT COALESCE(SUM(s.total_value), 0) as total, COUNT(*) as row_count
        FROM sales_out_raw s
        LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
        WHERE $baseWhere
    ");
    $totalStmt->execute($params);
    $totalRow = $totalStmt->fetch(PDO::FETCH_ASSOC);
    $grandTotal = (float) ($totalRow['total'] ?? 0);
    $rowsInRange = (int) ($totalRow['row_count'] ?? 0);

    // If no rows in date range, auto-adjust to actual data range (or report date column may be wrong)
    if ($rowsInRange === 0) {
        $checkStmt = $pdo->query("SELECT COUNT(*) as total_rows, COUNT(report_date) as with_date, MIN(report_date) as min_d, MAX(report_date) as max_d FROM sales_out_raw");
        $check = $checkStmt->fetch(PDO::FETCH_ASSOC);
        $totalRows = (int) ($check['total_rows'] ?? 0);
        $withDate = (int) ($check['with_date'] ?? 0);
        if ($totalRows > 0 && $withDate === 0) {
            $dbError = 'Your sales data has no report_date set. The import may need the Date column mapped. Check Import column mapping.';
        } elseif ($withDate > 0 && !empty($check['min_d']) && !empty($check['max_d'])) {
            $dateFrom = $check['min_d'];
            $dateTo = $check['max_d'];
            $dateAdjusted = true;
            $tsFrom = strtotime($dateFrom);
            $tsTo = strtotime($dateTo);
            $midTs = (int) (($tsFrom + $tsTo) / 2);
            $midDate = date('Y-m-d', $midTs);
            $params = [$dateFrom, $dateTo];
            if (!empty($distributor)) $params[] = $distributor;
            if ($reseller !== '' && is_numeric($reseller)) $params[] = (int) $reseller;
            $totalStmt->execute($params);
            $totalRow = $totalStmt->fetch(PDO::FETCH_ASSOC);
            $grandTotal = (float) ($totalRow['total'] ?? 0);
            $rowsInRange = (int) ($totalRow['row_count'] ?? 0);
        }
    }
    $grandTotalReal = $grandTotal;
    if ($grandTotal <= 0) $grandTotal = 1;

    // Prior half and recent half value per product
    // Param order: WHERE params first (dateFrom, dateTo, distributor?), then midDate for prior, midDate for recent
    $imgSelect = $hasImageCol ? ", MAX(p.image_thumb) as image_thumb" : ", NULL as image_thumb";
    $stmt = $pdo->prepare("
        SELECT product_key, product_name, prior_val, recent_val, product_sku, image_thumb
        FROM (
            SELECT $groupCol as product_key,
                MAX(COALESCE(p.product_name, s.product_name)) as product_name,
                SUM(CASE WHEN s.report_date < " . $pdo->quote($midDate) . " THEN COALESCE(s.total_value, 0) ELSE 0 END) as prior_val,
                SUM(CASE WHEN s.report_date >= " . $pdo->quote($midDate) . " THEN COALESCE(s.total_value, 0) ELSE 0 END) as recent_val,
                MAX(COALESCE(p.sku, s.sku)) as product_sku $imgSelect
            FROM sales_out_raw s
            LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
            WHERE $baseWhere
            GROUP BY $groupCol
        ) sub
        WHERE (prior_val + recent_val) > 0
        ORDER BY (prior_val + recent_val) DESC
    ");
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Compute share and growth for each product
    foreach ($products as &$p) {
        $total = (float) ($p['prior_val'] ?? 0) + (float) ($p['recent_val'] ?? 0);
        $prior = (float) ($p['prior_val'] ?? 0);
        $recent = (float) ($p['recent_val'] ?? 0);
        $p['total_value'] = $total;
        $p['share_pct'] = $total / $grandTotal * 100;
        $p['growth_pct'] = $prior > 0 ? (($recent - $prior) / $prior * 100) : ($recent > 0 ? 100 : 0);
    }
    unset($p);
    $quadrants = ['stars' => [], 'cash_cows' => [], 'question_marks' => [], 'dogs' => []];

    // Relative to our portfolio: use median as threshold (balanced 2x2)
    $shares = array_column($products, 'share_pct');
    $growths = array_column($products, 'growth_pct');
    $medianShare = !empty($shares) ? _median($shares) : 0;
    $medianGrowth = !empty($growths) ? _median($growths) : 0;

    foreach ($products as &$p) {
        $p['quadrant'] = 'dogs';
        if ($p['share_pct'] >= $medianShare && $p['growth_pct'] >= $medianGrowth) $p['quadrant'] = 'stars';
        elseif ($p['share_pct'] >= $medianShare && $p['growth_pct'] < $medianGrowth) $p['quadrant'] = 'cash_cows';
        elseif ($p['share_pct'] < $medianShare && $p['growth_pct'] >= $medianGrowth) $p['quadrant'] = 'question_marks';
        else $p['quadrant'] = 'dogs';
        $quadrants[$p['quadrant']][] = $p;
    }
    unset($p);

} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

$medianShare = $medianShare ?? 0;
$medianGrowth = $medianGrowth ?? 0;

$chartData = [];
foreach ($products as $p) {
    $chartData[] = [
        'x' => round($p['share_pct'] ?? 0, 2),
        'y' => round($p['growth_pct'] ?? 0, 2),
        'name' => $p['product_key'] ?? '',
    ];
}

$pageTitle = 'Product Portfolio';
require_once __DIR__ . '/header.php';
?>
<style>
.portfolio-header { background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 50%, #0ea5e9 100%); color: white; padding: 2rem 0; margin-bottom: 1.5rem; border-radius: 10px; box-shadow: 0 4px 14px rgba(37, 99, 235, 0.25); }
.quadrant-card { border-radius: 12px; min-height: 200px; }
.quadrant-stars { border: 2px solid #f39c12; background: rgba(243,156,18,0.08); }
.quadrant-cash { border: 2px solid #2ecc71; background: rgba(46,204,113,0.08); }
.quadrant-question { border: 2px solid #3498db; background: rgba(52,152,219,0.08); }
.quadrant-dogs { border: 2px solid #95a5a6; background: rgba(149,165,166,0.08); }
.prod-thumb { width: 36px; height: 36px; object-fit: contain; border-radius: 6px; background: #f1f5f9; }
</style>

<div class="container-xl py-4">
    <h1><i class="ti ti-chart-bubble me-2"></i>Product Portfolio</h1>
    <p class="text-muted">Boston Matrix view: products by market share vs growth (relative to your portfolio). Quadrants use median share and median growth as thresholds so each section is balanced.</p>

    <?php if ($dbError): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($dbError) ?></div>
    <?php endif; ?>
    <?php if (!empty($dateAdjusted)): ?>
    <div class="alert alert-info"><i class="ti ti-info-circle me-2"></i>Date range auto-adjusted to match your data: <?= htmlspecialchars($dateFrom) ?> – <?= htmlspecialchars($dateTo) ?></div>
    <?php endif; ?>
    <?php if (isset($rowsInRange) && $rowsInRange > 0): ?>
    <div class="alert alert-secondary py-2"><small><strong><?= number_format($rowsInRange) ?></strong> rows, <strong>£<?= number_format($grandTotalReal ?? $grandTotal ?? 0, 0) ?></strong> total in range. Midpoint for growth: <?= htmlspecialchars($midDate ?? '') ?></small></div>
    <?php endif; ?>
    <?php if (!$dbError): ?>

    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">From</label>
                    <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">To</label>
                    <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Distributor</label>
                    <select name="distributor" class="form-select">
                        <option value="">All</option>
                        <?php foreach ($distributors as $d): ?>
                        <option value="<?= htmlspecialchars($d) ?>" <?= $distributor === $d ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Reseller (vendor)</label>
                    <select name="reseller" class="form-select">
                        <option value="">All</option>
                        <?php foreach ($resellers as $r): ?>
                        <option value="<?= (int)$r['id'] ?>" <?= $reseller === (string)$r['id'] ? 'selected' : '' ?>><?= htmlspecialchars($r['vendor_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Group by</label>
                    <select name="group_by" class="form-select">
                        <option value="product_name" <?= $groupBy === 'product_name' ? 'selected' : '' ?>>Product name</option>
                        <option value="category" <?= $groupBy === 'category' ? 'selected' : '' ?>>Category</option>
                        <option value="product_line" <?= $groupBy === 'product_line' ? 'selected' : '' ?>>Product Line</option>
                        <option value="sku" <?= $groupBy === 'sku' ? 'selected' : '' ?>>SKU</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary">Apply</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-lg-6">
            <div class="quadrant-card p-4 quadrant-stars">
                <h5 class="text-warning"><i class="ti ti-star me-1"></i> Stars</h5>
                <small class="text-muted">Above median share, above median growth</small>
                <ul class="list-unstyled mt-2 mb-0">
                    <?php foreach (array_slice($quadrants['stars'], 0, 8) as $q): ?>
                    <li class="d-flex align-items-center gap-2 mb-2">
                        <?php if (!empty($q['image_thumb'])): ?><img src="<?= htmlspecialchars($q['image_thumb']) ?>" alt="" class="prod-thumb flex-shrink-0" loading="lazy"><?php endif; ?>
                        <span><strong><a href="product_detail.php?sku=<?= urlencode($q['product_sku'] ?? $q['product_key']) ?>" class="text-decoration-none"><?= htmlspecialchars($q['product_key']) ?></a></strong> £<?= number_format($q['total_value'], 0) ?> · <?= $q['growth_pct'] >= 0 ? '+' : '' ?><?= number_format($q['growth_pct'], 1) ?>%</span>
                    </li>
                    <?php endforeach; ?>
                    <?php if (empty($quadrants['stars'])): ?><li class="text-muted">None</li><?php endif; ?>
                </ul>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="quadrant-card p-4 quadrant-cash">
                <h5 class="text-success"><i class="ti ti-cash me-1"></i> Cash Cows</h5>
                <small class="text-muted">Above median share, below median growth</small>
                <ul class="list-unstyled mt-2 mb-0">
                    <?php foreach (array_slice($quadrants['cash_cows'], 0, 8) as $q): ?>
                    <li class="d-flex align-items-center gap-2 mb-2">
                        <?php if (!empty($q['image_thumb'])): ?><img src="<?= htmlspecialchars($q['image_thumb']) ?>" alt="" class="prod-thumb flex-shrink-0" loading="lazy"><?php endif; ?>
                        <span><strong><a href="product_detail.php?sku=<?= urlencode($q['product_sku'] ?? $q['product_key']) ?>" class="text-decoration-none"><?= htmlspecialchars($q['product_key']) ?></a></strong> £<?= number_format($q['total_value'], 0) ?> · <?= $q['growth_pct'] >= 0 ? '+' : '' ?><?= number_format($q['growth_pct'], 1) ?>%</span>
                    </li>
                    <?php endforeach; ?>
                    <?php if (empty($quadrants['cash_cows'])): ?><li class="text-muted">None</li><?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
    <div class="row mb-4">
        <div class="col-lg-6">
            <div class="quadrant-card p-4 quadrant-question">
                <h5 class="text-info"><i class="ti ti-help-circle me-1"></i> Question Marks</h5>
                <small class="text-muted">Below median share, above median growth</small>
                <ul class="list-unstyled mt-2 mb-0">
                    <?php foreach (array_slice($quadrants['question_marks'], 0, 8) as $q): ?>
                    <li class="d-flex align-items-center gap-2 mb-2">
                        <?php if (!empty($q['image_thumb'])): ?><img src="<?= htmlspecialchars($q['image_thumb']) ?>" alt="" class="prod-thumb flex-shrink-0" loading="lazy"><?php endif; ?>
                        <span><strong><a href="product_detail.php?sku=<?= urlencode($q['product_sku'] ?? $q['product_key']) ?>" class="text-decoration-none"><?= htmlspecialchars($q['product_key']) ?></a></strong> £<?= number_format($q['total_value'], 0) ?> · <?= $q['growth_pct'] >= 0 ? '+' : '' ?><?= number_format($q['growth_pct'], 1) ?>%</span>
                    </li>
                    <?php endforeach; ?>
                    <?php if (empty($quadrants['question_marks'])): ?><li class="text-muted">None</li><?php endif; ?>
                </ul>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="quadrant-card p-4 quadrant-dogs">
                <h5 class="text-secondary"><i class="ti ti-trending-down me-1"></i> Dogs</h5>
                <small class="text-muted">Below median share, below median growth</small>
                <ul class="list-unstyled mt-2 mb-0">
                    <?php foreach (array_slice($quadrants['dogs'], 0, 8) as $q): ?>
                    <li class="d-flex align-items-center gap-2 mb-2">
                        <?php if (!empty($q['image_thumb'])): ?><img src="<?= htmlspecialchars($q['image_thumb']) ?>" alt="" class="prod-thumb flex-shrink-0" loading="lazy"><?php endif; ?>
                        <span><strong><a href="product_detail.php?sku=<?= urlencode($q['product_sku'] ?? $q['product_key']) ?>" class="text-decoration-none"><?= htmlspecialchars($q['product_key']) ?></a></strong> £<?= number_format($q['total_value'], 0) ?> · <?= $q['growth_pct'] >= 0 ? '+' : '' ?><?= number_format($q['growth_pct'], 1) ?>%</span>
                    </li>
                    <?php endforeach; ?>
                    <?php if (empty($quadrants['dogs'])): ?><li class="text-muted">None</li><?php endif; ?>
                </ul>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><i class="ti ti-chart-scatter me-2"></i>Growth vs Share (hover for details)</div>
        <div class="card-body">
            <div id="matrixChart" style="min-height: 400px;"></div>
            <p class="text-muted small mt-2 mb-0">X = Share of total sales (%). Y = Growth % (second half vs first half of range). Quadrants split at median (relative to your portfolio).</p>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header"><i class="ti ti-table me-2"></i>Full matrix (by <?= htmlspecialchars($groupBy) ?>)</div>
        <div class="table-responsive">
            <table class="table table-hover table-striped mb-0">
                <thead class="table-light">
                    <tr><th>Product</th><th>Quadrant</th><th>Share %</th><th>Growth %</th><th>Total Value</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $p): ?>
                    <tr>
                        <td class="d-flex align-items-center gap-2">
                            <?php if (!empty($p['image_thumb'])): ?><img src="<?= htmlspecialchars($p['image_thumb']) ?>" alt="" class="prod-thumb flex-shrink-0" loading="lazy"><?php endif; ?>
                            <span><a href="product_detail.php?sku=<?= urlencode($p['product_sku'] ?? $p['product_key']) ?>" class="text-decoration-none"><?= htmlspecialchars($p['product_key']) ?></a></span>
                        </td>
                        <td><span class="badge <?= $p['quadrant'] === 'stars' ? 'bg-warning' : ($p['quadrant'] === 'cash_cows' ? 'bg-success' : ($p['quadrant'] === 'question_marks' ? 'bg-info' : 'bg-secondary')) ?>"><?= ucfirst(str_replace('_', ' ', $p['quadrant'])) ?></span></td>
                        <td><?= number_format($p['share_pct'], 1) ?>%</td>
                        <td><?= $p['growth_pct'] >= 0 ? '+' : '' ?><?= number_format($p['growth_pct'], 1) ?>%</td>
                        <td>£<?= number_format($p['total_value'], 0) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($products)): ?>
                    <tr><td colspan="5" class="text-muted text-center py-4">No data. Extend date range or import sales.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const data = <?= json_encode($chartData) ?>;
    const medianShare = <?= round($medianShare, 2) ?>;
    const medianGrowth = <?= round($medianGrowth, 2) ?>;
    if (data.length && document.getElementById('matrixChart')) {
        const series = [{
            name: 'Products',
            data: data.map(d => ({ x: d.x, y: d.y, name: d.name }))
        }];
        const ann = {
            yaxis: [{
                y: medianGrowth, borderColor: '#2563eb', strokeDashArray: 2,
                label: { text: 'Median growth (' + medianGrowth.toFixed(1) + '%)', position: 'left', style: { color: '#666', fontSize: '10px' } }
            }],
            xaxis: [{
                x: medianShare, borderColor: '#2563eb', strokeDashArray: 2,
                label: { text: 'Median share (' + medianShare.toFixed(1) + '%)', position: 'top', style: { color: '#666', fontSize: '10px' } }
            }]
        };
        new ApexCharts(document.getElementById('matrixChart'), {
            chart: { type: 'scatter', height: 400, zoom: { enabled: true } },
            series: series,
            xaxis: { title: { text: 'Share %' }, min: 0, tickAmount: 5 },
            yaxis: { title: { text: 'Growth %' }, tickAmount: 5 },
            grid: {
                xaxis: { lines: { show: true } },
                yaxis: { lines: { show: true } }
            },
            annotations: ann,
            tooltip: {
                custom: function({ seriesIndex, dataPointIndex, w }) {
                    const d = data[dataPointIndex];
                    return '<div class="p-2"><strong>' + (d.name || '') + '</strong><br/>Share: ' + d.x + '%<br/>Growth: ' + d.y + '%</div>';
                }
            },
            colors: ['#2563eb']
        }).render();
    }
});
</script>
</div></div>
</body>
</html>
