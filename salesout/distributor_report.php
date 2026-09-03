<?php
// distributor_report.php - Distributor-centric analysis: YoY, seasonality, category shifts, forecasting
session_start();
ob_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=' . urlencode('salesout/distributor_report.php'));
    exit;
}

$pdo = getDBConnection();
require_once __DIR__ . '/header.php';
if (ob_get_level()) { ob_flush(); }
flush();

$distributorName = trim($_GET['distributor'] ?? '');
$forecastMonths = (int) ($_GET['forecast_months'] ?? 6);
$yearsBack = (int) ($_GET['years_back'] ?? 5);
if ($yearsBack < 1 || $yearsBack > 10) $yearsBack = 5;

// Reseller filter by Amplify level
$filterAmplifyLevel = trim($_GET['filter_amplify_level'] ?? '');

// Deal search filters
$dealSearchSku = trim($_GET['deal_search_sku'] ?? '');
$dealSearchProduct = trim($_GET['deal_search_product'] ?? '');
$dealSearchReseller = trim($_GET['deal_search_reseller'] ?? '');
$dealDateFrom = $_GET['deal_date_from'] ?? date('Y-m-01', strtotime('-6 months'));
$dealDateTo = $_GET['deal_date_to'] ?? date('Y-m-d');

$dateFrom = date('Y-m-01', strtotime("-$yearsBack years"));
$dateTo = date('Y-m-d');

$params = [$dateFrom, $dateTo];
$where = "s.report_date BETWEEN ? AND ?";
if ($distributorName !== '') {
    $where .= " AND s.distributor_name = ?";
    $params[] = $distributorName;
}

$hasProductFamily = false;
try {
    $hasProductFamily = (bool) $pdo->query("SHOW COLUMNS FROM sales_out_products LIKE 'product_family'")->fetch();
} catch (PDOException $e) { /* ignore */ }

$distributorsWithSales = [];
$monthlyByYear = [];
$seasonality = [];
$categoryByYear = [];
$productLineByYear = [];
$yearTotals = [];
$forecast = null;
$totalValue = 0;
$recentTopDeals = [];
$topProducts = [];
$topResellers = [];
$amplifyByLevel = [];
$inventorySummary = null;
$dbError = null;

try {
    $distributorsStmt = $pdo->prepare("
        SELECT s.distributor_name,
            COALESCE(SUM(s.total_value), 0) as dist_reported,
            COALESCE(SUM(s.quantity * p.trade_price), 0) as at_trade,
            COALESCE(SUM(s.quantity * p.msrp), 0) as at_msrp
        FROM sales_out_raw s
        LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
        WHERE s.report_date >= ?
        GROUP BY s.distributor_name
        ORDER BY dist_reported DESC
    ");
    $distributorsStmt->execute([$dateFrom]);
    $distributorsWithSales = $distributorsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Monthly sales by year (for YoY overlay chart)
    $stmt = $pdo->prepare("
        SELECT YEAR(s.report_date) as yr, MONTH(s.report_date) as mo,
            COALESCE(SUM(s.total_value), 0) as dist_reported
        FROM sales_out_raw s
        WHERE $where
        GROUP BY yr, mo
        ORDER BY yr, mo
    ");
    $stmt->execute($params);
    $rawMonthly = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $years = array_unique(array_column($rawMonthly, 'yr'));
    sort($years);
    $monthLabels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    foreach ($years as $yr) {
        $monthlyByYear[$yr] = array_fill(1, 12, 0);
        foreach ($rawMonthly as $r) {
            if ((int)$r['yr'] === (int)$yr) {
                $monthlyByYear[$yr][(int)$r['mo']] = (float)$r['dist_reported'];
            }
        }
    }

    // Seasonality: average sales per month across all years
    $stmt = $pdo->prepare("
        SELECT mo, AVG(month_val) as avg_val
        FROM (
            SELECT YEAR(report_date) as yr, MONTH(report_date) as mo, SUM(total_value) as month_val
            FROM sales_out_raw s
            WHERE $where
            GROUP BY yr, mo
        ) sub
        GROUP BY mo
        ORDER BY mo
    ");
    $stmt->execute($params);
    $seasonRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $seasonality = array_fill(1, 12, 0);
    foreach ($seasonRaw as $r) {
        $seasonality[(int)$r['mo']] = (float)($r['avg_val'] ?? 0);
    }

    // Year totals (all three value bases)
    $stmt = $pdo->prepare("
        SELECT YEAR(s.report_date) as yr,
            COALESCE(SUM(s.total_value), 0) as dist_reported,
            COALESCE(SUM(s.quantity * p.trade_price), 0) as at_trade,
            COALESCE(SUM(s.quantity * p.msrp), 0) as at_msrp
        FROM sales_out_raw s
        LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
        WHERE $where
        GROUP BY yr
        ORDER BY yr
    ");
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $yearTotals[(int)$r['yr']] = [
            'dist_reported' => (float)$r['dist_reported'],
            'at_trade' => (float)$r['at_trade'],
            'at_msrp' => (float)$r['at_msrp'],
        ];
    }

    // Category mix by year
    $stmt = $pdo->prepare("
        SELECT YEAR(s.report_date) as yr,
            COALESCE(p.product_type, p.product_line, p.product_category, 'Uncategorised') as category,
            COALESCE(SUM(s.total_value), 0) as dist_reported
        FROM sales_out_raw s
        LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
        WHERE $where
        GROUP BY yr, category
        ORDER BY yr, dist_reported DESC
    ");
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $yr = (int)$r['yr'];
        if (!isset($categoryByYear[$yr])) $categoryByYear[$yr] = [];
        $categoryByYear[$yr][] = ['category' => $r['category'], 'dist_reported' => (float)$r['dist_reported']];
    }

    // Product line by year
    $stmt = $pdo->prepare("
        SELECT YEAR(s.report_date) as yr,
            COALESCE(p.product_line, p.product_type, 'Other') as product_line,
            COALESCE(SUM(s.total_value), 0) as dist_reported
        FROM sales_out_raw s
        LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
        WHERE $where
        GROUP BY yr, product_line
        ORDER BY yr, dist_reported DESC
    ");
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $yr = (int)$r['yr'];
        if (!isset($productLineByYear[$yr])) $productLineByYear[$yr] = [];
        $productLineByYear[$yr][] = ['product_line' => $r['product_line'], 'dist_reported' => (float)$r['dist_reported']];
    }

    // Portfolio mix by year-month (product_family when available)
    $portfolioByYearMonth = [];
    $portfolioFamilyExpr = $hasProductFamily
        ? "COALESCE(NULLIF(TRIM(p.product_family),''), p.product_series, p.product_line, p.product_type, p.product_category, 'Uncategorised')"
        : "COALESCE(p.product_series, p.product_line, p.product_type, p.product_category, 'Uncategorised')";
    $stmt = $pdo->prepare("
        SELECT YEAR(s.report_date) as yr, MONTH(s.report_date) as mo,
            $portfolioFamilyExpr as family,
            COALESCE(SUM(s.total_value), 0) as dist_reported
        FROM sales_out_raw s
        LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
        WHERE $where
        GROUP BY yr, mo, family
        ORDER BY yr, mo, dist_reported DESC
    ");
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $key = (int)$r['yr'] . '-' . sprintf('%02d', (int)$r['mo']);
        if (!isset($portfolioByYearMonth[$key])) $portfolioByYearMonth[$key] = [];
        $portfolioByYearMonth[$key][] = ['category' => $r['family'], 'dist_reported' => (float)$r['dist_reported']];
    }

    // Portfolio mix by year (for donut)
    $portfolioByYear = [];
    $stmt = $pdo->prepare("
        SELECT YEAR(s.report_date) as yr,
            $portfolioFamilyExpr as family,
            COALESCE(SUM(s.total_value), 0) as dist_reported
        FROM sales_out_raw s
        LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
        WHERE $where
        GROUP BY yr, family
        ORDER BY yr, dist_reported DESC
    ");
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $yr = (int)$r['yr'];
        if (!isset($portfolioByYear[$yr])) $portfolioByYear[$yr] = [];
        $portfolioByYear[$yr][] = ['category' => $r['family'], 'dist_reported' => (float)$r['dist_reported']];
    }

    // Total value (use selected value mode)
    $valueKey = getSalesOutValueCompareKey(getSalesOutValueMode());
    $valueTooltip = getSalesOutValueModeTooltip();
    $totalValue = 0;
    foreach ($yearTotals as $yr => $vals) {
        $totalValue += (float)($vals[$valueKey] ?? 0);
    }

    // Distributor target for current year (when distributor selected)
    $distributorTargetInfo = null;
    $currentYear = (int) date('Y');
    if ($distributorName !== '') {
        try {
            $tstmt = $pdo->prepare("SELECT annual_target FROM sales_out_targets WHERE target_type = 'distributor' AND entity_key = ? AND year = ?");
            $tstmt->execute([$distributorName, $currentYear]);
            $trow = $tstmt->fetch(PDO::FETCH_ASSOC);
            if ($trow && (float)($trow['annual_target'] ?? 0) > 0) {
                $targetSeasonality = getSeasonalityPercentages($pdo);
                $timeElapsed = getTimeElapsedForYear($currentYear);
                $targetToDate = getTargetToDate((float)$trow['annual_target'], $targetSeasonality, $currentYear);
                $actualYtd = (float)($yearTotals[$currentYear]['dist_reported'] ?? 0);
                $distributorTargetInfo = [
                    'annual_target' => (float)$trow['annual_target'],
                    'actual' => $actualYtd,
                    'target_to_date' => $targetToDate,
                    'time_elapsed' => $timeElapsed,
                    'pct' => round(100 * $actualYtd / (float)$trow['annual_target'], 1),
                    'vs_time' => $targetToDate > 0 ? round(100 * $actualYtd / $targetToDate, 1) : 0,
                    'pct_ahead' => $targetToDate > 0 ? round(100 * ($actualYtd / $targetToDate - 1), 1) : 0,
                    'year_end_forecast' => $timeElapsed['days_elapsed'] > 0 ? round($actualYtd * 365 / $timeElapsed['days_elapsed'], 0) : $actualYtd,
                ];
            }
        } catch (PDOException $e) { /* targets table may not exist */ }
    }

    // Seasonal forecast: project from month AFTER last data, using same-month historical average
    if (count($years) >= 1 && $forecastMonths > 0 && $distributorName !== '') {
        $hist = $pdo->prepare("
            SELECT yr, mo, month_val
            FROM (
                SELECT YEAR(report_date) as yr, MONTH(report_date) as mo, SUM(total_value) as month_val
                FROM sales_out_raw s
                WHERE $where
                GROUP BY yr, mo
            ) sub
            ORDER BY yr DESC, mo DESC
            LIMIT 60
        ");
        $hist->execute($params);
        $histData = $hist->fetchAll(PDO::FETCH_ASSOC);
        $byMonth = [];
        $lastYr = null;
        $lastMo = null;
        foreach ($histData as $h) {
            $mo = (int)$h['mo'];
            $yr = (int)$h['yr'];
            if (!isset($byMonth[$mo])) $byMonth[$mo] = [];
            $byMonth[$mo][] = (float)$h['month_val'];
            if ($lastYr === null) { $lastYr = $yr; $lastMo = $mo; }
        }
        $projections = [];
        $d = $lastYr && $lastMo ? new DateTime($lastYr . '-' . sprintf('%02d', $lastMo) . '-01') : new DateTime('now');
        $d->modify('+1 month'); // start from month after last data
        for ($i = 0; $i < $forecastMonths; $i++) {
            $mo = (int)$d->format('n');
            $avg = isset($byMonth[$mo]) && count($byMonth[$mo]) > 0
                ? array_sum($byMonth[$mo]) / count($byMonth[$mo])
                : (array_sum($seasonality) > 0 ? array_sum($seasonality) / 12 : 0);
            $projections[] = [
                'month' => $d->format('Y-m'),
                'label' => $d->format('M Y'),
                'value' => round($avg, 2),
            ];
            $d->modify('+1 month');
        }
        if (!empty($projections)) {
            $forecast = [
                'method' => 'seasonal',
                'projections' => $projections,
                'last_data' => $lastYr && $lastMo ? $lastYr . '-' . sprintf('%02d', $lastMo) : null,
            ];
        }
    }

    // Recent top deals: largest 20 deals in past 6 months (only when distributor selected)
    if ($distributorName !== '') {
        $dealsWhere = "s.distributor_name = ? AND s.report_date >= ? AND s.report_date <= ?";
        $dealsParams = [$distributorName, $dealDateFrom, $dealDateTo];
        
        if (!empty($dealSearchSku)) {
            $dealsWhere .= " AND (s.sku LIKE ? OR p.sku LIKE ?)";
            $skuLike = '%' . $dealSearchSku . '%';
            $dealsParams[] = $skuLike;
            $dealsParams[] = $skuLike;
        }
        if (!empty($dealSearchProduct)) {
            $dealsWhere .= " AND (s.product_name LIKE ? OR p.product_name LIKE ?)";
            $prodLike = '%' . $dealSearchProduct . '%';
            $dealsParams[] = $prodLike;
            $dealsParams[] = $prodLike;
        }
        if (!empty($dealSearchReseller)) {
            $dealsWhere .= " AND (s.reseller_name LIKE ? OR v.vendor_name LIKE ?)";
            $resLike = '%' . $dealSearchReseller . '%';
            $dealsParams[] = $resLike;
            $dealsParams[] = $resLike;
        }
        
        $dealsStmt = $pdo->prepare("
            SELECT 
                s.id,
                s.report_date,
                s.distributor_name,
                s.reseller_name,
                s.matched_vendor_id,
                v.vendor_name as matched_reseller_name,
                s.sku,
                COALESCE(p.product_name, s.product_name) as product_name,
                s.quantity,
                s.unit_price,
                s.total_value,
                s.currency
            FROM sales_out_raw s
            LEFT JOIN vendors v ON s.matched_vendor_id = v.id
            LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
            WHERE $dealsWhere
            ORDER BY s.total_value DESC
            LIMIT 20
        ");
        $dealsStmt->execute($dealsParams);
        $recentTopDeals = $dealsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Top products for this distributor (all time, aggregated by SKU) - limit to 10
        $topProductsStmt = $pdo->prepare("
            SELECT 
                COALESCE(p.sku, s.sku) as sku,
                COALESCE(p.product_name, s.product_name) as product_name,
                p.product_category,
                p.product_line,
                SUM(s.total_value) as total_value,
                SUM(s.quantity) as total_qty,
                COUNT(*) as deal_count
            FROM sales_out_raw s
            LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
            WHERE s.distributor_name = ?
            AND s.sku IS NOT NULL
            AND s.sku != ''
            GROUP BY COALESCE(p.sku, s.sku), COALESCE(p.product_name, s.product_name), p.product_category, p.product_line
            ORDER BY total_value DESC
            LIMIT 10
        ");
        $topProductsStmt->execute([$distributorName]);
        $topProducts = $topProductsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Top resellers for this distributor (matched vendors) - filterable by Amplify level
        $resellersWhere = "s.distributor_name = ?";
        $resellersParams = [$distributorName];
        if (!empty($filterAmplifyLevel)) {
            $resellersWhere .= " AND COALESCE(NULLIF(TRIM(v.AMPLIFY_Level__c), ''), 'Other') = ?";
            $resellersParams[] = $filterAmplifyLevel;
        }
        
        $topResellersStmt = $pdo->prepare("
            SELECT 
                v.vendor_name,
                v.id as vendor_id,
                COALESCE(NULLIF(TRIM(v.AMPLIFY_Level__c), ''), 'Other') as amplify_level,
                SUM(s.total_value) as total_value,
                SUM(s.quantity) as total_qty,
                COUNT(*) as deal_count
            FROM sales_out_raw s
            INNER JOIN vendors v ON s.matched_vendor_id = v.id
            WHERE $resellersWhere
            GROUP BY v.id, v.vendor_name, amplify_level
            ORDER BY total_value DESC
        ");
        $topResellersStmt->execute($resellersParams);
        $topResellers = $topResellersStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Amplify level breakdown for this distributor
        try {
            $amplifyCols = $pdo->query("SHOW COLUMNS FROM vendors LIKE 'AMPLIFY_Level__c'")->fetch();
            if ($amplifyCols) {
                $amplifyStmt = $pdo->prepare("
                    SELECT COALESCE(NULLIF(TRIM(v.AMPLIFY_Level__c), ''), 'Other') as amplify_level, 
                           COALESCE(SUM(s.total_value), 0) as total
                    FROM sales_out_raw s
                    INNER JOIN vendors v ON s.matched_vendor_id = v.id
                    WHERE s.distributor_name = ?
                    GROUP BY COALESCE(NULLIF(TRIM(v.AMPLIFY_Level__c), ''), 'Other')
                    ORDER BY total DESC
                ");
                $amplifyStmt->execute([$distributorName]);
                $amplifyByLevel = $amplifyStmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (PDOException $e) { /* column may not exist */ }
    }

    $inventorySummary = getInventorySummary($pdo, $distributorName, 8);
    if ($distributorName !== '') {
        $inventoryTopSkus = getInventoryTopSkus($pdo, $distributorName, 10);
        $inventoryTrend = getInventoryTrend($pdo, $distributorName, 12);
    } else {
        $inventoryTopSkus = [];
        $inventoryTrend = [];
    }

} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

$chartColors = ['#00a399', '#00353d', '#ff5549', '#666666', '#cccccc', '#f59e0b', '#00a399', '#00353d', '#ff5549', '#666666'];
$yoyColors = array_slice($chartColors, 0, count($years));

if (!empty($dbError)) {
    echo '<div class="container-xl py-4"><div class="alert alert-danger">' . htmlspecialchars($dbError) . '</div></div></div></div></body></html>';
    exit;
}
?>
<style>
.report-header { background: linear-gradient(135deg, #00353d 0%, #00a399 100%); color: white; padding: 2rem 0; margin-bottom: 1.5rem; border-radius: 10px; box-shadow: 0 4px 14px rgba(0, 163, 153, 0.25); }
.filter-card { background: white; border: 1px solid #D7D2CB; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 1.5rem; overflow: hidden; }
.filter-card .card-header { background: rgb(238, 239, 241); border-bottom: 1px solid #D7D2CB; padding: 1rem 1.25rem; font-weight: 600; color: #0f172a; }
.filter-card .card-body { padding: 1.25rem 1.5rem; }
.kpi-card { text-align: center; padding: 1.25rem; border-radius: 10px; background: white; border: 1px solid #D7D2CB; box-shadow: 0 1px 3px rgba(0,0,0,0.05); height: 100%; }
.kpi-value { font-size: 1.75rem; font-weight: 700; }
.kpi-label { font-size: 0.75rem; color: #666666; text-transform: uppercase; letter-spacing: 0.5px; }
.chart-card { border-radius: 10px; border: 1px solid #D7D2CB; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 1.5rem; background: white; }
.chart-card .card-header { background: rgb(238, 239, 241); border-bottom: 1px solid #D7D2CB; padding: 1rem 1.25rem; font-weight: 600; color: #0f172a; }
.chart-container { height: 320px; }
.report-body { background: rgb(238, 239, 241); }
.distributor-target-combined { background: white; border-radius: 10px; padding: 1.5rem; border: 1px solid #D7D2CB; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 1.5rem; }
.distributor-target-bar { height: 12px; background: #e5e7eb; border-radius: 6px; overflow: visible; margin-bottom: 0.5rem; }
.distributor-target-ref { position: absolute; top: -2px; bottom: -2px; width: 2px; background: rgba(0,0,0,0.5); }
</style>

<div class="container-xl py-4 report-body">
    <div class="report-header">
        <div class="container-fluid">
            <h1 class="h2 mb-1"><i class="ti ti-report-analytics me-2"></i>Distributor Report</h1>
            <p class="mb-0 opacity-75">Year-on-year tracking, seasonality, category shifts & forecasting by distributor. Uses up to <?= $yearsBack ?> years of data.</p>
        </div>
    </div>

    <div class="filter-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div><i class="ti ti-filter me-2"></i>Filters</div>
            <a href="distributor_report.php" class="btn btn-sm btn-outline-secondary">Reset</a>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Distributor</label>
                    <select name="distributor" class="form-select">
                        <option value="">All distributors</option>
                        <?php foreach ($distributorsWithSales as $d): ?>
                        <option value="<?= htmlspecialchars($d['distributor_name']) ?>" <?= $distributorName === $d['distributor_name'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($d['distributor_name']) ?> (£<?= number_format($d[$valueKey] ?? 0, 0) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Years of data</label>
                    <select name="years_back" class="form-select">
                        <?php for ($i = 2; $i <= 10; $i++): ?>
                        <option value="<?= $i ?>" <?= $yearsBack === $i ? 'selected' : '' ?>><?= $i ?> years</option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Forecast</label>
                    <select name="forecast_months" class="form-select">
                        <option value="0" <?= $forecastMonths === 0 ? 'selected' : '' ?>>Off</option>
                        <option value="3" <?= $forecastMonths === 3 ? 'selected' : '' ?>>3 months</option>
                        <option value="6" <?= $forecastMonths === 6 ? 'selected' : '' ?>>6 months</option>
                        <option value="12" <?= $forecastMonths === 12 ? 'selected' : '' ?>>12 months</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary">Apply</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($inventorySummary !== null): ?>
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="ti ti-package me-2"></i>Inventory <?= $distributorName ? '(' . htmlspecialchars($distributorName) . ')' : '(all distributors)' ?></span>
            <a href="inventory_report.php<?= $distributorName ? '?distributor=' . urlencode($distributorName) : '' ?>" class="btn btn-sm btn-outline-secondary">View full report</a>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <div class="d-flex align-items-center">
                        <div class="rounded-circle d-flex align-items-center justify-content-center me-3" style="width:48px;height:48px;background:rgba(0,53,61,0.12);"><i class="ti ti-currency-pound" style="font-size:1.25rem;color:#00353d"></i></div>
                        <div>
                            <div class="h4 mb-0" style="color:#00353d">£<?= number_format($inventorySummary['total_value'], 0) ?></div>
                            <div class="small text-muted">Total value (trade)</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="d-flex align-items-center">
                        <div class="rounded-circle d-flex align-items-center justify-content-center me-3" style="width:48px;height:48px;background:rgba(0,53,61,0.12);"><i class="ti ti-box" style="font-size:1.25rem;color:#00353d"></i></div>
                        <div>
                            <div class="h4 mb-0" style="color:#00353d"><?= number_format($inventorySummary['total_units'], 0) ?></div>
                            <div class="small text-muted">Units on hand</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="d-flex align-items-center">
                        <div class="rounded-circle d-flex align-items-center justify-content-center me-3" style="width:48px;height:48px;background:rgba(0,53,61,0.12);"><i class="ti ti-clock" style="font-size:1.25rem;color:#00353d"></i></div>
                        <div>
                            <div class="h4 mb-0" style="color:#00353d"><?= $inventorySummary['avg_weeks'] !== null ? number_format($inventorySummary['avg_weeks'], 1) . 'w' : '—' ?></div>
                            <div class="small text-muted">Avg weeks of stock</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="mt-2 small">
                <a href="inventory_trends.php<?= $distributorName ? '?distributor=' . urlencode($distributorName) : '' ?>">Trends</a>
                <span class="mx-2">·</span>
                <a href="inventory_movement.php<?= $distributorName ? '?distributor=' . urlencode($distributorName) : '' ?>">Movement</a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($distributorName): ?>
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="kpi-card">
                <div class="kpi-value text-primary"><?= htmlspecialchars($distributorName) ?></div>
                <div class="kpi-label">Selected distributor</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card" title="<?= htmlspecialchars($valueTooltip) ?>">
                <div class="kpi-value text-info">£<?= number_format($totalValue, 0) ?></div>
                <div class="kpi-label">Total (<?= min($years ?? [0]) ?>–<?= max($years ?? [0]) ?>)</div>
            </div>
        </div>
        <?php if (!empty($distributorTargetInfo)): ?>
        <div class="col-md-2">
            <div class="kpi-card">
                <div class="kpi-value" style="color: <?= $distributorTargetInfo['pct'] >= 100 ? '#00a399' : ($distributorTargetInfo['pct'] >= 80 ? '#f59e0b' : '#ff5549') ?>"><?= $distributorTargetInfo['pct'] ?>%</div>
                <div class="kpi-label">Target <?= $currentYear ?></div>
                <small class="text-muted">£<?= number_format($distributorTargetInfo['actual'], 0) ?> / £<?= number_format($distributorTargetInfo['annual_target'], 0) ?></small>
            </div>
        </div>
        <?php if (($distributorTargetInfo['time_elapsed']['is_current_year'] ?? false)): ?>
        <div class="col-md-2">
            <div class="kpi-card">
                <div class="kpi-value" style="color: <?= $distributorTargetInfo['vs_time'] >= 100 ? '#00a399' : ($distributorTargetInfo['vs_time'] >= 80 ? '#f59e0b' : '#ff5549') ?>"><?= $distributorTargetInfo['vs_time'] ?>%</div>
                <div class="kpi-label">vs Time (<?= $distributorTargetInfo['time_elapsed']['pct'] ?>% elapsed)</div>
                <small class="text-muted">£<?= number_format($distributorTargetInfo['actual'], 0) ?> / £<?= number_format($distributorTargetInfo['target_to_date'], 0) ?> to date</small>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        <?php
        $prevYr = null;
        $yoyPct = null;
        $sortedYrs = array_keys($yearTotals);
        rsort($sortedYrs);
        if (count($sortedYrs) >= 2) {
            $curr = (float)($yearTotals[$sortedYrs[0]][$valueKey] ?? 0);
            $prev = (float)($yearTotals[$sortedYrs[1]][$valueKey] ?? 1);
            $yoyPct = $prev > 0 ? round(100 * ($curr - $prev) / $prev, 1) : 0;
        }
        ?>
        <div class="col-md-3">
            <div class="kpi-card">
                <div class="kpi-value <?= ($yoyPct ?? 0) >= 0 ? 'text-success' : 'text-danger' ?>">
                    <?= $yoyPct !== null ? ($yoyPct >= 0 ? '+' : '') . $yoyPct . '%' : '–' ?>
                </div>
                <div class="kpi-label">YoY growth (latest vs prior)</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card">
                <div class="kpi-value" style="color:#00353d"><?= count($years ?? []) ?></div>
                <div class="kpi-label">Years of data</div>
            </div>
        </div>
    </div>

    <?php if ($distributorName && !empty($distributorTargetInfo)): ?>
    <div class="distributor-target-combined">
        <div class="d-flex flex-wrap gap-3 align-items-start">
            <div class="flex-grow-1" style="min-width: 200px;">
                <div class="fw-bold fs-4" style="color: <?= $distributorTargetInfo['pct'] >= 100 ? '#00a399' : ($distributorTargetInfo['pct'] >= 80 ? '#f59e0b' : '#ff5549') ?>" title="By Distributor reported (target comparison)">£<?= number_format($distributorTargetInfo['actual'], 0) ?></div>
                <div class="small text-muted mb-2">Sales Out — <?= htmlspecialchars($distributorName) ?> (<?= $currentYear ?>)</div>
                <div class="position-relative distributor-target-bar">
                    <div class="h-100 rounded" style="width: <?= min(100, $distributorTargetInfo['pct']) ?>%; background: <?= $distributorTargetInfo['pct'] >= 100 ? '#00a399' : ($distributorTargetInfo['pct'] >= 80 ? '#f59e0b' : '#ff5549') ?>;"></div>
                    <?php if (($distributorTargetInfo['time_elapsed']['is_current_year'] ?? false) && ($distributorTargetInfo['annual_target'] ?? 0) > 0): 
                        $refPct = min(99, 100 * $distributorTargetInfo['target_to_date'] / $distributorTargetInfo['annual_target']);
                    ?>
                    <div class="distributor-target-ref" style="left: <?= $refPct ?>%;" title="On-track: £<?= number_format($distributorTargetInfo['target_to_date'], 0) ?>"></div>
                    <?php endif; ?>
                </div>
                <div class="small text-muted">Target: £<?= number_format($distributorTargetInfo['annual_target'], 0) ?> · <?= ($distributorTargetInfo['time_elapsed']['is_current_year'] ?? false) ? 'To date: £' . number_format($distributorTargetInfo['target_to_date'], 0) : '' ?></div>
            </div>
            <?php if ($distributorTargetInfo['time_elapsed']['is_current_year'] ?? false): ?>
            <div class="text-center px-3 py-2 rounded" style="background: <?= ($distributorTargetInfo['pct_ahead'] ?? 0) >= 0 ? 'rgba(0,163,153,0.12)' : 'rgba(255,85,73,0.12)' ?>;">
                <div class="fw-bold fs-5" style="color: <?= ($distributorTargetInfo['pct_ahead'] ?? 0) >= 0 ? '#00a399' : '#ff5549' ?>"><?= ($distributorTargetInfo['pct_ahead'] ?? 0) >= 0 ? '+' : '' ?><?= $distributorTargetInfo['pct_ahead'] ?? 0 ?>%</div>
                <div class="small" style="color: #666"><?= ($distributorTargetInfo['pct_ahead'] ?? 0) >= 0 ? 'Ahead' : 'Behind' ?> target</div>
                <?php if (!empty($distributorTargetInfo['year_end_forecast'])): ?>
                <div class="small mt-1" style="color: #666">Forecast: £<?= number_format($distributorTargetInfo['year_end_forecast'], 0) ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <p class="mb-0 mt-3 small"><a href="targets.php">Edit targets</a></p>
    </div>
    <?php elseif ($distributorName): ?>
    <div class="alert alert-info mb-4"><i class="ti ti-info-circle me-2"></i><a href="targets.php">Set a distributor target</a> for <?= $currentYear ?> to track performance vs time.</div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($distributorName): ?>
    <div class="chart-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="ti ti-package me-2"></i>Stock position</span>
            <div>
                <a href="inventory_report.php?distributor=<?= urlencode($distributorName) ?>" class="btn btn-sm btn-outline-secondary me-1">Current stock</a>
                <a href="inventory_trends.php?distributor=<?= urlencode($distributorName) ?>" class="btn btn-sm btn-outline-secondary me-1">Trends</a>
                <a href="inventory_movement.php?distributor=<?= urlencode($distributorName) ?>" class="btn btn-sm btn-outline-secondary">Movement</a>
            </div>
        </div>
        <div class="card-body">
            <div class="row">
                <?php if (count($inventoryTrend) >= 2): ?>
                <div class="col-lg-6 mb-4 mb-lg-0">
                    <h6 class="text-muted mb-2">Inventory value over time</h6>
                    <div style="height: 200px;"><canvas id="invTrendChart"></canvas></div>
                </div>
                <?php endif; ?>
                <div class="<?= count($inventoryTrend) >= 2 ? 'col-lg-6' : 'col-12' ?>">
                    <h6 class="text-muted mb-2">Top SKUs by value (current)</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light"><tr><th>SKU</th><th>Product</th><th class="text-end">Qty</th><th class="text-end">Value</th></tr></thead>
                            <tbody>
                                <?php foreach ($inventoryTopSkus as $row): ?>
                                <tr>
                                    <td><a href="product_detail.php?sku=<?= urlencode($row['sku']) ?>"><code><?= htmlspecialchars($row['sku']) ?></code></a></td>
                                    <td class="text-truncate" style="max-width:160px" title="<?= htmlspecialchars($row['sku_description'] ?? '') ?>"><?= htmlspecialchars($row['sku_description'] ?? '—') ?></td>
                                    <td class="text-end"><?= number_format($row['on_hand_qty'], 0) ?></td>
                                    <td class="text-end">£<?= number_format($row['inventory_value'], 0) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($inventoryTopSkus)): ?>
                                <tr><td colspan="4" class="text-muted">No inventory data. <a href="inventory_import.php">Import inventory</a></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($monthlyByYear)): ?>
    <div class="chart-card">
        <div class="card-header"><i class="ti ti-chart-line me-2"></i>Year-on-Year Monthly Sales</div>
        <div class="card-body">
            <div class="chart-container">
                <canvas id="yoyChart"></canvas>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($seasonality) && array_sum($seasonality) > 0): ?>
    <div class="chart-card">
        <div class="card-header"><i class="ti ti-calendar me-2"></i>Seasonality – Average Sales by Month (across years)</div>
        <div class="card-body">
            <div class="chart-container">
                <canvas id="seasonalityChart"></canvas>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($portfolioByYear) || !empty($portfolioByYearMonth)): ?>
    <div class="chart-card">
        <div class="card-header"><i class="ti ti-chart-pie me-2"></i>Portfolio Mix – By product family (how sales are made up & how it changes over time)</div>
        <div class="card-body">
            <div class="row g-4">
                <?php
                $latestYr = !empty($portfolioByYear) ? max(array_keys($portfolioByYear)) : (!empty($portfolioByYearMonth) ? max(array_map(function($k) { return (int)explode('-', $k)[0]; }, array_keys($portfolioByYearMonth))) : null);
                $portfolioLatest = [];
                if ($latestYr) {
                    $portfolioLatest = $portfolioByYear[$latestYr] ?? [];
                    if (empty($portfolioLatest) && !empty($portfolioByYearMonth)) {
                        $agg = [];
                        foreach ($portfolioByYearMonth as $k => $rows) { if ((int)explode('-', $k)[0] === $latestYr) { foreach ($rows as $r) { $c = $r['category']; $agg[$c] = ($agg[$c] ?? 0) + $r['dist_reported']; } } }
                        foreach ($agg as $c => $v) { $portfolioLatest[] = ['category' => $c, 'dist_reported' => $v]; }
                        usort($portfolioLatest, fn($a,$b) => $b['dist_reported'] <=> $a['dist_reported']);
                    }
                }
                $portfolioTotal = array_sum(array_column($portfolioLatest, 'dist_reported'));
                ?>
                <?php if (!empty($portfolioLatest) && $portfolioTotal > 0): ?>
                <div class="col-lg-5">
                    <div class="mb-2 fw-semibold">Composition by product family (<?= $latestYr ?>)</div>
                    <div class="chart-container" style="height: 280px;">
                        <canvas id="portfolioDonutChart"></canvas>
                    </div>
                    <ul class="list-unstyled small mb-0 mt-2">
                        <?php foreach (array_slice($portfolioLatest, 0, 8) as $c): $pct = 100 * ($c['dist_reported'] / $portfolioTotal); ?>
                        <li class="d-flex justify-content-between py-1"><span><?= htmlspecialchars($c['category']) ?></span><span>£<?= number_format($c['dist_reported'], 0) ?> (<?= round($pct, 1) ?>%)</span></li>
                        <?php endforeach; ?>
                        <?php if (count($portfolioLatest) > 8): ?>
                        <li class="text-muted"><?= count($portfolioLatest) - 8 ?> more…</li>
                        <?php endif; ?>
                    </ul>
                </div>
                <?php endif; ?>
                <div class="col-lg-7">
                    <div class="mb-2 fw-semibold">Mix over time (stacked by product family)</div>
                    <div class="chart-container" style="height: 280px;">
                        <canvas id="portfolioStackedChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($categoryByYear)): ?>
    <div class="chart-card">
        <div class="card-header"><i class="ti ti-chart-donut me-2"></i>Category Mix by Year</div>
        <div class="card-body">
            <div class="chart-container">
                <canvas id="categoryShiftChart"></canvas>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($forecast && count($forecast['projections']) > 0): ?>
    <div class="chart-card">
        <div class="card-header"><i class="ti ti-trending-up me-2"></i>Seasonal Forecast (same-month historical average)</div>
        <div class="card-body">
            <div class="chart-container">
                <canvas id="forecastChart"></canvas>
            </div>
            <p class="text-muted small mt-2 mb-0">Projects from <?= htmlspecialchars($forecast['last_data'] ?? 'last data') ?> using same-month historical average across <?= count($years) ?> years.</p>
        </div>
    </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-6">
            <div class="chart-card">
                <div class="card-header"><i class="ti ti-table me-2"></i>Annual Totals</div>
                <div class="card-body p-0">
                    <table class="table table-hover table-striped mb-0">
                        <thead class="table-light"><tr><th>Year</th><th title="<?= htmlspecialchars($valueTooltip) ?>">Total</th><th>vs prior</th></tr></thead>
                        <tbody>
                            <?php
                            $prev = null;
                            foreach (array_keys($yearTotals) as $yr):
                                $tot = (float)($yearTotals[$yr][$valueKey] ?? 0);
                                $chg = $prev !== null && $prev > 0 ? round(100 * ($tot - $prev) / $prev, 1) : null;
                            ?>
                            <tr>
                                <td><?= $yr ?></td>
                                <td title="<?= htmlspecialchars($valueTooltip) ?>">£<?= number_format($tot, 0) ?></td>
                                <td><?= $chg !== null ? ($chg >= 0 ? '+' : '') . $chg . '%' : '–' ?></td>
                            </tr>
                            <?php $prev = $tot; endforeach; ?>
                            <?php if (empty($yearTotals)): ?>
                            <tr><td colspan="3" class="text-muted">No data. Select a distributor.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="chart-card">
                <div class="card-header"><i class="ti ti-table me-2"></i>Top Categories (latest year)</div>
                <div class="card-body p-0">
                    <table class="table table-hover table-striped mb-0">
                        <thead class="table-light"><tr><th>Category</th><th>Value</th></tr></thead>
                        <tbody>
                            <?php
                            $latestYr = !empty($categoryByYear) ? max(array_keys($categoryByYear)) : null;
                            $topCats = $latestYr ? array_slice($categoryByYear[$latestYr] ?? [], 0, 10) : [];
                            foreach ($topCats as $c):
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($c['category']) ?></td>
                                <td>£<?= number_format($c['dist_reported'], 0) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($topCats)): ?>
                            <tr><td colspan="2" class="text-muted">No category data.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <?php if ($distributorName): ?>
    <div class="chart-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div><i class="ti ti-receipt me-2"></i>Recent Top Deals</div>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#dealSearchForm">
                <i class="ti ti-search me-1"></i>Search
            </button>
        </div>
        <div class="collapse" id="dealSearchForm">
            <div class="card-body border-bottom">
                <form method="GET" class="row g-3">
                    <input type="hidden" name="distributor" value="<?= htmlspecialchars($distributorName) ?>">
                    <input type="hidden" name="years_back" value="<?= htmlspecialchars($yearsBack) ?>">
                    <input type="hidden" name="forecast_months" value="<?= htmlspecialchars($forecastMonths) ?>">
                    <div class="col-md-3">
                        <label class="form-label small">SKU</label>
                        <input type="text" name="deal_search_sku" class="form-control form-control-sm" 
                               value="<?= htmlspecialchars($dealSearchSku) ?>" placeholder="Search SKU...">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Product Name</label>
                        <input type="text" name="deal_search_product" class="form-control form-control-sm" 
                               value="<?= htmlspecialchars($dealSearchProduct) ?>" placeholder="Search product...">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Reseller</label>
                        <input type="text" name="deal_search_reseller" class="form-control form-control-sm" 
                               value="<?= htmlspecialchars($dealSearchReseller) ?>" placeholder="Search reseller...">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Date From</label>
                        <input type="date" name="deal_date_from" class="form-control form-control-sm" 
                               value="<?= htmlspecialchars($dealDateFrom) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Date To</label>
                        <input type="date" name="deal_date_to" class="form-control form-control-sm" 
                               value="<?= htmlspecialchars($dealDateTo) ?>">
                    </div>
                    <div class="col-md-12">
                        <button type="submit" class="btn btn-sm btn-primary me-2">
                            <i class="ti ti-search me-1"></i> Apply Filters
                        </button>
                        <a href="distributor_report.php?distributor=<?= urlencode($distributorName) ?>&years_back=<?= urlencode($yearsBack) ?>&forecast_months=<?= urlencode($forecastMonths) ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="ti ti-x me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Reseller</th>
                            <th>SKU</th>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Total Value</th>
                            <th>Currency</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentTopDeals as $deal): ?>
                        <tr>
                            <td><?= htmlspecialchars(date('d M Y', strtotime($deal['report_date']))) ?></td>
                            <td>
                                <?php if (!empty($deal['matched_reseller_name'])): ?>
                                    <a href="reseller_report.php?vendor_id=<?= (int)($deal['matched_vendor_id'] ?? 0) ?>">
                                        <?= htmlspecialchars($deal['matched_reseller_name']) ?>
                                    </a>
                                    <?php if ($deal['reseller_name'] !== $deal['matched_reseller_name']): ?>
                                        <br><small class="text-muted">(<?= htmlspecialchars($deal['reseller_name']) ?>)</small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?= htmlspecialchars($deal['reseller_name']) ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($deal['sku'])): ?>
                                    <a href="product_detail.php?sku=<?= urlencode($deal['sku']) ?>">
                                        <code><?= htmlspecialchars($deal['sku']) ?></code>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($deal['product_name'])): ?>
                                    <?= htmlspecialchars($deal['product_name']) ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?= number_format($deal['quantity'], 0) ?></td>
                            <td>
                                <?php if ($deal['unit_price'] > 0): ?>
                                    <?= htmlspecialchars($deal['currency'] ?? 'EUR') ?> <?= number_format($deal['unit_price'], 2) ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><strong><?= htmlspecialchars($deal['currency'] ?? 'EUR') ?> <?= number_format($deal['total_value'], 2) ?></strong></td>
                            <td><?= htmlspecialchars($deal['currency'] ?? 'EUR') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recentTopDeals)): ?>
                        <tr><td colspan="8" class="text-muted text-center">No deals found matching your search criteria.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($distributorName && !empty($topProducts)): ?>
    <div class="chart-card">
        <div class="card-header"><i class="ti ti-package me-2"></i>Top 10 Products (All Time)</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th>SKU</th>
                            <th>Product Name</th>
                            <th>Category</th>
                            <th>Product Line</th>
                            <th class="text-end">Total Sales</th>
                            <th class="text-end">Quantity</th>
                            <th class="text-end">Deals</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topProducts as $idx => $prod): ?>
                        <tr>
                            <td><strong><?= $idx + 1 ?></strong></td>
                            <td>
                                <?php if (!empty($prod['sku'])): ?>
                                    <a href="product_detail.php?sku=<?= urlencode($prod['sku']) ?>">
                                        <code><?= htmlspecialchars($prod['sku']) ?></code>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($prod['product_name'])): ?>
                                    <a href="product_detail.php?sku=<?= urlencode($prod['sku']) ?>" class="text-decoration-none">
                                        <?= htmlspecialchars($prod['product_name']) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($prod['product_category'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                            <td><?= htmlspecialchars($prod['product_line'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                            <td class="text-end"><strong>£<?= number_format($prod['total_value'] ?? 0, 0) ?></strong></td>
                            <td class="text-end"><?= number_format($prod['total_qty'] ?? 0, 0) ?></td>
                            <td class="text-end"><?= number_format($prod['deal_count'] ?? 0, 0) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($distributorName && !empty($amplifyByLevel)): ?>
    <div class="chart-card">
        <div class="card-header"><i class="ti ti-chart-pie me-2"></i>Sales by Amplify Level</div>
        <div class="card-body">
            <div class="chart-container">
                <canvas id="amplifyChart"></canvas>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($distributorName && !empty($topResellers)): ?>
    <div class="chart-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div><i class="ti ti-users me-2"></i>Resellers by Value</div>
            <div>
                <form method="GET" action="distributor_report.php" class="d-inline-flex align-items-center gap-2">
                    <input type="hidden" name="distributor" value="<?= htmlspecialchars($distributorName) ?>">
                    <input type="hidden" name="years_back" value="<?= htmlspecialchars($yearsBack) ?>">
                    <input type="hidden" name="forecast_months" value="<?= htmlspecialchars($forecastMonths) ?>">
                    <label class="form-label small mb-0">Filter by Amplify Level:</label>
                    <select name="filter_amplify_level" class="form-select form-select-sm" style="width: auto;" onchange="this.form.submit()">
                        <option value="">All Levels</option>
                        <?php
                        // Get all unique Amplify levels for this distributor
                        try {
                            $amplifyCols = $pdo->query("SHOW COLUMNS FROM vendors LIKE 'AMPLIFY_Level__c'")->fetch();
                            if ($amplifyCols) {
                                $allLevelsStmt = $pdo->prepare("
                                    SELECT DISTINCT COALESCE(NULLIF(TRIM(v.AMPLIFY_Level__c), ''), 'Other') as amplify_level
                                    FROM sales_out_raw s
                                    INNER JOIN vendors v ON s.matched_vendor_id = v.id
                                    WHERE s.distributor_name = ?
                                    ORDER BY amplify_level
                                ");
                                $allLevelsStmt->execute([$distributorName]);
                                $allLevels = $allLevelsStmt->fetchAll(PDO::FETCH_COLUMN);
                                foreach ($allLevels as $level) {
                                    $selected = ($filterAmplifyLevel === $level) ? 'selected' : '';
                                    echo '<option value="' . htmlspecialchars($level) . '" ' . $selected . '>' . htmlspecialchars($level) . '</option>';
                                }
                            }
                        } catch (PDOException $e) { /* column may not exist */ }
                        ?>
                    </select>
                </form>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th>Reseller</th>
                            <th>Amplify Level</th>
                            <th class="text-end">Total Sales</th>
                            <th class="text-end">Quantity</th>
                            <th class="text-end">Deals</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topResellers as $idx => $res): ?>
                        <tr>
                            <td><strong><?= $idx + 1 ?></strong></td>
                            <td>
                                <a href="reseller_report.php?vendor_id=<?= (int)$res['vendor_id'] ?>" class="text-decoration-none">
                                    <?= htmlspecialchars($res['vendor_name']) ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($res['amplify_level'] ?? 'Other') ?></td>
                            <td class="text-end"><strong>£<?= number_format($res['total_value'] ?? 0, 0) ?></strong></td>
                            <td class="text-end"><?= number_format($res['total_qty'] ?? 0, 0) ?></td>
                            <td class="text-end"><?= number_format($res['deal_count'] ?? 0, 0) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (empty($distributorsWithSales)): ?>
    <div class="alert alert-info">
        <i class="ti ti-info-circle me-2"></i>No distributor data yet. <a href="import.php">Import sales data</a> to see distributor reports.
    </div>
    <?php elseif (!$distributorName && empty($monthlyByYear)): ?>
    <div class="alert alert-warning">
        <i class="ti ti-alert-triangle me-2"></i>Select a specific distributor to see YoY charts and forecasts.
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js" defer></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const fmt = v => '£' + parseFloat(v).toLocaleString('en-GB', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    const monthLabels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

    <?php if (!empty($monthlyByYear)): ?>
    const yoyCtx = document.getElementById('yoyChart');
    if (yoyCtx) {
        const yoyData = <?= json_encode($monthlyByYear) ?>;
        const years = <?= json_encode(array_values($years)) ?>;
        const colors = <?= json_encode($yoyColors) ?>;
        const datasets = years.map((yr, i) => ({
            label: '' + yr,
            data: [1,2,3,4,5,6,7,8,9,10,11,12].map(mo => yoyData[yr] ? yoyData[yr][mo] || 0 : 0),
            borderColor: colors[i % colors.length],
            backgroundColor: colors[i % colors.length] + '20',
            borderWidth: 2,
            fill: false,
            tension: 0.3
        }));
        new Chart(yoyCtx.getContext('2d'), {
            type: 'line',
            data: { labels: monthLabels, datasets: datasets },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top' }, tooltip: { callbacks: { label: c => c.dataset.label + ': ' + fmt(c.raw) } } }, scales: { y: { beginAtZero: true, ticks: { callback: v => fmt(v) } } } }
        });
    }
    <?php endif; ?>

    <?php if (!empty($seasonality) && array_sum($seasonality) > 0): ?>
    const seaCtx = document.getElementById('seasonalityChart');
    if (seaCtx) {
        const seaData = <?= json_encode(array_values($seasonality)) ?>;
        new Chart(seaCtx.getContext('2d'), {
            type: 'bar',
            data: { labels: monthLabels, datasets: [{ label: 'Avg monthly sales', data: seaData, backgroundColor: 'rgba(0, 163, 153, 0.7)', borderColor: '#00a399', borderWidth: 1 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => fmt(c.raw) } } }, scales: { y: { beginAtZero: true, ticks: { callback: v => fmt(v) } } } }
        });
    }
    <?php endif; ?>

    <?php if (!empty($portfolioByYear) || !empty($portfolioByYearMonth)): ?>
    const portfolioDonutCtx = document.getElementById('portfolioDonutChart');
    if (portfolioDonutCtx) {
        const portfolioLatest = <?= json_encode($portfolioLatest ?? []) ?>;
        const portfolioColors = ['#00a399','#00353d','#ff5549','#666666','#cccccc','#f59e0b','#10b981','#6366f1'];
        const labels = portfolioLatest.map(c => c.category);
        const data = portfolioLatest.map(c => c.dist_reported);
        new Chart(portfolioDonutCtx.getContext('2d'), {
            type: 'doughnut',
            data: { labels: labels, datasets: [{ data: data, backgroundColor: portfolioColors, borderWidth: 2 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: (c) => c.label + ': ' + fmt(c.raw) + ' (' + (data.reduce((a,b)=>a+b,0) ? Math.round(100*c.raw/data.reduce((a,b)=>a+b,0)) : 0) + '%)' } } } }
        });
    }
    const portfolioStackedCtx = document.getElementById('portfolioStackedChart');
    if (portfolioStackedCtx) {
        const catByYrMo = <?= json_encode($portfolioByYearMonth ?? []) ?>;
        const timeKeys = Object.keys(catByYrMo).sort();
        const allCats = [...new Set(timeKeys.flatMap(k => (catByYrMo[k] || []).map(r => r.category)))];
        const pColors = ['#00a399','#00353d','#ff5549','#666666','#cccccc','#f59e0b','#10b981','#6366f1'];
        const pDatasets = allCats.slice(0, 8).map((cat, i) => ({
            label: cat,
            data: timeKeys.map(k => { const r = (catByYrMo[k] || []).find(c => c.category === cat); return r ? r.dist_reported : 0; }),
            backgroundColor: pColors[i % pColors.length]
        }));
        const pLabels = timeKeys.map(k => { const [y,m] = k.split('-'); return monthLabels[parseInt(m,10)-1] + ' ' + y; });
        new Chart(portfolioStackedCtx.getContext('2d'), {
            type: 'bar',
            data: { labels: pLabels, datasets: pDatasets },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top' }, tooltip: { callbacks: { label: c => c.dataset.label + ': ' + fmt(c.raw) } } }, scales: { x: { stacked: true, ticks: { maxRotation: 45, maxTicksLimit: 24 } }, y: { stacked: true, beginAtZero: true, ticks: { callback: v => fmt(v) } } } }
        });
    }
    <?php endif; ?>

    <?php if (!empty($categoryByYear)): ?>
    const catCtx = document.getElementById('categoryShiftChart');
    if (catCtx) {
        const catByYear = <?= json_encode($categoryByYear) ?>;
        const yrs = Object.keys(catByYear).sort();
        const allCats = [...new Set(yrs.flatMap(y => (catByYear[y] || []).map(c => c.category)))];
        const colors = ['#00a399','#00353d','#ff5549','#666666','#cccccc','#f59e0b','#00a399','#00353d'];
        const datasets = allCats.slice(0, 8).map((cat, i) => ({
            label: cat,
            data: yrs.map(yr => { const r = (catByYear[yr] || []).find(c => c.category === cat); return r ? r.dist_reported : 0; }),
            backgroundColor: colors[i % colors.length]
        }));
        new Chart(catCtx.getContext('2d'), {
            type: 'bar',
            data: { labels: yrs, datasets: datasets },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top' }, tooltip: { callbacks: { label: c => c.dataset.label + ': ' + fmt(c.raw) } } }, scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { callback: v => fmt(v) } } } }
        });
    }
    <?php endif; ?>

    <?php if ($forecast && count($forecast['projections']) > 0): ?>
    const fcCtx = document.getElementById('forecastChart');
    if (fcCtx) {
        const proj = <?= json_encode($forecast['projections']) ?>;
        new Chart(fcCtx.getContext('2d'), {
            type: 'bar',
            data: { labels: proj.map(p => p.label), datasets: [{ label: 'Forecast', data: proj.map(p => p.value), backgroundColor: 'rgba(0, 163, 153, 0.7)', borderColor: '#00a399', borderWidth: 1 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => fmt(c.raw) } } }, scales: { y: { beginAtZero: true, ticks: { callback: v => fmt(v) } } } }
        });
    }
    <?php endif; ?>

    <?php if (!empty($inventoryTrend) && count($inventoryTrend) >= 2): ?>
    const invTrendCtx = document.getElementById('invTrendChart');
    if (invTrendCtx) {
        const invTrend = <?= json_encode($inventoryTrend) ?>;
        new Chart(invTrendCtx.getContext('2d'), {
            type: 'line',
            data: {
                labels: invTrend.map(r => r.snapshot_date),
                datasets: [{ label: 'Inventory value', data: invTrend.map(r => parseFloat(r.val)), borderColor: '#00353d', backgroundColor: 'rgba(0, 53, 61, 0.2)', fill: true, tension: 0.3 }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => fmt(c.raw) } } }, scales: { y: { beginAtZero: true, ticks: { callback: v => fmt(v) } } } }
        });
    }
    <?php endif; ?>

    <?php if (!empty($amplifyByLevel)): ?>
    const amplifyCtx = document.getElementById('amplifyChart');
    if (amplifyCtx) {
        const amplifyLabels = <?= json_encode(array_column($amplifyByLevel, 'amplify_level')) ?>;
        const amplifyData = <?= json_encode(array_map('floatval', array_column($amplifyByLevel, 'total'))) ?>;
        const amplifyColors = ['#00a399', '#00353d', '#ff5549', '#666666', '#cccccc', '#f59e0b', '#8b5cf6', '#ec4899'];
        new Chart(amplifyCtx.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: amplifyLabels,
                datasets: [{
                    data: amplifyData,
                    backgroundColor: amplifyColors.slice(0, amplifyLabels.length),
                    borderColor: '#fff',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'right' },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.parsed || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                return label + ': £' + fmt(value) + ' (' + percentage + '%)';
                            }
                        }
                    }
                }
            }
        });
    }
    <?php endif; ?>
});
</script>
</div></div>
</body>
</html>
