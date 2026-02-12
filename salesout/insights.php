<?php
// salesout/insights.php - Category analysis, product families, trends & forecasting
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=' . urlencode('salesout/insights.php'));
    exit;
}

$pdo = getDBConnection();

$dateFrom = $_GET['date_from'] ?? date('Y-m-01', strtotime('-12 months'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$distributor = $_GET['distributor'] ?? '';
$forecastMonths = (int) ($_GET['forecast_months'] ?? 3);

$params = [$dateFrom, $dateTo];
$where = "s.report_date BETWEEN ? AND ?";
if (!empty($distributor)) {
    $where .= " AND s.distributor_name = ?";
    $params[] = $distributor;
}

$byProductType = [];
$byProductLine = [];
$byProductSeries = [];
$monthlyTrend = [];
$topSkuByCategory = [];
$forecast = null;
$distributors = [];
$totalDistReported = 0;
$totalAtTrade = 0;
$totalRows = 0;
$dbError = null;

try {
    $distributors = $pdo->query("SELECT DISTINCT distributor_name FROM sales_out_raw ORDER BY distributor_name")->fetchAll(PDO::FETCH_COLUMN);

    $byProductType = $pdo->prepare("
        SELECT COALESCE(p.product_type, p.product_line, p.product_category, 'Uncategorised') as category,
            COUNT(*) as row_count,
            COALESCE(SUM(s.total_value), 0) as dist_reported,
            COALESCE(SUM(s.quantity * p.msrp), 0) as at_msrp,
            COALESCE(SUM(s.quantity * p.trade_price), 0) as at_trade
        FROM sales_out_raw s
        LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
        WHERE $where
        GROUP BY category
        ORDER BY dist_reported DESC
    ");
    $byProductType->execute($params);
    $byProductType = $byProductType->fetchAll(PDO::FETCH_ASSOC);

    $byProductLine = $pdo->prepare("
        SELECT COALESCE(p.product_line, p.product_type, 'Other') as product_line,
            COUNT(*) as row_count,
            COALESCE(SUM(s.total_value), 0) as dist_reported,
            COALESCE(SUM(s.quantity * p.trade_price), 0) as at_trade
        FROM sales_out_raw s
        LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
        WHERE $where
        GROUP BY product_line
        ORDER BY dist_reported DESC
        LIMIT 15
    ");
    $byProductLine->execute($params);
    $byProductLine = $byProductLine->fetchAll(PDO::FETCH_ASSOC);

    $byProductSeries = $pdo->prepare("
        SELECT COALESCE(p.product_series, p.product_line, 'Other') as product_series,
            COUNT(*) as row_count,
            COALESCE(SUM(s.total_value), 0) as dist_reported
        FROM sales_out_raw s
        LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
        WHERE $where AND (p.product_series IS NOT NULL AND p.product_series != '')
        GROUP BY product_series
        ORDER BY dist_reported DESC
        LIMIT 12
    ");
    $byProductSeries->execute($params);
    $byProductSeries = $byProductSeries->fetchAll(PDO::FETCH_ASSOC);

    $monthlyTrend = $pdo->prepare("
        SELECT DATE_FORMAT(s.report_date, '%Y-%m') as month,
            COUNT(*) as row_count,
            COALESCE(SUM(s.total_value), 0) as dist_reported,
            COALESCE(SUM(s.quantity * p.trade_price), 0) as at_trade
        FROM sales_out_raw s
        LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
        WHERE $where
        GROUP BY month
        ORDER BY month
    ");
    $monthlyTrend->execute($params);
    $monthlyTrend = $monthlyTrend->fetchAll(PDO::FETCH_ASSOC);

    // Totals for KPI cards
    $totalsStmt = $pdo->prepare("
        SELECT COUNT(*) as row_count,
            COALESCE(SUM(s.total_value), 0) as dist_reported,
            COALESCE(SUM(s.quantity * p.trade_price), 0) as at_trade
        FROM sales_out_raw s
        LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
        WHERE $where
    ");
    $totalsStmt->execute($params);
    $totals = $totalsStmt->fetch(PDO::FETCH_ASSOC);
    $totalRows = (int) ($totals['row_count'] ?? 0);
    $totalDistReported = (float) ($totals['dist_reported'] ?? 0);
    $totalAtTrade = (float) ($totals['at_trade'] ?? 0);

    // Simple forecast: average of last N months projected forward
    $forecastData = $pdo->prepare("
        SELECT DATE_FORMAT(s.report_date, '%Y-%m') as month, COALESCE(SUM(s.total_value), 0) as dist_reported
        FROM sales_out_raw s
        WHERE $where
        GROUP BY month
        ORDER BY month DESC
        LIMIT 12
    ");
    $forecastData->execute($params);
    $months = array_reverse($forecastData->fetchAll(PDO::FETCH_ASSOC));

    if (count($months) >= 2 && $forecastMonths > 0) {
        $avg = array_sum(array_column($months, 'dist_reported')) / count($months);
        $lastMonth = end($months)['month'];
        $forecast = [
            'avg_monthly' => $avg,
            'last_month' => $lastMonth,
            'projections' => [],
            'historical' => $months,
        ];
        $d = new DateTime($lastMonth . '-01');
        for ($i = 1; $i <= $forecastMonths; $i++) {
            $d->modify('+1 month');
            $forecast['projections'][] = [
                'month' => $d->format('Y-m'),
                'label' => $d->format('M Y'),
                'value' => round($avg, 2),
            ];
        }
    }

    // Top SKUs within top categories
    $topCategories = array_slice(array_column($byProductType, 'category'), 0, 5);
    $topSkuByCategory = [];
    foreach ($topCategories as $cat) {
        $stmt = $pdo->prepare("
            SELECT s.sku, COALESCE(p.product_name, s.product_name) as product_name,
                SUM(s.quantity) as total_qty,
                COALESCE(SUM(s.total_value), 0) as dist_reported
            FROM sales_out_raw s
            LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
            WHERE $where
            AND COALESCE(p.product_type, p.product_line, p.product_category, 'Uncategorised') = ?
            GROUP BY s.sku, product_name
            ORDER BY dist_reported DESC
            LIMIT 5
        ");
        $stmt->execute(array_merge($params, [$cat]));
        $topSkuByCategory[$cat] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

// Chart data for Chart.js
$categoryLabels = json_encode(array_column($byProductType, 'category'));
$categoryDistData = json_encode(array_column($byProductType, 'dist_reported'));
$lineLabels = json_encode(array_column($byProductLine, 'product_line'));
$lineDistData = json_encode(array_column($byProductLine, 'dist_reported'));
$monthLabels = json_encode(array_map(function($m) { return date('M y', strtotime($m['month'] . '-01')); }, $monthlyTrend));
$monthDistData = json_encode(array_column($monthlyTrend, 'dist_reported'));
$monthTradeData = json_encode(array_column($monthlyTrend, 'at_trade'));
$chartColors = ['#00a399', '#3498db', '#9b59b6', '#e74c3c', '#f39c12', '#2ecc71', '#34495e', '#1abc9c', '#e67e22', '#95a5a6'];
$categoryColors = json_encode(array_slice($chartColors, 0, count($byProductType)));

require_once __DIR__ . '/header.php';
if (!empty($dbError)) {
    echo '<div class="container-xl py-4"><div class="alert alert-danger">' . htmlspecialchars($dbError) . '</div></div></div></div></body></html>';
    exit;
}
?>
<style>
.dashboard-header { background: linear-gradient(135deg, #00a399 0%, #00353d 100%); color: white; padding: 2rem 0; margin-bottom: 1.5rem; border-radius: 12px; box-shadow: 0 4px 20px rgba(0, 163, 153, 0.15); }
.filter-card { background: white; border: 1px solid #e1e5eb; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 1.5rem; padding: 20px; }
.filter-card .card-header { background: #f8f9fa; border-bottom: 1px solid #e1e5eb; padding: 1rem 1.25rem; font-weight: 600; color: #2a3547; }
.kpi-card { text-align: center; padding: 1.5rem; border-radius: 12px; background: white; border: 1px solid #e1e5eb; box-shadow: 0 2px 8px rgba(0,0,0,0.05); transition: transform 0.2s ease; height: 100%; }
.kpi-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
.kpi-value { font-size: 2rem; font-weight: 700; line-height: 1.2; margin-bottom: 0.5rem; }
.kpi-label { font-size: 0.875rem; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 500; }
.chart-card { border-radius: 12px; border: 1px solid #e1e5eb; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 1.5rem; background: white; }
.chart-card .card-header { background: #f8f9fa; border-bottom: 1px solid #e1e5eb; padding: 1rem 1.25rem; font-weight: 600; color: #2a3547; }
.chart-container { position: relative; height: 300px; width: 100%; }
.speedometer-container { height: 220px; width: 100%; }
.btn-filter-reset { border: 1px dashed #dee2e6; color: #6c757d; }
.btn-filter-reset:hover { border-color: #00a399; color: #00a399; }
.insights-body { background: #f5f7fb; }
</style>

<div class="container-xl py-4 insights-body">
    <div class="dashboard-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <div class="d-flex align-items-center mb-2">
                        <i class="ti ti-chart-line me-2" style="font-size: 1.5rem; color: white;"></i>
                        <h1 class="h2 mb-0">Sales Out Insights &amp; Forecasting</h1>
                    </div>
                    <p class="mb-0 opacity-75">
                        <i class="ti ti-calendar me-1"></i> <?= htmlspecialchars($dateFrom) ?> – <?= htmlspecialchars($dateTo) ?>
                        <?php if (!empty($distributor)): ?><span class="mx-2">•</span><i class="ti ti-building-store me-1"></i> <?= htmlspecialchars($distributor) ?><?php endif; ?>
                    </p>
                </div>
                <div class="col-md-4 text-md-end">
                    <button class="btn btn-outline-light" onclick="window.print()"><i class="ti ti-printer me-1"></i> Export</button>
                </div>
            </div>
        </div>
    </div>

    <div class="filter-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div><i class="ti ti-filter me-2"></i>Filters</div>
            <button type="button" class="btn btn-sm btn-filter-reset" onclick="window.location.href='insights.php'"><i class="ti ti-refresh me-1"></i> Reset</button>
        </div>
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
                    <label class="form-label">Forecast months</label>
                    <select name="forecast_months" class="form-select">
                        <option value="0" <?= $forecastMonths === 0 ? 'selected' : '' ?>>Off</option>
                        <option value="1" <?= $forecastMonths === 1 ? 'selected' : '' ?>>1</option>
                        <option value="3" <?= $forecastMonths === 3 ? 'selected' : '' ?>>3</option>
                        <option value="6" <?= $forecastMonths === 6 ? 'selected' : '' ?>>6</option>
                        <option value="12" <?= $forecastMonths === 12 ? 'selected' : '' ?>>12</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary"><i class="ti ti-filter me-1"></i> Apply</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-4">
            <div class="kpi-card">
                <div class="metric-icon mx-auto mb-2 rounded d-flex align-items-center justify-content-center" style="width:48px;height:48px;background:rgba(0,163,153,0.15);"><i class="ti ti-list" style="font-size:1.5rem;color:#00a399"></i></div>
                <div class="kpi-value text-primary"><?= number_format($totalRows) ?></div>
                <div class="kpi-label">Total Rows</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="kpi-card">
                <div class="metric-icon mx-auto mb-2 rounded d-flex align-items-center justify-content-center" style="width:48px;height:48px;background:rgba(52,152,219,0.15);"><i class="ti ti-cash" style="font-size:1.5rem;color:#3498db"></i></div>
                <div class="kpi-value text-info">£<?= number_format($totalDistReported, 0) ?></div>
                <div class="kpi-label">Dist. Reported</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="kpi-card">
                <div class="metric-icon mx-auto mb-2 rounded d-flex align-items-center justify-content-center" style="width:48px;height:48px;background:rgba(46,204,113,0.15);"><i class="ti ti-discount" style="font-size:1.5rem;color:#2ecc71"></i></div>
                <div class="kpi-value text-success">£<?= number_format($totalAtTrade, 0) ?></div>
                <div class="kpi-label">At Trade Price</div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-lg-6">
            <div class="chart-card">
                <div class="card-header"><i class="ti ti-chart-donut me-2"></i>Sales by Product Type / Category</div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="categoryChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="chart-card">
                <div class="card-header"><i class="ti ti-chart-bar me-2"></i>Sales by Product Line</div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="productLineChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-lg-8">
            <div class="chart-card">
                <div class="card-header"><i class="ti ti-chart-line me-2"></i>Monthly Trend (Dist. Reported vs At Trade)</div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="monthlyChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="chart-card">
                <div class="card-header"><i class="ti ti-gauge me-2"></i>Total Sales Summary</div>
                <div class="card-body">
                    <div class="speedometer-container">
                        <div id="salesGauge"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($forecast && count($forecast['projections']) > 0): ?>
    <div class="chart-card mb-4">
        <div class="card-header"><i class="ti ti-trending-up me-2"></i>Simple Forecast (avg £<?= number_format($forecast['avg_monthly'], 0) ?>/month)</div>
        <div class="card-body">
            <div class="chart-container">
                <canvas id="forecastChart"></canvas>
            </div>
            <p class="text-muted small mt-2 mb-0">Last month in data: <?= htmlspecialchars($forecast['last_month']) ?>. Projection based on historical average.</p>
        </div>
    </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-6">
            <div class="chart-card">
                <div class="card-header"><i class="ti ti-table me-2"></i>By Product Type / Category (Detail)</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped table-bordered mb-0">
                            <thead class="table-light"><tr><th>Category</th><th>Rows</th><th>Dist. Reported</th><th>At MSRP</th><th>At Trade</th></tr></thead>
                            <tbody>
                                <?php foreach ($byProductType as $r): ?>
                                <tr>
                                    <td><?= htmlspecialchars($r['category']) ?></td>
                                    <td><?= number_format($r['row_count']) ?></td>
                                    <td>£<?= number_format($r['dist_reported'], 0) ?></td>
                                    <td>£<?= number_format($r['at_msrp'], 0) ?></td>
                                    <td>£<?= number_format($r['at_trade'], 0) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($byProductType)): ?>
                                <tr><td colspan="5" class="text-muted">No data. Import sales and products.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="chart-card">
                <div class="card-header"><i class="ti ti-table me-2"></i>By Product Line (Detail)</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped table-bordered mb-0">
                            <thead class="table-light"><tr><th>Product Line</th><th>Rows</th><th>Dist. Reported</th><th>At Trade</th></tr></thead>
                            <tbody>
                                <?php foreach ($byProductLine as $r): ?>
                                <tr>
                                    <td><?= htmlspecialchars($r['product_line']) ?></td>
                                    <td><?= number_format($r['row_count']) ?></td>
                                    <td>£<?= number_format($r['dist_reported'], 0) ?></td>
                                    <td>£<?= number_format($r['at_trade'], 0) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($byProductLine)): ?>
                                <tr><td colspan="4" class="text-muted">No product data. <a href="products.php">Import EPOS product list</a>.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-6">
            <div class="chart-card">
                <div class="card-header"><i class="ti ti-table me-2"></i>By Product Series</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped table-bordered mb-0">
                            <thead class="table-light"><tr><th>Series</th><th>Rows</th><th>Dist. Reported</th></tr></thead>
                            <tbody>
                                <?php foreach ($byProductSeries as $r): ?>
                                <tr>
                                    <td><?= htmlspecialchars($r['product_series']) ?></td>
                                    <td><?= number_format($r['row_count']) ?></td>
                                    <td>£<?= number_format($r['dist_reported'], 0) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($byProductSeries)): ?>
                                <tr><td colspan="3" class="text-muted">No series data.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="chart-card">
                <div class="card-header"><i class="ti ti-table me-2"></i>Monthly Trend (Detail)</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped table-bordered mb-0">
                            <thead class="table-light"><tr><th>Month</th><th>Rows</th><th>Dist. Reported</th><th>At Trade</th></tr></thead>
                            <tbody>
                                <?php foreach ($monthlyTrend as $r): ?>
                                <tr>
                                    <td><?= htmlspecialchars($r['month']) ?></td>
                                    <td><?= number_format($r['row_count']) ?></td>
                                    <td>£<?= number_format($r['dist_reported'], 0) ?></td>
                                    <td>£<?= number_format($r['at_trade'], 0) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($monthlyTrend)): ?>
                                <tr><td colspan="4" class="text-muted">No trend data.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="chart-card">
        <div class="card-header"><i class="ti ti-trophy me-2"></i>Top SKUs by Category</div>
        <div class="card-body">
            <div class="row">
                <?php foreach ($topSkuByCategory as $cat => $skus): ?>
                <div class="col-md-6 col-lg-4 mb-3">
                    <h6 class="text-muted"><?= htmlspecialchars($cat) ?></h6>
                    <table class="table table-hover table-striped table-bordered table-sm">
                        <thead class="table-light"><tr><th>SKU</th><th>Product</th><th>Qty</th><th>Value</th></tr></thead>
                        <tbody>
                            <?php foreach ($skus as $s): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($s['sku']) ?></code></td>
                                <td class="small text-truncate" style="max-width:120px" title="<?= htmlspecialchars($s['product_name'] ?? '') ?>"><?= htmlspecialchars($s['product_name'] ?? '-') ?></td>
                                <td><?= number_format($s['total_qty']) ?></td>
                                <td>£<?= number_format($s['dist_reported'], 0) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (empty($topSkuByCategory)): ?>
            <p class="text-muted mb-0">No category data. Import EPOS product list to enable SKU-to-category lookup.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    function fmt(v) { return '£' + parseFloat(v).toLocaleString('en-GB', { minimumFractionDigits: 0, maximumFractionDigits: 0 }); }
    const colors = <?= json_encode($chartColors) ?>;

    const catEl = document.getElementById('categoryChart');
    if (catEl) {
        const labels = <?= $categoryLabels ?>;
        const data = <?= $categoryDistData ?>;
        const col = <?= $categoryColors ?>;
        if (labels.length && data.some(x => x > 0)) {
            new Chart(catEl.getContext('2d'), {
                type: 'doughnut',
                data: { labels: labels, datasets: [{ data: data, backgroundColor: col, borderColor: 'white', borderWidth: 2 }] },
                options: { responsive: true, maintainAspectRatio: false, cutout: '55%', plugins: { legend: { position: 'right' }, tooltip: { callbacks: { label: c => c.label + ': ' + fmt(c.raw) } } } }
            });
        } else catEl.parentElement.innerHTML = '<div class="text-center py-5 text-muted">No category data</div>';
    }

    const lineEl = document.getElementById('productLineChart');
    if (lineEl) {
        const labels = <?= $lineLabels ?>;
        const data = <?= $lineDistData ?>;
        if (labels.length && data.some(x => x > 0)) {
            new Chart(lineEl.getContext('2d'), {
                type: 'bar',
                data: { labels: labels, datasets: [{ label: 'Dist. Reported', data: data, backgroundColor: colors.slice(0, labels.length) }] },
                options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => fmt(c.raw) } } }, scales: { x: { beginAtZero: true, ticks: { callback: v => fmt(v) } } } }
            });
        } else lineEl.parentElement.innerHTML = '<div class="text-center py-5 text-muted">No product line data</div>';
    }

    const monEl = document.getElementById('monthlyChart');
    if (monEl) {
        const labels = <?= $monthLabels ?>;
        const distData = <?= $monthDistData ?>;
        const tradeData = <?= $monthTradeData ?>;
        if (labels.length) {
            new Chart(monEl.getContext('2d'), {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        { label: 'Dist. Reported', data: distData, borderColor: '#00a399', backgroundColor: '#00a39920', borderWidth: 2, fill: true, tension: 0.4 },
                        { label: 'At Trade', data: tradeData, borderColor: '#00353d', backgroundColor: '#00353d20', borderWidth: 2, borderDash: [4,4], fill: true, tension: 0.4 }
                    ]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top' }, tooltip: { callbacks: { label: c => c.dataset.label + ': ' + fmt(c.raw) } } }, scales: { y: { beginAtZero: true, ticks: { callback: v => fmt(v) } } } }
            });
        } else monEl.parentElement.innerHTML = '<div class="text-center py-5 text-muted">No monthly data</div>';
    }

    const gaugeEl = document.getElementById('salesGauge');
    if (gaugeEl && <?= $totalDistReported ?> > 0) {
        const total = <?= $totalDistReported ?>;
        const max = Math.max(total * 1.2, 100000);
        const pct = Math.min(100, (total / max) * 100);
        new ApexCharts(gaugeEl, {
            series: [pct],
            chart: { height: 220, type: 'radialBar' },
            plotOptions: { radialBar: { startAngle: -135, endAngle: 135, dataLabels: { value: { fontSize: '18px', formatter: () => '£' + total.toLocaleString('en-GB', { maximumFractionDigits: 0 }) } } } },
            labels: ['Total Sales'],
            colors: ['#00a399'],
            fill: { type: 'gradient', gradient: { shade: 'dark', shadeIntensity: 0.2, opacityFrom: 1, opacityTo: 1 } }
        }).render();
    } else if (gaugeEl) {
        gaugeEl.innerHTML = '<div class="text-center py-5 text-muted"><i class="ti ti-chart-pie-off" style="font-size:2rem"></i><p class="mt-2">No sales data</p></div>';
    }

    <?php if ($forecast && count($forecast['projections']) > 0): ?>
    const fcEl = document.getElementById('forecastChart');
    if (fcEl) {
        const hist = <?= json_encode($forecast['historical'] ?? []) ?>;
        const proj = <?= json_encode($forecast['projections'] ?? []) ?>;
        const allLabels = hist.map(h => h.month).concat(proj.map(p => p.month));
        const allLabelsFmt = allLabels.map(m => { const d = new Date(m + '-01'); return d.toLocaleDateString('en-GB', { month: 'short', year: '2-digit' }); });
        const histData = hist.map(h => h.dist_reported);
        const projData = hist.map(() => null).concat(proj.map(p => p.value));
        new Chart(fcEl.getContext('2d'), {
            type: 'line',
            data: {
                labels: allLabelsFmt,
                datasets: [
                    { label: 'Historical', data: histData.concat(proj.map(() => null)), borderColor: '#00a399', backgroundColor: '#00a39920', borderWidth: 2, fill: true, tension: 0.4 },
                    { label: 'Forecast', data: hist.map(() => null).concat(proj.map(p => p.value)), borderColor: '#f39c12', backgroundColor: 'transparent', borderWidth: 2, borderDash: [6,4], tension: 0.4 }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top' }, tooltip: { callbacks: { label: c => c.dataset.label + ': ' + (c.raw != null ? fmt(c.raw) : '-') } } }, scales: { y: { beginAtZero: true, ticks: { callback: v => fmt(v) } } } }
        });
    }
    <?php endif; ?>
});
</script>
</div></div>
</body>
</html>
