<?php
// salesout/index.php - Dashboard with insights
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
ob_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=' . urlencode('salesout/index.php'));
    exit;
}

$pdo = getDBConnection();
require_once __DIR__ . '/header.php';
if (ob_get_level()) { ob_flush(); }
flush();
$summary = ['total_rows' => 0, 'total_value' => 0, 'distributors' => 0, 'match_rate' => 0, 'matched_to_vendor' => 0, 'resellers' => 0, 'skus' => 0];
$topDistributors = [];
$topResellers = [];
$recentImports = [];
$valueCompare = ['dist_reported' => 0, 'at_msrp' => 0, 'at_trade' => 0];
$inventorySummary = null;
$dbError = null;

$year = $_GET['year'] ?? date('Y');
$distributor = $_GET['distributor'] ?? '';

$where = ['1=1'];
$params = [];
if ($year !== '' && preg_match('/^\d{4}$/', $year)) {
    $where[] = 's.report_date BETWEEN ? AND ?';
    $params[] = $year . '-01-01';
    $params[] = $year . '-12-31';
}
if ($distributor !== '') {
    $where[] = 's.distributor_name = ?';
    $params[] = $distributor;
}
$whereClause = implode(' AND ', $where);

try {
    $years = $pdo->query("SELECT DISTINCT YEAR(report_date) as y FROM sales_out_raw WHERE report_date IS NOT NULL ORDER BY y DESC LIMIT 15")->fetchAll(PDO::FETCH_COLUMN);
    $distributors = $pdo->query("SELECT DISTINCT distributor_name FROM sales_out_raw WHERE distributor_name != '' ORDER BY distributor_name LIMIT 500")->fetchAll(PDO::FETCH_COLUMN);

    $summarySql = "
        SELECT 
            COUNT(*) as total_rows,
            COUNT(DISTINCT s.distributor_name) as distributors,
            COUNT(DISTINCT s.reseller_name) as resellers,
            COUNT(DISTINCT s.sku) as skus,
            COALESCE(SUM(s.total_value), 0) as total_value,
            SUM(CASE WHEN s.matched_vendor_id IS NOT NULL THEN 1 ELSE 0 END) as matched_to_vendor
        FROM sales_out_raw s
        WHERE $whereClause
    ";
    $summaryStmt = $pdo->prepare($summarySql);
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

    $valueSql = "
        SELECT
            COALESCE(SUM(s.total_value), 0) as dist_reported,
            COALESCE(SUM(s.quantity * p.msrp), 0) as at_msrp,
            COALESCE(SUM(s.quantity * p.trade_price), 0) as at_trade
        FROM sales_out_raw s
        LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
        WHERE $whereClause
    ";
    $valueStmt = $pdo->prepare($valueSql);
    $valueStmt->execute($params);
    $valueCompare = $valueStmt->fetch(PDO::FETCH_ASSOC);

    // Amplify partner level (like budget system) - sales by AMPLIFY_Level__c from vendors
    $amplifyByLevel = [];
    try {
        $amplifyCols = $pdo->query("SHOW COLUMNS FROM vendors LIKE 'AMPLIFY_Level__c'")->fetch();
        if ($amplifyCols) {
            $amplifyStmt = $pdo->prepare("
                SELECT COALESCE(NULLIF(TRIM(v.AMPLIFY_Level__c), ''), 'Other') as amplify_level, COALESCE(SUM(s.total_value), 0) as total
                FROM sales_out_raw s
                LEFT JOIN vendors v ON s.matched_vendor_id = v.id
                WHERE $whereClause
                GROUP BY COALESCE(NULLIF(TRIM(v.AMPLIFY_Level__c), ''), 'Other')
                ORDER BY total DESC
            ");
            $amplifyStmt->execute($params);
            $amplifyByLevel = $amplifyStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) { /* column may not exist */ }

    $summary['match_rate'] = $summary['total_rows'] > 0 
        ? round(100 * $summary['matched_to_vendor'] / $summary['total_rows'], 1) 
        : 0;

    $topDistSql = "
        SELECT s.distributor_name as distributor_name,
            COUNT(*) as row_count,
            COALESCE(SUM(s.total_value), 0) as dist_reported,
            COALESCE(SUM(s.quantity * p.trade_price), 0) as at_trade,
            COALESCE(SUM(s.quantity * p.msrp), 0) as at_msrp
        FROM sales_out_raw s
        LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
        WHERE $whereClause
        GROUP BY s.distributor_name
        ORDER BY dist_reported DESC LIMIT 10
    ";
    $topDistStmt = $pdo->prepare($topDistSql);
    $topDistStmt->execute($params);
    $topDistributors = $topDistStmt->fetchAll(PDO::FETCH_ASSOC);

    $topResSql = "
        SELECT v.id as vendor_id, v.vendor_name,
            COUNT(*) as row_count,
            COALESCE(SUM(s.total_value), 0) as dist_reported,
            COALESCE(SUM(s.quantity * p.trade_price), 0) as at_trade,
            COALESCE(SUM(s.quantity * p.msrp), 0) as at_msrp
        FROM sales_out_raw s
        INNER JOIN vendors v ON s.matched_vendor_id = v.id
        LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
        WHERE $whereClause
        GROUP BY s.matched_vendor_id, v.id, v.vendor_name
        ORDER BY dist_reported DESC LIMIT 10
    ";
    $topResStmt = $pdo->prepare($topResSql);
    $topResStmt->execute($params);
    $topResellers = $topResStmt->fetchAll(PDO::FETCH_ASSOC);

    $recentImports = $pdo->query("
        SELECT * FROM sales_out_imports ORDER BY imported_at DESC LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    $inventorySummary = getInventorySummary($pdo, $distributor ?: '', 8);

    // Sales target vs actual (when year selected)
    $targetInfo = null;
    if ($year !== '' && preg_match('/^\d{4}$/', $year)) {
        try {
            $targetStmt = null;
            if ($distributor !== '') {
                $targetStmt = $pdo->prepare("SELECT annual_target FROM sales_out_targets WHERE target_type = 'distributor' AND entity_key = ? AND year = ?");
                $targetStmt->execute([$distributor, (int)$year]);
            } else {
                $targetStmt = $pdo->prepare("SELECT SUM(annual_target) as annual_target FROM sales_out_targets WHERE target_type = 'distributor' AND year = ?");
                $targetStmt->execute([(int)$year]);
            }
            $row = $targetStmt->fetch(PDO::FETCH_ASSOC);
            if ($row && (float)($row['annual_target'] ?? 0) > 0) {
                $seasonality = getSeasonalityPercentages($pdo);
                $timeElapsed = getTimeElapsedForYear((int)$year);
                $targetToDate = getTargetToDate((float)$row['annual_target'], $seasonality, (int)$year);
                $targetInfo = [
                    'annual_target' => (float)$row['annual_target'],
                    'actual' => (float)($valueCompare['dist_reported'] ?? 0),
                    'label' => $distributor ? $distributor : 'All distributors',
                    'target_to_date' => $targetToDate,
                    'time_elapsed' => $timeElapsed,
                ];
                $targetInfo['pct'] = $targetInfo['annual_target'] > 0 ? round(100 * $targetInfo['actual'] / $targetInfo['annual_target'], 1) : 0;
                $targetInfo['vs_time'] = $targetToDate > 0 ? round(100 * $targetInfo['actual'] / $targetToDate, 1) : ($targetInfo['actual'] > 0 ? 100 : 0);
                $targetInfo['pct_ahead'] = $targetToDate > 0 ? round(100 * ($targetInfo['actual'] / $targetToDate - 1), 1) : 0; // + ahead, - behind
                $targetInfo['ref_line_pct'] = $targetInfo['annual_target'] > 0 ? min(99, round(100 * $targetToDate / $targetInfo['annual_target'], 1)) : 0; // position of "on track" line

                // Monthly data for quarterly breakdown & forecast
                $monthlyStmt = $pdo->prepare("
                    SELECT MONTH(s.report_date) as mo, COALESCE(SUM(s.total_value), 0) as total
                    FROM sales_out_raw s
                    WHERE $whereClause
                    GROUP BY mo ORDER BY mo
                ");
                $monthlyStmt->execute($params);
                $byMonth = [];
                foreach ($monthlyStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $byMonth[(int)$r['mo']] = (float)$r['total'];
                }
                $targetInfo['by_month'] = $byMonth;

                // Quarterly targets (seasonality)
                $targetInfo['q_targets'] = [
                    1 => round($targetInfo['annual_target'] * (($seasonality[1] + $seasonality[2] + $seasonality[3]) / 100), 0),
                    2 => round($targetInfo['annual_target'] * (($seasonality[4] + $seasonality[5] + $seasonality[6]) / 100), 0),
                    3 => round($targetInfo['annual_target'] * (($seasonality[7] + $seasonality[8] + $seasonality[9]) / 100), 0),
                    4 => round($targetInfo['annual_target'] * (($seasonality[10] + $seasonality[11] + $seasonality[12]) / 100), 0),
                ];
                $targetInfo['q_actuals'] = [
                    1 => ($byMonth[1] ?? 0) + ($byMonth[2] ?? 0) + ($byMonth[3] ?? 0),
                    2 => ($byMonth[4] ?? 0) + ($byMonth[5] ?? 0) + ($byMonth[6] ?? 0),
                    3 => ($byMonth[7] ?? 0) + ($byMonth[8] ?? 0) + ($byMonth[9] ?? 0),
                    4 => ($byMonth[10] ?? 0) + ($byMonth[11] ?? 0) + ($byMonth[12] ?? 0),
                ];
                // Year-end forecast: run-rate extrapolation
                $te = $targetInfo['time_elapsed'];
                $targetInfo['year_end_forecast'] = $te['days_elapsed'] > 0 ? round($targetInfo['actual'] * 365 / $te['days_elapsed'], 0) : $targetInfo['actual'];
            }
        } catch (PDOException $e) { /* targets table may not exist */ }
    }
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

// Chart data (use selected value mode for bar chart values)
$chartValueKey = getSalesOutValueCompareKey(getSalesOutValueMode());
$distLabels = json_encode(array_column($topDistributors, 'distributor_name'));
$distData = json_encode(array_map('floatval', array_column($topDistributors, $chartValueKey)));
$resellerLabels = json_encode(array_column($topResellers, 'vendor_name'));
$resellerData = json_encode(array_map('floatval', array_column($topResellers, $chartValueKey)));
// Value Comparison: Amplify level if available, else fallback to At MSRP / At Trade
if (!empty($amplifyByLevel)) {
    $valueCompareLabels = json_encode(array_column($amplifyByLevel, 'amplify_level'));
    $valueCompareData = json_encode(array_map('floatval', array_column($amplifyByLevel, 'total')));
    $valueCompareChartTitle = 'Sales by AMPLIFY Level';
} else {
    $valueCompareLabels = json_encode(['At MSRP', 'At Trade']);
    $valueCompareData = json_encode([
        (float)($valueCompare['at_msrp'] ?? 0),
        (float)($valueCompare['at_trade'] ?? 0)
    ]);
    $valueCompareChartTitle = 'Value Comparison';
}
$chartColors = ['#00a399', '#00353d', '#ff5549', '#666666', '#cccccc', '#f59e0b', '#00a399', '#00353d', '#ff5549', '#666666'];

if (!empty($dbError)) {
    echo '<div class="container-xl py-4"><div class="alert alert-danger">Database error: ' . htmlspecialchars($dbError) . '</div></div></div></div></body></html>';
    exit;
}
?>
<style>
.dashboard-header { background: linear-gradient(135deg, #00353d 0%, #00a399 100%); color: white; padding: 2rem 0; margin-bottom: 1.5rem; border-radius: 10px; box-shadow: 0 4px 14px rgba(0, 163, 153, 0.25); }
.kpi-card { text-align: center; padding: 1.25rem; border-radius: 10px; background: white; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05); transition: transform 0.2s ease; height: 100%; }
.kpi-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
.kpi-value { font-size: 1.75rem; font-weight: 700; line-height: 1.2; margin-bottom: 0.25rem; }
.kpi-label { font-size: 0.75rem; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 500; }
.chart-card { border-radius: 10px; border: 1px solid #D7D2CB; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 1.5rem; background: white; }
.chart-card .card-header { background: rgb(238, 239, 241); border-bottom: 1px solid #D7D2CB; padding: 1rem 1.25rem; font-weight: 600; color: #0f172a; }
/* Combined target performance component */
.target-combined { background: white; border-radius: 10px; padding: 1.5rem; border: 1px solid #D7D2CB; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 1rem; }
.target-combined-main { display: flex; gap: 1.5rem; align-items: flex-start; flex-wrap: wrap; }
.target-combined-left { flex: 1; min-width: 0; }
.target-combined-right { flex: 0 0 25%; min-width: 140px; text-align: center; padding: 1rem; border-radius: 8px; }
.target-combined-value { font-size: 2.25rem; font-weight: 700; line-height: 1.2; margin-bottom: 0.5rem; }
.target-combined-label { font-size: 0.8rem; color: #6b7280; margin-bottom: 0.75rem; }
.target-combined-bar-wrap { position: relative; height: 12px; background: #e5e7eb; border-radius: 6px; overflow: visible; margin-bottom: 0.5rem; }
.target-combined-bar-fill { position: absolute; left: 0; top: 0; height: 100%; border-radius: 6px; transition: width 0.4s ease; z-index: 1; }
.target-combined-ref-line { position: absolute; top: -2px; bottom: -2px; width: 2px; background: rgba(0,0,0,0.5); z-index: 2; }
.target-combined-meta { font-size: 0.75rem; color: #9ca3af; display: flex; flex-wrap: wrap; gap: 0.5rem 1rem; align-items: center; }
.target-combined-pct { font-size: 1.75rem; font-weight: 700; }
.target-combined-pct-label { font-size: 0.75rem; color: #6b7280; }
.target-forecast { display: grid; grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); gap: 0.75rem; margin-top: 1.25rem; padding-top: 1.25rem; border-top: 1px solid #D7D2CB; }
.target-forecast-item { text-align: center; padding: 0.75rem; background: rgb(238, 239, 241); border-radius: 8px; }
.target-forecast-item-label { font-size: 0.7rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.25rem; }
.target-forecast-item-value { font-size: 1.1rem; font-weight: 600; }
.target-forecast-item-sub { font-size: 0.7rem; color: #9ca3af; }
.chart-container { position: relative; height: 280px; width: 100%; }
.speedometer-container { height: 200px; width: 100%; }
.dashboard-body { background: rgb(238, 239, 241); }
.filter-card { background: white; border: 1px solid #D7D2CB; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 1.5rem; overflow: hidden; }
.filter-card .card-header { background: #f8fafc; border-bottom: 1px solid #D7D2CB; padding: 1rem 1.25rem; font-weight: 600; color: #0f172a; }
.filter-card .card-body { padding: 1.25rem 1.5rem; }
.btn-filter-reset { border: 1px dashed #dee2e6; color: #6c757d; }
.btn-filter-reset:hover { border-color: #00a399; color: #00a399; }
</style>

<div class="container-xl py-4 dashboard-body">
    <div class="filter-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div><i class="ti ti-filter me-2"></i>Filters</div>
            <a href="index.php" class="btn btn-sm btn-filter-reset"><i class="ti ti-refresh me-1"></i> Reset</a>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label class="form-label">Year</label>
                    <select name="year" class="form-select">
                        <option value="">All years</option>
                        <?php foreach ($years as $y): ?>
                        <option value="<?= (int)$y ?>" <?= $year === (string)$y ? 'selected' : '' ?>><?= (int)$y ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
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
                    <button type="submit" class="btn btn-primary"><i class="ti ti-filter me-1"></i> Apply</button>
                </div>
            </form>
        </div>
    </div>

    <div class="dashboard-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <div class="d-flex align-items-center mb-2">
                        <i class="ti ti-dashboard me-2" style="font-size: 1.5rem; color: white;"></i>
                        <h1 class="h2 mb-0">Sales Out Dashboard</h1>
                    </div>
                    <p class="mb-0 opacity-75">
                        Standardised distributor sales data. Value columns use product master (MSRP/Trade) when SKU matches.
                        <?php if ($year !== '' || $distributor !== ''): ?>
                        <span class="d-inline-block mt-1">— Showing <?= $year ? $year : 'all years' ?><?= $distributor ? ' · ' . htmlspecialchars($distributor) : '' ?></span>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="col-md-4 text-md-end">
                    <a href="import.php" class="btn btn-outline-light me-2"><i class="ti ti-upload me-1"></i> Import</a>
                    <a href="targets.php" class="btn btn-outline-light me-2"><i class="ti ti-target me-1"></i> Targets</a>
                    <a href="insights.php" class="btn btn-outline-light"><i class="ti ti-chart-line me-1"></i> Insights</a>
                    <a href="product_portfolio.php" class="btn btn-outline-light"><i class="ti ti-chart-bubble me-1"></i> Product Portfolio</a>
                    <a href="executive_summary.php" class="btn btn-outline-light"><i class="ti ti-file-report me-1"></i> Executive Summary</a>
                </div>
            </div>
        </div>
    </div>

    <?php
    $valueMode = getSalesOutValueMode();
    $valueKey = getSalesOutValueCompareKey($valueMode);
    $valueTooltip = getSalesOutValueModeTooltip($valueMode);
    ?>
    <div class="row mb-4">
        <div class="col-md-2">
            <div class="kpi-card">
                <div class="metric-icon mx-auto mb-2 rounded d-flex align-items-center justify-content-center" style="width:44px;height:44px;background:rgba(0,163,153,0.15);"><i class="ti ti-shopping-cart" style="font-size:1.25rem;color:#00a399"></i></div>
                <div class="kpi-value" style="color:#00a399"><?= number_format($summary['total_rows']) ?></div>
                <div class="kpi-label">Total Sales</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="kpi-card" title="<?= htmlspecialchars(getSalesOutValueModeTooltip('msrp')) ?>">
                <div class="metric-icon mx-auto mb-2 rounded d-flex align-items-center justify-content-center" style="width:44px;height:44px;background:rgba(155,89,182,0.15);"><i class="ti ti-tag" style="font-size:1.25rem;color:#9b59b6"></i></div>
                <div class="kpi-value" style="color:#9b59b6">£<?= number_format($valueCompare['at_msrp'] ?? 0, 0) ?></div>
                <div class="kpi-label">At MSRP</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="kpi-card" title="<?= htmlspecialchars(getSalesOutValueModeTooltip('disti')) ?>">
                <div class="metric-icon mx-auto mb-2 rounded d-flex align-items-center justify-content-center" style="width:44px;height:44px;background:rgba(255,85,73,0.12);"><i class="ti ti-report-money" style="font-size:1.25rem;color:#ff5549"></i></div>
                <div class="kpi-value" style="color:#ff5549">£<?= number_format($valueCompare['dist_reported'] ?? 0, 0) ?></div>
                <div class="kpi-label">Dist. Reported</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="kpi-card" title="<?= htmlspecialchars(getSalesOutValueModeTooltip('trade')) ?>">
                <div class="metric-icon mx-auto mb-2 rounded d-flex align-items-center justify-content-center" style="width:44px;height:44px;background:rgba(0,163,153,0.12);"><i class="ti ti-discount" style="font-size:1.25rem;color:#00a399"></i></div>
                <div class="kpi-value" style="color:#00a399">£<?= number_format($valueCompare['at_trade'] ?? 0, 0) ?></div>
                <div class="kpi-label">At Trade</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="kpi-card">
                <div class="metric-icon mx-auto mb-2 rounded d-flex align-items-center justify-content-center" style="width:44px;height:44px;background:rgba(0,53,61,0.12);"><i class="ti ti-cloud" style="font-size:1.25rem;color:#00353d"></i></div>
                <div class="kpi-value" style="color:#00353d"><?= $summary['match_rate'] ?>%</div>
                <div class="kpi-label">Salesforce Synced</div>
            </div>
        </div>
        <?php if (!empty($targetInfo)): ?>
        <div class="col-md-2">
            <a href="#target-perf" class="text-decoration-none">
            <div class="kpi-card">
                <div class="metric-icon mx-auto mb-2 rounded d-flex align-items-center justify-content-center" style="width:44px;height:44px;background:rgba(0,163,153,0.15);"><i class="ti ti-target" style="font-size:1.25rem;color:#00a399"></i></div>
                <div class="kpi-value" style="color: <?= $targetInfo['pct'] >= 100 ? '#00a399' : ($targetInfo['pct'] >= 80 ? '#f59e0b' : '#ff5549') ?>"><?= $targetInfo['pct'] ?>%</div>
                <div class="kpi-label">Target</div>
            </div>
            </a>
        </div>
        <?php endif; ?>
        <?php if ($inventorySummary !== null): ?>
        <div class="col-md-2">
            <a href="inventory_report.php" class="text-decoration-none">
            <div class="kpi-card">
                <div class="metric-icon mx-auto mb-2 rounded d-flex align-items-center justify-content-center" style="width:44px;height:44px;background:rgba(0,53,61,0.12);"><i class="ti ti-package" style="font-size:1.25rem;color:#00353d"></i></div>
                <div class="kpi-value" style="color:#00353d">£<?= number_format($inventorySummary['total_value'], 0) ?></div>
                <div class="kpi-label">Inventory (trade)</div>
            </div>
            </a>
        </div>
        <div class="col-md-2">
            <a href="inventory_report.php" class="text-decoration-none">
            <div class="kpi-card">
                <div class="metric-icon mx-auto mb-2 rounded d-flex align-items-center justify-content-center" style="width:44px;height:44px;background:rgba(0,53,61,0.12);"><i class="ti ti-box" style="font-size:1.25rem;color:#00353d"></i></div>
                <div class="kpi-value" style="color:#00353d"><?= number_format($inventorySummary['total_units'], 0) ?></div>
                <div class="kpi-label">Units on hand</div>
            </div>
            </a>
        </div>
        <div class="col-md-2">
            <a href="inventory_report.php" class="text-decoration-none">
            <div class="kpi-card">
                <div class="metric-icon mx-auto mb-2 rounded d-flex align-items-center justify-content-center" style="width:44px;height:44px;background:rgba(0,53,61,0.12);"><i class="ti ti-clock" style="font-size:1.25rem;color:#00353d"></i></div>
                <div class="kpi-value" style="color:#00353d"><?= $inventorySummary['avg_weeks'] !== null ? number_format($inventorySummary['avg_weeks'], 1) . 'w' : '—' ?></div>
                <div class="kpi-label">Avg weeks of stock</div>
            </div>
            </a>
        </div>
        <?php endif; ?>
    </div>
    <?php if (!empty($targetInfo)): ?>
    <div class="target-combined" id="target-perf">
        <div class="target-combined-main">
            <div class="target-combined-left">
                <div class="target-combined-value" style="color: <?= $targetInfo['pct'] >= 100 ? '#00a399' : ($targetInfo['pct'] >= 80 ? '#f59e0b' : '#ff5549') ?>" title="By Distributor reported (target comparison uses dist. reported)">£<?= number_format($targetInfo['actual'], 0) ?></div>
                <div class="target-combined-label">Sales Out — <?= htmlspecialchars($targetInfo['label']) ?> (<?= (int)$year ?>)</div>
                <div class="target-combined-bar-wrap">
                    <div class="target-combined-bar-fill" style="width: <?= min(100, max(0, $targetInfo['pct'])) ?>%; background: <?= $targetInfo['pct'] >= 100 ? '#00a399' : ($targetInfo['pct'] >= 80 ? '#f59e0b' : '#ff5549') ?>;"></div>
                    <?php if (($targetInfo['time_elapsed']['is_current_year'] ?? false) && ($targetInfo['ref_line_pct'] ?? 0) > 0 && ($targetInfo['ref_line_pct'] ?? 0) < 100): ?>
                    <div class="target-combined-ref-line" style="left: <?= $targetInfo['ref_line_pct'] ?>%;" title="On-track level (£<?= number_format($targetInfo['target_to_date'], 0) ?>)"></div>
                    <?php endif; ?>
                </div>
                <div class="target-combined-meta">
                    <span><i class="ti ti-target"></i> Annual target: £<?= number_format($targetInfo['annual_target'], 0) ?></span>
                    <?php if ($targetInfo['time_elapsed']['is_current_year'] ?? false): ?>
                    <span class="ms-2"><i class="ti ti-line-dashed"></i> On-track line: £<?= number_format($targetInfo['target_to_date'], 0) ?></span>
                    <?php endif; ?>
                    <span style="margin-left: auto;"><i class="ti ti-clock"></i> <?= date('d.m.Y') ?></span>
                </div>
            </div>
            <?php if ($targetInfo['time_elapsed']['is_current_year'] ?? false): ?>
            <div class="target-combined-right" style="background: <?= ($targetInfo['pct_ahead'] ?? 0) >= 0 ? 'rgba(0,163,153,0.12)' : 'rgba(255,85,73,0.12)' ?>;">
                <div class="target-combined-pct" style="color: <?= ($targetInfo['pct_ahead'] ?? 0) >= 0 ? '#00a399' : '#ff5549' ?>">
                    <?= ($targetInfo['pct_ahead'] ?? 0) >= 0 ? '+' : '' ?><?= $targetInfo['pct_ahead'] ?? 0 ?>%
                </div>
                <div class="target-combined-pct-label"><?= ($targetInfo['pct_ahead'] ?? 0) >= 0 ? 'Ahead of target' : 'Behind target' ?></div>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($targetInfo['time_elapsed']['is_current_year'] ?? false): ?>
        <div class="target-forecast">
            <?php foreach ([1=>'Q1', 2=>'Q2', 3=>'Q3', 4=>'Q4'] as $q => $qlbl): 
                $qAct = $targetInfo['q_actuals'][$q] ?? 0;
                $qTgt = $targetInfo['q_targets'][$q] ?? 0;
                $qPct = $qTgt > 0 ? round(100 * $qAct / $qTgt, 0) : 0;
            ?>
            <div class="target-forecast-item">
                <div class="target-forecast-item-label"><?= $qlbl ?></div>
                <div class="target-forecast-item-value">£<?= number_format($qAct, 0) ?></div>
                <div class="target-forecast-item-sub">Target £<?= number_format($qTgt, 0) ?> (<?= $qPct ?>%)</div>
            </div>
            <?php endforeach; ?>
            <div class="target-forecast-item" style="background: rgba(0,163,153,0.08);">
                <div class="target-forecast-item-label">Year-end forecast</div>
                <div class="target-forecast-item-value">£<?= number_format($targetInfo['year_end_forecast'] ?? 0, 0) ?></div>
                <div class="target-forecast-item-sub">Run-rate from YTD</div>
            </div>
        </div>
        <?php endif; ?>
        <p class="mb-0 mt-3 small text-muted"><a href="targets.php"><i class="ti ti-settings me-1"></i>Edit targets</a></p>
    </div>
    <?php endif; ?>
    <?php if (empty($targetInfo) && $year !== '' && preg_match('/^\d{4}$/', $year)): ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="alert alert-info mb-0"><i class="ti ti-info-circle me-2"></i>Select a year to see target vs actual. <a href="targets.php">Set targets</a> for <?= htmlspecialchars($year) ?> to track progress.</div>
        </div>
    </div>
    <?php endif; ?>

    <div class="row mb-4">
        <div class="col-lg-4">
            <div class="chart-card">
                <div class="card-header"><i class="ti ti-chart-pie me-2"></i><?= $valueCompareChartTitle ?? 'Value Comparison' ?></div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="valueChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="chart-card">
                <div class="card-header"><i class="ti ti-cloud me-2"></i>Salesforce Synced</div>
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
                            <thead class="table-light"><tr><th>Distributor</th><th>Sales</th><th title="<?= htmlspecialchars($valueTooltip) ?>">Total</th></tr></thead>
                            <tbody>
                                <?php foreach ($topDistributors as $r): ?>
                                <tr>
                                    <td><?= htmlspecialchars($r['distributor_name']) ?></td>
                                    <td><?= number_format($r['row_count']) ?></td>
                                    <td title="<?= htmlspecialchars($valueTooltip) ?>">£<?= number_format($r[$valueKey] ?? 0, 0) ?></td>
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
                            <thead class="table-light"><tr><th>Vendor</th><th>Sales</th><th title="<?= htmlspecialchars($valueTooltip) ?>">Total</th></tr></thead>
                            <tbody>
                                <?php foreach ($topResellers as $r): ?>
                                <tr>
                                    <td><a href="reseller_report.php?vendor_id=<?= (int)($r['vendor_id'] ?? 0) ?>"><?= htmlspecialchars($r['vendor_name']) ?></a></td>
                                    <td><?= number_format($r['row_count']) ?></td>
                                    <td title="<?= htmlspecialchars($valueTooltip) ?>">£<?= number_format($r[$valueKey] ?? 0, 0) ?></td>
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
                    <thead class="table-light"><tr><th>File</th><th>Distributor</th><th>Sales</th><th>Imported</th></tr></thead>
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

<script src="https://cdn.jsdelivr.net/npm/chart.js" defer></script>
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
            const eposColors = ['#00a399', '#00353d', '#ff5549', '#666666', '#cccccc', '#f59e0b'];
            const barColors = labels.length <= eposColors.length ? eposColors.slice(0, labels.length) : colors;
            new Chart(valEl.getContext('2d'), {
                type: 'doughnut',
                data: { labels: labels, datasets: [{ data: data, backgroundColor: barColors, borderColor: 'white', borderWidth: 2 }] },
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
            labels: ['Synced'],
            colors: [rate >= 50 ? '#00a399' : (rate >= 25 ? '#f59e0b' : '#ff5549')],
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
