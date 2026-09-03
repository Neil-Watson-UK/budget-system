<?php
// inventory_report.php - Stock levels and weeks of stock per SKU/distributor
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=' . urlencode('salesout/inventory_report.php'));
    exit;
}

$pdo = getDBConnection();
$distributor = trim($_GET['distributor'] ?? '');
$category = trim($_GET['category'] ?? '');
$productFilter = trim($_GET['product_filter'] ?? '');
$weeksBack = (int)($_GET['weeks_back'] ?? 8);
if ($weeksBack < 1 || $weeksBack > 26) $weeksBack = 8;
$minWeeksHighlight = (float)($_GET['min_weeks'] ?? 2);
$maxWeeksHighlight = (float)($_GET['max_weeks'] ?? 12);

$tableExists = false;
try {
    $tableExists = (bool)$pdo->query("SHOW TABLES LIKE 'sales_out_inventory'")->fetch();
} catch (PDOException $e) { /* ignore */ }

$inventory = [];
$topToRefresh = [];
$summary = ['total_value' => 0, 'total_units' => 0, 'skus' => 0, 'distributors' => 0];
$distributors = [];
$categories = [];
$chartValueByDist = [];
$chartWeeksByDist = [];
$latestDates = [];

if ($tableExists) {
    $distributors = $pdo->query("SELECT DISTINCT distributor_name FROM sales_out_inventory ORDER BY distributor_name")->fetchAll(PDO::FETCH_COLUMN);

    // Populate category/family filter options from products table (SKUs that exist in inventory)
    try {
        $catStmt = $pdo->prepare("
            SELECT DISTINCT COALESCE(p.product_category, p.product_line, 'Uncategorised') as cat
            FROM sales_out_inventory i
            LEFT JOIN sales_out_products p ON TRIM(REPLACE(i.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
            WHERE COALESCE(p.product_category, p.product_line) IS NOT NULL AND COALESCE(p.product_category, p.product_line) != ''
            ORDER BY cat
        ");
        $catStmt->execute();
        $categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('Uncategorised', $categories, true)) {
            $categories[] = 'Uncategorised';
            sort($categories);
        }
    } catch (PDOException $e) { $categories = []; }

    $latestDate = $pdo->query("SELECT MAX(snapshot_date) FROM sales_out_inventory")->fetchColumn();
    if ($latestDate) {
        $salesFrom = date('Y-m-d', strtotime("-$weeksBack weeks", strtotime($latestDate)));
        $whereProd = [];
        $aliases = getInventoryDistributorAliases();
        $distNames = $distributor ? ($aliases[$distributor] ?? [$distributor]) : [];
        $distPh = $distNames ? implode(',', array_fill(0, count($distNames), 'LOWER(?)')) : '';
        $whereDist = $distPh ? " AND LOWER(TRIM(distributor_name)) IN ($distPh)" : "";
        $whereSales = $distPh ? " AND LOWER(TRIM(s.distributor_name)) IN ($distPh)" : "";
        $canonJoin = "CASE WHEN LOWER(TRIM(i.distributor_name)) IN ('westcoast','wc') THEN 'westcoast' ELSE LOWER(TRIM(i.distributor_name)) END = CASE WHEN LOWER(TRIM(sa.distributor_name)) IN ('westcoast','wc') THEN 'westcoast' ELSE LOWER(TRIM(sa.distributor_name)) END";

        $params = [];
        if ($distNames) { $params = array_merge($params, $distNames); }
        $params[] = $salesFrom;
        $params[] = $latestDate;
        if ($distNames) { $params = array_merge($params, $distNames); }

        if ($category) {
            $whereProd[] = " AND COALESCE(p.product_category, p.product_line, 'Uncategorised') = ?";
            $params[] = $category;
        }
        if ($productFilter) {
            $pf = '%' . $productFilter . '%';
            $whereProd[] = " AND (i.sku LIKE ? OR i.sku_description LIKE ? OR COALESCE(p.product_name,'') LIKE ?)";
            $params[] = $pf;
            $params[] = $pf;
            $params[] = $pf;
        }
        $whereProd = implode('', $whereProd);

        // Latest inventory + avg weekly sales + weeks of stock (MySQL 5.7 compatible)
        $sql = "
            SELECT
                i.distributor_name,
                i.sku,
                COALESCE(p.product_name, i.sku_description) as sku_description,
                p.product_category,
                p.product_line,
                i.snapshot_date,
                i.on_hand_qty,
                i.unit_cost,
                i.inventory_value,
                COALESCE(sa.units_sold, 0) as units_sold,
                COALESCE(sa.weeks_count, 0) as weeks_count,
                CASE WHEN COALESCE(sa.weeks_count, 0) > 0 AND sa.units_sold > 0
                    THEN i.on_hand_qty / (sa.units_sold / sa.weeks_count)
                    ELSE NULL
                END as weeks_of_stock
            FROM sales_out_inventory i
            LEFT JOIN sales_out_products p ON TRIM(REPLACE(i.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
            INNER JOIN (
                SELECT distributor_name, TRIM(REPLACE(sku,' ','')) as sku_norm, MAX(snapshot_date) as max_date
                FROM sales_out_inventory
                WHERE 1=1 $whereDist
                GROUP BY distributor_name, sku_norm
            ) latest ON i.distributor_name = latest.distributor_name
                AND TRIM(REPLACE(i.sku,' ','')) = latest.sku_norm
                AND i.snapshot_date = latest.max_date
            LEFT JOIN (
                SELECT s.distributor_name, TRIM(REPLACE(s.sku,' ','')) as sku_norm,
                    SUM(s.quantity) as units_sold,
                    COUNT(DISTINCT YEARWEEK(s.report_date)) as weeks_count
                FROM sales_out_raw s
                WHERE s.report_date >= ? AND s.report_date <= ?
                AND s.sku IS NOT NULL AND s.sku != ''
                $whereSales
                GROUP BY s.distributor_name, sku_norm
            ) sa ON $canonJoin AND TRIM(REPLACE(i.sku,' ','')) = sa.sku_norm
            WHERE 1=1 $whereProd
            ORDER BY i.inventory_value DESC
            LIMIT 1000
        ";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($inventory as $r) {
                $summary['total_value'] += (float)($r['inventory_value'] ?? 0);
                $summary['total_units'] += (int)($r['on_hand_qty'] ?? 0);
            }
            $summary['skus'] = count($inventory);
            $summary['distributors'] = count(array_unique(array_column($inventory, 'distributor_name')));

            // Chart data: value by distributor
            $chartValueByDist = [];
            foreach ($inventory as $r) {
                $d = $r['distributor_name'];
                $chartValueByDist[$d] = ($chartValueByDist[$d] ?? 0) + (float)($r['inventory_value'] ?? 0);
            }
            arsort($chartValueByDist);

            // Chart data: avg weeks of stock by distributor
            $chartWeeksByDist = [];
            $chartWeeksCount = [];
            foreach ($inventory as $r) {
                $w = $r['weeks_of_stock'];
                if ($w !== null && (float)$w >= 0) {
                    $d = $r['distributor_name'];
                    $chartWeeksByDist[$d] = ($chartWeeksByDist[$d] ?? 0) + (float)$w;
                    $chartWeeksCount[$d] = ($chartWeeksCount[$d] ?? 0) + 1;
                }
            }
            foreach ($chartWeeksByDist as $d => $sum) {
                $chartWeeksByDist[$d] = round($sum / ($chartWeeksCount[$d] ?? 1), 1);
            }
            arsort($chartWeeksByDist);

            // Top SKUs to refresh: low weeks of stock + has sales run-rate
            $topToRefresh = array_filter($inventory, function($r) use ($minWeeksHighlight) {
                $w = $r['weeks_of_stock'];
                return $w !== null && (float)$w < $minWeeksHighlight && (float)$w >= 0
                    && ((int)($r['units_sold'] ?? 0) > 0);
            });
            usort($topToRefresh, function($a, $b) {
                $wa = (float)($a['weeks_of_stock'] ?? 99);
                $wb = (float)($b['weeks_of_stock'] ?? 99);
                if (abs($wa - $wb) < 0.01) {
                    return (float)($b['inventory_value'] ?? 0) <=> (float)($a['inventory_value'] ?? 0);
                }
                return $wa <=> $wb;
            });
            $topToRefresh = array_slice($topToRefresh, 0, 30);

        } catch (PDOException $e) {
            $inventory = [];
        }

        try {
            $latestDates = $pdo->query("SELECT distributor_name, MAX(snapshot_date) as d FROM sales_out_inventory GROUP BY distributor_name")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $latestDates = [];
        }
    }
}

$chartColors = ['#00a399', '#00353d', '#ff5549', '#666666', '#f59e0b', '#cccccc', '#3498db'];
$chartValueLabels = json_encode(array_keys($chartValueByDist ?? []));
$chartValueData = json_encode(array_values($chartValueByDist ?? []));
$chartWeeksLabels = json_encode(array_keys($chartWeeksByDist ?? []));
$chartWeeksData = json_encode(array_values($chartWeeksByDist ?? []));

require_once __DIR__ . '/header.php';
?>
<style>
.inv-report-header { background: linear-gradient(135deg, #00353d 0%, #00a399 100%); color: white; padding: 2rem 0; margin-bottom: 1.5rem; border-radius: 10px; }
.wos-low { color: #dc2626; font-weight: 600; }
.wos-ok { color: #059669; }
.wos-high { color: #d97706; font-weight: 600; }
</style>
<div class="container-xl py-4">
    <div class="inv-report-header">
        <div class="container-fluid">
            <h1 class="h2 mb-1"><i class="ti ti-package me-2"></i>Inventory &amp; Weeks of Stock</h1>
            <p class="mb-0 opacity-75">Current stock levels and weeks of stock per SKU. Weeks of stock = on hand ÷ average weekly run-rate (from sales in the lookback period).</p>
        </div>
    </div>

    <?php if (!$tableExists): ?>
    <div class="alert alert-warning"><i class="ti ti-alert-triangle me-2"></i>Run <code>install/inventory_schema.sql</code> in phpMyAdmin, then <a href="inventory_import.php">import inventory</a>.</div>
    <?php else: ?>

    <div class="card mb-4">
        <div class="card-header"><i class="ti ti-filter me-2"></i>Filters</div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Distributor</label>
                    <select name="distributor" class="form-select">
                        <option value="">All</option>
                        <?php foreach ($distributors as $d): ?>
                        <option value="<?= htmlspecialchars($d) ?>" <?= $distributor === $d ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Category</label>
                    <select name="category" class="form-select">
                        <option value="">All</option>
                        <?php foreach ($categories as $c): ?>
                        <option value="<?= htmlspecialchars($c) ?>" <?= $category === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Product / SKU</label>
                    <input type="text" name="product_filter" class="form-control" placeholder="Search…" value="<?= htmlspecialchars($productFilter) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Sales lookback <i class="ti ti-info-circle" title="Number of weeks of past sales used to calculate average weekly run-rate. That run-rate is used to compute weeks of stock."></i></label>
                    <select name="weeks_back" class="form-select">
                        <?php foreach ([4, 8, 12, 16, 26] as $w): ?>
                        <option value="<?= $w ?>" <?= $weeksBack === $w ? 'selected' : '' ?>><?= $w ?> weeks</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary">Apply</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card">
                <div class="card-body text-center">
                    <div class="h4 text-primary">£<?= number_format($summary['total_value'], 0) ?></div>
                    <div class="small text-muted">Total inventory value</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body text-center">
                    <div class="h4"><?= number_format($summary['total_units'], 0) ?></div>
                    <div class="small text-muted">Total units on hand</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body text-center">
                    <div class="h4"><?= $summary['skus'] ?></div>
                    <div class="small text-muted">SKUs</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body text-center">
                    <div class="h4"><?= $summary['distributors'] ?></div>
                    <div class="small text-muted">Distributors</div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($chartValueByDist) || !empty($chartWeeksByDist)): ?>
    <div class="row mb-4">
        <div class="col-lg-6 mb-4 mb-lg-0">
            <div class="card h-100">
                <div class="card-header"><i class="ti ti-currency-pound me-2"></i>Inventory value by distributor</div>
                <div class="card-body p-3">
                    <div class="chart-container" style="height: 260px;"><canvas id="valueChart"></canvas></div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><i class="ti ti-clock me-2"></i>Average weeks of stock by distributor</div>
                <div class="card-body p-3">
                    <div class="chart-container" style="height: 260px;"><canvas id="weeksChart"></canvas></div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($topToRefresh)): ?>
    <div class="card mb-4 border-danger">
        <div class="card-header border-danger border-2 bg-white text-danger"><i class="ti ti-alert-triangle me-2"></i>Top SKUs to refresh (low stock vs run-rate, &lt;<?= $minWeeksHighlight ?> weeks)</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Distributor</th>
                            <th>SKU</th>
                            <th>Product</th>
                            <th class="text-end">On hand</th>
                            <th class="text-end">Sold (<?= $weeksBack ?>w)</th>
                            <th class="text-end">Weeks left</th>
                            <th class="text-end">Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topToRefresh as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['distributor_name']) ?></td>
                            <td><a href="product_detail.php?sku=<?= urlencode($r['sku']) ?>"><code><?= htmlspecialchars($r['sku']) ?></code></a></td>
                            <td class="text-truncate" style="max-width:160px" title="<?= htmlspecialchars($r['sku_description'] ?? '') ?>"><?= htmlspecialchars($r['sku_description'] ?? '—') ?></td>
                            <td class="text-end"><?= number_format($r['on_hand_qty'], 0) ?></td>
                            <td class="text-end"><?= number_format($r['units_sold'] ?? 0, 0) ?></td>
                            <td class="text-end text-danger fw-bold"><?= number_format((float)$r['weeks_of_stock'], 1) ?> w</td>
                            <td class="text-end">£<?= number_format($r['inventory_value'] ?? 0, 0) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><i class="ti ti-table me-2"></i>Stock by SKU (top by value)</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Distributor</th>
                            <th>SKU</th>
                            <th>Product</th>
                            <th class="text-end">On hand</th>
                            <th class="text-end">Unit cost</th>
                            <th class="text-end">Value</th>
                            <th class="text-end">Sold (<?= $weeksBack ?>w)</th>
                            <th class="text-end">Weeks of stock</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($inventory as $r): 
                            $wos = $r['weeks_of_stock'] !== null ? (float)$r['weeks_of_stock'] : null;
                            $wosClass = '';
                            if ($wos !== null) {
                                if ($wos < $minWeeksHighlight) $wosClass = 'wos-low';
                                elseif ($wos > $maxWeeksHighlight) $wosClass = 'wos-high';
                                else $wosClass = 'wos-ok';
                            }
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($r['distributor_name']) ?></td>
                            <td>
                                <a href="product_detail.php?sku=<?= urlencode($r['sku']) ?>"><code><?= htmlspecialchars($r['sku']) ?></code></a>
                            </td>
                            <td class="text-truncate" style="max-width:180px" title="<?= htmlspecialchars($r['sku_description'] ?? '') ?>">
                                <?= htmlspecialchars($r['sku_description'] ?? '—') ?>
                            </td>
                            <td class="text-end"><?= number_format($r['on_hand_qty'], 0) ?></td>
                            <td class="text-end">£<?= number_format($r['unit_cost'] ?? 0, 2) ?></td>
                            <td class="text-end"><strong>£<?= number_format($r['inventory_value'] ?? 0, 0) ?></strong></td>
                            <td class="text-end"><?= number_format($r['units_sold'] ?? 0, 0) ?></td>
                            <td class="text-end <?= $wosClass ?>">
                                <?php if ($wos !== null): ?>
                                    <?= number_format($wos, 1) ?> w
                                    <?php if ($wosClass === 'wos-low'): ?><span class="badge bg-danger ms-1">Low</span>
                                    <?php elseif ($wosClass === 'wos-high'): ?><span class="badge bg-warning text-dark ms-1">High</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($inventory)): ?>
                        <tr><td colspan="8" class="text-muted text-center">No inventory data. <a href="inventory_import.php">Import inventory</a> first.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer small text-muted d-flex justify-content-between align-items-center">
            <span>Weeks of stock = On hand ÷ (Avg weekly units sold). &lt;<?= $minWeeksHighlight ?> weeks = Low (red), &gt;<?= $maxWeeksHighlight ?> weeks = High (amber). Sales lookback = how many weeks of past sales we use to calculate the average weekly run-rate.</span>
            <span>
                <a href="inventory_trends.php<?= $distributor ? '?distributor=' . urlencode($distributor) : '' ?>" class="ms-2"><i class="ti ti-chart-line me-1"></i>Trends</a>
                <a href="inventory_movement.php<?= $distributor ? '?distributor=' . urlencode($distributor) : '' ?>" class="ms-2"><i class="ti ti-arrows-exchange me-1"></i>Movement</a>
            </span>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php if ((!empty($chartValueByDist) || !empty($chartWeeksByDist)) && $tableExists): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function() {
    var colors = <?= json_encode($chartColors) ?>;
    var valEl = document.getElementById('valueChart');
    if (valEl) {
        var valLabels = <?= $chartValueLabels ?? '[]' ?>;
        var valData = <?= $chartValueData ?? '[]' ?>;
        if (valLabels.length && valData.length) {
            new Chart(valEl.getContext('2d'), {
                type: 'bar',
                data: { labels: valLabels, datasets: [{ label: 'Value (£)', data: valData, backgroundColor: colors.slice(0, valLabels.length) }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { callback: function(v) { return '£' + v; } } } } }
            });
        }
    }
    var weeksEl = document.getElementById('weeksChart');
    if (weeksEl) {
        var weeksLabels = <?= $chartWeeksLabels ?? '[]' ?>;
        var weeksData = <?= $chartWeeksData ?? '[]' ?>;
        if (weeksLabels.length && weeksData.length) {
            new Chart(weeksEl.getContext('2d'), {
                type: 'bar',
                data: { labels: weeksLabels, datasets: [{ label: 'Avg weeks', data: weeksData, backgroundColor: colors.slice(0, weeksLabels.length) }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
            });
        }
    }
})();
</script>
<?php endif; ?>
</div></div>
</body>
</html>
