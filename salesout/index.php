<?php
// salesout/index.php - Dashboard with insights
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=' . urlencode('salesout/index.php'));
    exit;
}

$pdo = getDBConnection();
$summary = ['total_rows' => 0, 'total_value' => 0, 'distributors' => 0, 'match_rate' => 0, 'matched_to_vendor' => 0, 'resellers' => 0, 'skus' => 0];
$topDistributors = [];
$topResellers = [];
$recentImports = [];
$valueCompare = ['dist_reported' => 0, 'at_msrp' => 0, 'at_trade' => 0];
$dbError = null;

try {
    $summary = $pdo->query("
        SELECT 
            COUNT(*) as total_rows,
            COUNT(DISTINCT distributor_name) as distributors,
            COUNT(DISTINCT reseller_name) as resellers,
            COUNT(DISTINCT sku) as skus,
            COALESCE(SUM(total_value), 0) as total_value,
            SUM(CASE WHEN matched_vendor_id IS NOT NULL THEN 1 ELSE 0 END) as matched_to_vendor
        FROM sales_out_raw
    ")->fetch(PDO::FETCH_ASSOC);

    $valueCompare = $pdo->query("
        SELECT
            COALESCE(SUM(s.total_value), 0) as dist_reported,
            COALESCE(SUM(s.quantity * p.msrp), 0) as at_msrp,
            COALESCE(SUM(s.quantity * p.trade_price), 0) as at_trade
        FROM sales_out_raw s
        LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
    ")->fetch(PDO::FETCH_ASSOC);

    $summary['match_rate'] = $summary['total_rows'] > 0 
        ? round(100 * $summary['matched_to_vendor'] / $summary['total_rows'], 1) 
        : 0;

    $topDistributors = $pdo->query("
        SELECT distributor_name, COUNT(*) as row_count, COALESCE(SUM(total_value), 0) as total
        FROM sales_out_raw GROUP BY distributor_name ORDER BY total DESC LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    $topResellers = $pdo->query("
        SELECT v.vendor_name, COUNT(*) as row_count, COALESCE(SUM(s.total_value), 0) as total
        FROM sales_out_raw s
        INNER JOIN vendors v ON s.matched_vendor_id = v.id
        GROUP BY s.matched_vendor_id, v.vendor_name ORDER BY total DESC LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    $recentImports = $pdo->query("
        SELECT * FROM sales_out_imports ORDER BY imported_at DESC LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

// Chart data
$distLabels = json_encode(array_column($topDistributors, 'distributor_name'));
$distData = json_encode(array_column($topDistributors, 'total'));
$resellerLabels = json_encode(array_column($topResellers, 'vendor_name'));
$resellerData = json_encode(array_column($topResellers, 'total'));
$valueCompareLabels = json_encode(['Dist. Reported', 'At MSRP', 'At Trade']);
$valueCompareData = json_encode([
    (float)($valueCompare['dist_reported'] ?? 0),
    (float)($valueCompare['at_msrp'] ?? 0),
    (float)($valueCompare['at_trade'] ?? 0)
]);
$chartColors = ['#00a399', '#3498db', '#9b59b6', '#e74c3c', '#f39c12', '#2ecc71', '#34495e', '#1abc9c', '#e67e22', '#95a5a6'];

require_once __DIR__ . '/header.php';
if (!empty($dbError)) {
    echo '<div class="container-xl py-4"><div class="alert alert-danger">Database error: ' . htmlspecialchars($dbError) . '</div></div></div></div></body></html>';
    exit;
}
?>
<style>
.dashboard-header { background: linear-gradient(135deg, #00a399 0%, #00353d 100%); color: white; padding: 2rem 0; margin-bottom: 1.5rem; border-radius: 12px; box-shadow: 0 4px 20px rgba(0, 163, 153, 0.15); }
.kpi-card { text-align: center; padding: 1.25rem; border-radius: 12px; background: white; border: 1px solid #e1e5eb; box-shadow: 0 2px 8px rgba(0,0,0,0.05); transition: transform 0.2s ease; height: 100%; }
.kpi-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
.kpi-value { font-size: 1.75rem; font-weight: 700; line-height: 1.2; margin-bottom: 0.25rem; }
.kpi-label { font-size: 0.75rem; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 500; }
.chart-card { border-radius: 12px; border: 1px solid #e1e5eb; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 1.5rem; background: white; }
.chart-card .card-header { background: #f8f9fa; border-bottom: 1px solid #e1e5eb; padding: 1rem 1.25rem; font-weight: 600; color: #2a3547; }
.chart-container { position: relative; height: 280px; width: 100%; }
.speedometer-container { height: 200px; width: 100%; }
.dashboard-body { background: #f5f7fb; }
</style>

<div class="container-xl py-4 dashboard-body">
    <div class="dashboard-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <div class="d-flex align-items-center mb-2">
                        <i class="ti ti-dashboard me-2" style="font-size: 1.5rem; color: white;"></i>
                        <h1 class="h2 mb-0">Sales Out Dashboard</h1>
                    </div>
                    <p class="mb-0 opacity-75">Standardised distributor sales data. Value columns use product master (MSRP/Trade) when SKU matches.</p>
                </div>
                <div class="col-md-4 text-md-end">
                    <a href="import.php" class="btn btn-outline-light me-2"><i class="ti ti-upload me-1"></i> Import</a>
                    <a href="insights.php" class="btn btn-outline-light"><i class="ti ti-chart-line me-1"></i> Insights</a>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-2">
            <div class="kpi-card">
                <div class="metric-icon mx-auto mb-2 rounded d-flex align-items-center justify-content-center" style="width:44px;height:44px;background:rgba(0,163,153,0.15);"><i class="ti ti-list" style="font-size:1.25rem;color:#00a399"></i></div>
                <div class="kpi-value text-primary"><?= number_format($summary['total_rows']) ?></div>
                <div class="kpi-label">Total Rows</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="kpi-card">
                <div class="metric-icon mx-auto mb-2 rounded d-flex align-items-center justify-content-center" style="width:44px;height:44px;background:rgba(52,152,219,0.15);"><i class="ti ti-cash" style="font-size:1.25rem;color:#3498db"></i></div>
                <div class="kpi-value text-info">£<?= number_format($valueCompare['dist_reported'] ?? 0, 0) ?></div>
                <div class="kpi-label">Dist. Reported</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="kpi-card">
                <div class="metric-icon mx-auto mb-2 rounded d-flex align-items-center justify-content-center" style="width:44px;height:44px;background:rgba(155,89,182,0.15);"><i class="ti ti-tag" style="font-size:1.25rem;color:#9b59b6"></i></div>
                <div class="kpi-value" style="color:#9b59b6">£<?= number_format($valueCompare['at_msrp'] ?? 0, 0) ?></div>
                <div class="kpi-label">At MSRP</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="kpi-card">
                <div class="metric-icon mx-auto mb-2 rounded d-flex align-items-center justify-content-center" style="width:44px;height:44px;background:rgba(46,204,113,0.15);"><i class="ti ti-discount" style="font-size:1.25rem;color:#2ecc71"></i></div>
                <div class="kpi-value text-success">£<?= number_format($valueCompare['at_trade'] ?? 0, 0) ?></div>
                <div class="kpi-label">At Trade</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="kpi-card">
                <div class="metric-icon mx-auto mb-2 rounded d-flex align-items-center justify-content-center" style="width:44px;height:44px;background:rgba(241,196,15,0.2);"><i class="ti ti-users" style="font-size:1.25rem;color:#f1c40f"></i></div>
                <div class="kpi-value text-warning"><?= $summary['match_rate'] ?>%</div>
                <div class="kpi-label">Vendor Match</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="kpi-card">
                <div class="metric-icon mx-auto mb-2 rounded d-flex align-items-center justify-content-center" style="width:44px;height:44px;background:rgba(52,73,94,0.15);"><i class="ti ti-building-store" style="font-size:1.25rem;color:#34495e"></i></div>
                <div class="kpi-value" style="color:#34495e"><?= $summary['distributors'] ?></div>
                <div class="kpi-label">Distributors</div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-lg-4">
            <div class="chart-card">
                <div class="card-header"><i class="ti ti-chart-pie me-2"></i>Value Comparison</div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="valueChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="chart-card">
                <div class="card-header"><i class="ti ti-gauge me-2"></i>Vendor Match Rate</div>
                <div class="card-body">
                    <div class="speedometer-container">
                        <div id="matchGauge"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="chart-card">
                <div class="card-header"><i class="ti ti-chart-bar me-2"></i>Top Distributors</div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="distChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-6">
            <div class="chart-card">
                <div class="card-header"><i class="ti ti-table me-2"></i>Top Distributors by Value (Detail)</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped table-bordered mb-0">
                            <thead class="table-light"><tr><th>Distributor</th><th>Rows</th><th>Total</th></tr></thead>
                            <tbody>
                                <?php foreach ($topDistributors as $r): ?>
                                <tr>
                                    <td><?= htmlspecialchars($r['distributor_name']) ?></td>
                                    <td><?= number_format($r['row_count']) ?></td>
                                    <td>£<?= number_format($r['total'], 0) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($topDistributors)): ?>
                                <tr><td colspan="3" class="text-muted">No data yet. <a href="import.php">Import a report</a>.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="chart-card">
                <div class="card-header"><i class="ti ti-table me-2"></i>Top Matched Resellers (Detail)</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped table-bordered mb-0">
                            <thead class="table-light"><tr><th>Vendor</th><th>Rows</th><th>Total</th></tr></thead>
                            <tbody>
                                <?php foreach ($topResellers as $r): ?>
                                <tr>
                                    <td><?= htmlspecialchars($r['vendor_name']) ?></td>
                                    <td><?= number_format($r['row_count']) ?></td>
                                    <td>£<?= number_format($r['total'], 0) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($topResellers)): ?>
                                <tr><td colspan="3" class="text-muted">No matched vendors yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="chart-card">
        <div class="card-header"><i class="ti ti-history me-2"></i>Recent Imports</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped table-bordered mb-0">
                    <thead class="table-light"><tr><th>File</th><th>Distributor</th><th>Rows</th><th>Imported</th></tr></thead>
                    <tbody>
                        <?php foreach ($recentImports as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['filename']) ?></td>
                            <td><?= htmlspecialchars($r['distributor_name']) ?></td>
                            <td><?= number_format($r['row_count']) ?></td>
                            <td><?= date('d/m/Y H:i', strtotime($r['imported_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recentImports)): ?>
                        <tr><td colspan="4" class="text-muted">No imports yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    function fmt(v) { return '£' + parseFloat(v).toLocaleString('en-GB', { minimumFractionDigits: 0, maximumFractionDigits: 0 }); }
    const colors = <?= json_encode($chartColors) ?>;

    const valEl = document.getElementById('valueChart');
    if (valEl) {
        const labels = <?= $valueCompareLabels ?>;
        const data = <?= $valueCompareData ?>;
        if (data.some(x => x > 0)) {
            new Chart(valEl.getContext('2d'), {
                type: 'doughnut',
                data: { labels: labels, datasets: [{ data: data, backgroundColor: ['#00a399', '#9b59b6', '#2ecc71'], borderColor: 'white', borderWidth: 2 }] },
                options: { responsive: true, maintainAspectRatio: false, cutout: '55%', plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: c => c.label + ': ' + fmt(c.raw) } } } }
            });
        } else valEl.parentElement.innerHTML = '<div class="text-center py-5 text-muted">No value data</div>';
    }

    const matchEl = document.getElementById('matchGauge');
    if (matchEl) {
        const rate = <?= (float)$summary['match_rate'] ?>;
        new ApexCharts(matchEl, {
            series: [rate],
            chart: { height: 200, type: 'radialBar' },
            plotOptions: { radialBar: { startAngle: -135, endAngle: 135, dataLabels: { value: { fontSize: '24px', formatter: () => rate + '%' } } } },
            labels: ['Match Rate'],
            colors: [rate >= 50 ? '#2ecc71' : (rate >= 25 ? '#f39c12' : '#e74c3c')],
            fill: { type: 'gradient', gradient: { shade: 'dark', shadeIntensity: 0.2 } }
        }).render();
    }

    const distEl = document.getElementById('distChart');
    if (distEl) {
        const labels = <?= $distLabels ?>;
        const data = <?= $distData ?>;
        if (labels.length && data.some(x => x > 0)) {
            new Chart(distEl.getContext('2d'), {
                type: 'bar',
                data: { labels: labels, datasets: [{ label: 'Total Value', data: data, backgroundColor: colors.slice(0, labels.length) }] },
                options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => fmt(c.raw) } } }, scales: { x: { beginAtZero: true, ticks: { callback: v => fmt(v) } } } }
            });
        } else distEl.parentElement.innerHTML = '<div class="text-center py-5 text-muted">No distributor data</div>';
    }
});
</script>
</div></div>
</body>
</html>
