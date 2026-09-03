<?php
// reseller_report.php - Vendor/reseller-centric analysis: YoY, seasonality, category shifts, forecasting
session_start();
ob_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=' . urlencode('salesout/reseller_report.php'));
    exit;
}

$pdo = getDBConnection();

$vendorId = $_GET['vendor_id'] ?? '';
$unmappedReseller = trim($_GET['unmapped_reseller'] ?? '');
$forecastMonths = (int) ($_GET['forecast_months'] ?? 6);
$yearsBack = (int) ($_GET['years_back'] ?? 5);
if ($yearsBack < 1 || $yearsBack > 10) $yearsBack = 5;

// Matched vendor and unmapped reseller are mutually exclusive (vendor takes priority)
if ($vendorId !== '') {
    $unmappedReseller = '';
} elseif ($unmappedReseller !== '') {
    $vendorId = '';
}

// Account Owner filter (vendors.Owner_Full_Name__c) - only applied when column exists
$filterOwner = trim($_GET['owner'] ?? '');

// Deal search filters
$dealSearchSku = trim($_GET['deal_search_sku'] ?? '');
$dealSearchProduct = trim($_GET['deal_search_product'] ?? '');
$dealSearchDistributor = trim($_GET['deal_search_distributor'] ?? '');
$dealDateFrom = $_GET['deal_date_from'] ?? date('Y-m-01', strtotime('-6 months'));
$dealDateTo = $_GET['deal_date_to'] ?? date('Y-m-d');

$dateFrom = date('Y-m-01', strtotime("-$yearsBack years"));
$dateTo = date('Y-m-d');

$params = [$dateFrom, $dateTo];
if ($unmappedReseller !== '') {
    $where = "s.report_date BETWEEN ? AND ? AND s.reseller_name = ? AND s.matched_vendor_id IS NULL";
    $params[] = $unmappedReseller;
} else {
    $where = "s.report_date BETWEEN ? AND ? AND s.matched_vendor_id IS NOT NULL";
    if ($vendorId !== '') {
        $where .= " AND s.matched_vendor_id = ?";
        $params[] = $vendorId;
    }
}

$hasOwnerColumn = false;
try {
    $hasOwnerColumn = (bool) $pdo->query("SHOW COLUMNS FROM vendors LIKE 'Owner_Full_Name__c'")->fetch();
} catch (PDOException $e) { /* ignore */ }

$hasProductFamily = false;
try {
    $hasProductFamily = (bool) $pdo->query("SHOW COLUMNS FROM sales_out_products LIKE 'product_family'")->fetch();
} catch (PDOException $e) { /* ignore */ }
if ($hasOwnerColumn && $filterOwner !== '') {
    $where .= " AND s.matched_vendor_id IN (SELECT id FROM vendors WHERE TRIM(COALESCE(Owner_Full_Name__c,'')) = ?)";
    $params[] = $filterOwner;
}

$vendorsWithSales = [];
$monthlyByYear = [];
$seasonality = [];
$categoryByYear = [];
$productLineByYear = [];
$yearTotals = [];
$forecast = null;
$vendorName = '';
$vendorSalesforceId = '';
$totalValue = 0;
$recentTopDeals = [];
$topProducts = [];
$opportunities = [];
$opportunitiesError = null;
$dbError = null;

require_once __DIR__ . '/header.php';
if (ob_get_level()) { ob_flush(); }
flush();

try {
    $vendorsSql = "
        SELECT v.id, v.vendor_name, v.salesforce_id,
            COALESCE(SUM(s.total_value), 0) as dist_reported,
            COALESCE(SUM(s.quantity * p.trade_price), 0) as at_trade,
            COALESCE(SUM(s.quantity * p.msrp), 0) as at_msrp
        FROM vendors v
        INNER JOIN sales_out_raw s ON s.matched_vendor_id = v.id
        LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
        WHERE s.report_date >= ?
    ";
    $vendorsParams = [$dateFrom];
    if ($hasOwnerColumn && $filterOwner !== '') {
        $vendorsSql .= " AND TRIM(COALESCE(v.Owner_Full_Name__c,'')) = ?";
        $vendorsParams[] = $filterOwner;
    }
    $vendorsSql .= " GROUP BY v.id, v.vendor_name, v.salesforce_id ORDER BY dist_reported DESC";
    $vendorsStmt = $pdo->prepare($vendorsSql);
    $vendorsStmt->execute($vendorsParams);
    $vendorsWithSales = $vendorsStmt->fetchAll(PDO::FETCH_ASSOC);

    $accountOwners = [];
    if ($hasOwnerColumn) {
        $ownersStmt = $pdo->query("
            SELECT DISTINCT TRIM(Owner_Full_Name__c) as owner_name
            FROM vendors
            WHERE Owner_Full_Name__c IS NOT NULL AND TRIM(Owner_Full_Name__c) != ''
            ORDER BY owner_name
        ");
        $accountOwners = $ownersStmt->fetchAll(PDO::FETCH_COLUMN);
    }

    foreach ($vendorsWithSales as $v) {
        if ((string)$v['id'] === (string)$vendorId) {
            $vendorName = $v['vendor_name'];
            $vendorSalesforceId = $v['salesforce_id'] ?? '';
            break;
        }
    }
    if ($unmappedReseller !== '') {
        $vendorName = $unmappedReseller;
    }

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

    // Prior-year YTD for fair YoY when current year is latest (avoids partial vs full-year comparison)
    $priorYearYtd = null;
    $currentYear = (int) date('Y');
    $today = date('Y-m-d');
    $sortedYrs = array_keys($yearTotals);
    rsort($sortedYrs);
    if (!empty($sortedYrs) && (int)$sortedYrs[0] === $currentYear && count($sortedYrs) >= 2) {
        $priorYr = (int)$sortedYrs[1];
        $priorYtdEnd = $priorYr . '-' . date('m-d', strtotime($today));
        if ($priorYtdEnd <= ($priorYr . '-12-31')) {
            // Same vendor/reseller filters, YTD date range for prior year
            $ytdParams = [(string)$priorYr . '-01-01', $priorYtdEnd];
            if (count($params) > 2) {
                $ytdParams = array_merge($ytdParams, array_slice($params, 2));
            }
            $ytdStmt = $pdo->prepare("
                SELECT COALESCE(SUM(s.total_value), 0) as dist_reported,
                    COALESCE(SUM(s.quantity * p.trade_price), 0) as at_trade,
                    COALESCE(SUM(s.quantity * p.msrp), 0) as at_msrp
                FROM sales_out_raw s
                LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
                WHERE $where
            ");
            $ytdStmt->execute($ytdParams);
            $ytdRow = $ytdStmt->fetch(PDO::FETCH_ASSOC);
            if ($ytdRow) {
                $priorYearYtd = [
                    'dist_reported' => (float)$ytdRow['dist_reported'],
                    'at_trade' => (float)$ytdRow['at_trade'],
                    'at_msrp' => (float)$ytdRow['at_msrp'],
                ];
            }
        }
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

    // Portfolio mix by year-month (product_family when available, else product_line/product_type/product_category)
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

    // Portfolio mix by year (for donut - same grouping as above)
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
    $totalValue = 0;
    foreach ($yearTotals as $yr => $vals) {
        $totalValue += (float)($vals[$valueKey] ?? 0);
    }

    // Reseller target for current year (when matched vendor selected) - show even if no YTD sales yet
    $resellerTargetInfo = null;
    $currentYear = (int) date('Y');
    if ($vendorId !== '' && $unmappedReseller === '') {
        try {
            $tstmt = $pdo->prepare("SELECT annual_target FROM sales_out_targets WHERE target_type = 'reseller' AND entity_key = ? AND year = ?");
            $tstmt->execute([$vendorId, $currentYear]);
            $trow = $tstmt->fetch(PDO::FETCH_ASSOC);
            if ($trow && (float)($trow['annual_target'] ?? 0) > 0) {
                $targetSeasonality = getSeasonalityPercentages($pdo);
                $timeElapsed = getTimeElapsedForYear($currentYear);
                $targetToDate = getTargetToDate((float)$trow['annual_target'], $targetSeasonality, $currentYear);
                $actualYtd = (float)($yearTotals[$currentYear]['dist_reported'] ?? 0);
                $resellerTargetInfo = [
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
    if (count($years) >= 1 && $forecastMonths > 0 && ($vendorId !== '' || $unmappedReseller !== '')) {
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

    // Recent top deals: largest 20 deals in past 6 months (only when vendor or unmapped reseller selected)
    if ($vendorId !== '' || $unmappedReseller !== '') {
        if ($unmappedReseller !== '') {
            $dealsWhere = "s.reseller_name = ? AND s.matched_vendor_id IS NULL AND s.report_date >= ? AND s.report_date <= ?";
            $dealsParams = [$unmappedReseller, $dealDateFrom, $dealDateTo];
        } else {
            $dealsWhere = "s.matched_vendor_id = ? AND s.report_date >= ? AND s.report_date <= ?";
            $dealsParams = [$vendorId, $dealDateFrom, $dealDateTo];
        }
        
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
        if (!empty($dealSearchDistributor)) {
            $dealsWhere .= " AND s.distributor_name LIKE ?";
            $dealsParams[] = '%' . $dealSearchDistributor . '%';
        }
        
        $dealsStmt = $pdo->prepare("
            SELECT 
                s.id,
                s.report_date,
                s.distributor_name,
                s.reseller_name,
                s.sku,
                COALESCE(p.product_name, s.product_name) as product_name,
                s.quantity,
                s.unit_price,
                s.total_value,
                s.currency
            FROM sales_out_raw s
            LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
            WHERE $dealsWhere
            ORDER BY s.total_value DESC
            LIMIT 20
        ");
        $dealsStmt->execute($dealsParams);
        $recentTopDeals = $dealsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Top products for this reseller (all time, aggregated by SKU)
        if ($unmappedReseller !== '') {
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
                WHERE s.reseller_name = ? AND s.matched_vendor_id IS NULL
                AND s.sku IS NOT NULL
                AND s.sku != ''
                GROUP BY COALESCE(p.sku, s.sku), COALESCE(p.product_name, s.product_name), p.product_category, p.product_line
                ORDER BY total_value DESC
                LIMIT 20
            ");
            $topProductsStmt->execute([$unmappedReseller]);
        } else {
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
                WHERE s.matched_vendor_id = ?
                AND s.sku IS NOT NULL
                AND s.sku != ''
                GROUP BY COALESCE(p.sku, s.sku), COALESCE(p.product_name, s.product_name), p.product_category, p.product_line
                ORDER BY total_value DESC
                LIMIT 20
            ");
            $topProductsStmt->execute([$vendorId]);
        }
        $topProducts = $topProductsStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Opportunities (Salesforce or sheet) for selected matched vendor only (when enabled)
    if (defined('SALESOUT_OPPORTUNITIES_ENABLED') && SALESOUT_OPPORTUNITIES_ENABLED && $vendorId !== '' && $unmappedReseller === '') {
        $oppResult = fetchOpportunities();
        if ($oppResult['error'] === null) {
            $opportunities = getOpportunitiesForVendor($oppResult['rows'], $pdo, (int) $vendorId);
        } else {
            $opportunitiesError = $oppResult['error'];
        }
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
.filter-card { background: white; border: 1px solid #D7D2CB; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 1.5rem; overflow: visible; }
.filter-card .card-header { background: rgb(238, 239, 241); border-bottom: 1px solid #D7D2CB; padding: 1rem 1.25rem; font-weight: 600; color: #0f172a; }
.filter-card .card-body { padding: 1.25rem 1.5rem; }
.kpi-card { text-align: center; padding: 1.25rem; border-radius: 10px; background: white; border: 1px solid #D7D2CB; box-shadow: 0 1px 3px rgba(0,0,0,0.05); height: 100%; }
.kpi-value { font-size: 1.75rem; font-weight: 700; }
.kpi-label { font-size: 0.75rem; color: #666666; text-transform: uppercase; letter-spacing: 0.5px; }
.chart-card { border-radius: 10px; border: 1px solid #D7D2CB; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 1.5rem; background: white; }
.chart-card .card-header { background: rgb(238, 239, 241); border-bottom: 1px solid #D7D2CB; padding: 1rem 1.25rem; font-weight: 600; color: #0f172a; }
.chart-container { height: 320px; }
.report-body { background: rgb(238, 239, 241); }
.reseller-target-combined { background: white; border-radius: 10px; padding: 1.5rem; border: 1px solid #D7D2CB; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 1.5rem; }
.reseller-target-bar { height: 12px; background: #e5e7eb; border-radius: 6px; overflow: visible; margin-bottom: 0.5rem; }
#unmappedResellerDropdown { min-width: 100%; }
#unmappedResellerDropdown .list-group-item { white-space: normal; word-wrap: break-word; overflow-wrap: break-word; }
.reseller-target-ref { position: absolute; top: -2px; bottom: -2px; width: 2px; background: rgba(0,0,0,0.5); }
th.sortable { user-select: none; }
th.sortable:hover { background-color: rgba(0, 163, 153, 0.1) !important; }
th.sortable i { opacity: 0.5; font-size: 0.85em; }
th.sortable:hover i { opacity: 1; }
</style>

<div class="container-xl py-4 report-body">
    <div class="report-header">
        <div class="container-fluid">
            <h1 class="h2 mb-1"><i class="ti ti-report-analytics me-2"></i>Reseller / Vendor Report</h1>
            <p class="mb-0 opacity-75">Year-on-year tracking, seasonality, category shifts & forecasting by vendor. Uses up to <?= $yearsBack ?> years of data.<?php if ($filterOwner !== ''): ?> <span class="badge bg-light text-dark">Account owner: <?= htmlspecialchars($filterOwner) ?></span><?php endif; ?></p>
        </div>
    </div>

    <div class="filter-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div><i class="ti ti-filter me-2"></i>Filters</div>
            <a href="reseller_report.php" class="btn btn-sm btn-outline-secondary">Reset</a>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Matched Vendor (Reseller)</label>
                    <select name="vendor_id" id="vendorSelect" class="form-select">
                        <option value="">All matched vendors</option>
                        <?php foreach ($vendorsWithSales as $v): ?>
                        <option value="<?= $v['id'] ?>" <?= $vendorId === (string)$v['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($v['vendor_name']) ?> (&pound;<?= number_format($v[$valueKey] ?? 0, 0) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 position-relative">
                    <label class="form-label">Unmapped Reseller (search)</label>
                    <input type="text" name="unmapped_reseller" id="unmappedResellerInput" class="form-control" 
                           value="<?= htmlspecialchars($unmappedReseller) ?>" autocomplete="off" 
                           placeholder="Type to search unmapped resellers...">
                    <div id="unmappedResellerDropdown" class="list-group position-absolute start-0 end-0 mt-1 shadow" style="z-index:1050;max-height:320px;overflow-y:auto;display:none"></div>
                </div>
                <?php if ($hasOwnerColumn && !empty($accountOwners)): ?>
                <div class="col-md-3">
                    <label class="form-label">Account Owner</label>
                    <select name="owner" class="form-select">
                        <option value="">All owners</option>
                        <?php foreach ($accountOwners as $ownerName): ?>
                        <option value="<?= htmlspecialchars($ownerName) ?>" <?= $filterOwner === $ownerName ? 'selected' : '' ?>><?= htmlspecialchars($ownerName) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
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

    <?php if ($vendorId || $unmappedReseller): ?>
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="kpi-card">
                <div class="kpi-value text-primary"><?= htmlspecialchars($vendorName) ?></div>
                <div class="kpi-label"><?= $unmappedReseller ? 'Unmapped reseller' : 'Selected vendor' ?></div>
                <?php if ($vendorId && !empty($vendorSalesforceId)): ?><div class="mt-1"><code class="small"><?= htmlspecialchars($vendorSalesforceId) ?></code></div><?php endif; ?>
                <?php if ($unmappedReseller): ?><div class="mt-1"><a href="mapping.php" class="small">Map to vendor</a></div><?php endif; ?>
            </div>
        </div>
        <?php $valueTooltip = getSalesOutValueModeTooltip(); ?>
        <div class="col-md-3">
            <div class="kpi-card" title="<?= htmlspecialchars($valueTooltip) ?>">
                <div class="kpi-value text-info">&pound;<?= number_format($totalValue, 0) ?></div>
                <div class="kpi-label">Total (<?= min($years ?? [0]) ?>-<?= max($years ?? [0]) ?>)</div>
            </div>
        </div>
        <?php if (!empty($resellerTargetInfo)): ?>
        <div class="col-md-2">
            <div class="kpi-card">
                <div class="kpi-value" style="color: <?= $resellerTargetInfo['pct'] >= 100 ? '#00a399' : ($resellerTargetInfo['pct'] >= 80 ? '#f59e0b' : '#ff5549') ?>"><?= $resellerTargetInfo['pct'] ?>%</div>
                <div class="kpi-label">Target <?= $currentYear ?></div>
                <small class="text-muted">&pound;<?= number_format($resellerTargetInfo['actual'], 0) ?> / &pound;<?= number_format($resellerTargetInfo['annual_target'], 0) ?></small>
            </div>
        </div>
        <?php if (($resellerTargetInfo['time_elapsed']['is_current_year'] ?? false)): ?>
        <div class="col-md-2">
            <div class="kpi-card">
                <div class="kpi-value" style="color: <?= $resellerTargetInfo['vs_time'] >= 100 ? '#00a399' : ($resellerTargetInfo['vs_time'] >= 80 ? '#f59e0b' : '#ff5549') ?>"><?= $resellerTargetInfo['vs_time'] ?>%</div>
                <div class="kpi-label">vs Time (<?= $resellerTargetInfo['time_elapsed']['pct'] ?>% elapsed)</div>
                <small class="text-muted">&pound;<?= number_format($resellerTargetInfo['actual'], 0) ?> / &pound;<?= number_format($resellerTargetInfo['target_to_date'], 0) ?> to date</small>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        <?php
        $prevYr = null;
        $yoyPct = null;
        $yoyLabel = 'YoY growth (latest vs prior)';
        $sortedYrs = array_keys($yearTotals);
        rsort($sortedYrs);
        if (count($sortedYrs) >= 2) {
            $curr = (float)($yearTotals[$sortedYrs[0]][$valueKey] ?? 0);
            // When latest year is current year, use prior-year YTD for apples-to-apples comparison
            if ($priorYearYtd !== null && (int)$sortedYrs[0] === $currentYear) {
                $prev = (float)($priorYearYtd[$valueKey] ?? 1);
                $yoyLabel = 'YoY growth (YTD vs prior YTD)';
            } else {
                $prev = (float)($yearTotals[$sortedYrs[1]][$valueKey] ?? 1);
            }
            $yoyPct = $prev > 0 ? round(100 * ($curr - $prev) / $prev, 1) : 0;
        }
        ?>
        <div class="col-md-3">
            <div class="kpi-card">
                <div class="kpi-value <?= ($yoyPct ?? 0) >= 0 ? 'text-success' : 'text-danger' ?>">
                    <?= $yoyPct !== null ? ($yoyPct >= 0 ? '+' : '') . $yoyPct . '%' : '-' ?>
                </div>
                <div class="kpi-label"><?= htmlspecialchars($yoyLabel ?? 'YoY growth (latest vs prior)') ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card">
                <div class="kpi-value" style="color:#00353d"><?= count($years ?? []) ?></div>
                <div class="kpi-label">Years of data</div>
            </div>
        </div>
    </div>

    <?php if ($vendorId && !empty($resellerTargetInfo)): ?>
    <div class="reseller-target-combined">
        <div class="d-flex flex-wrap gap-3 align-items-start">
            <div class="flex-grow-1" style="min-width: 200px;">
                <div class="fw-bold fs-4" style="color: <?= $resellerTargetInfo['pct'] >= 100 ? '#00a399' : ($resellerTargetInfo['pct'] >= 80 ? '#f59e0b' : '#ff5549') ?>" title="By Distributor reported (target comparison)">&pound;<?= number_format($resellerTargetInfo['actual'], 0) ?></div>
                <div class="small text-muted mb-2">Sales Out — <?= htmlspecialchars($vendorName) ?> (<?= $currentYear ?>)</div>
                <div class="position-relative reseller-target-bar">
                    <div class="h-100 rounded" style="width: <?= min(100, $resellerTargetInfo['pct']) ?>%; background: <?= $resellerTargetInfo['pct'] >= 100 ? '#00a399' : ($resellerTargetInfo['pct'] >= 80 ? '#f59e0b' : '#ff5549') ?>;"></div>
                    <?php if (($resellerTargetInfo['time_elapsed']['is_current_year'] ?? false) && ($resellerTargetInfo['annual_target'] ?? 0) > 0): 
                        $refPct = min(99, 100 * $resellerTargetInfo['target_to_date'] / $resellerTargetInfo['annual_target']);
                    ?>
                    <div class="reseller-target-ref" style="left: <?= $refPct ?>%;" title="On-track: &pound;<?= number_format($resellerTargetInfo['target_to_date'], 0) ?>"></div>
                    <?php endif; ?>
                </div>
                <div class="small text-muted">Target: &pound;<?= number_format($resellerTargetInfo['annual_target'], 0) ?> · <?= ($resellerTargetInfo['time_elapsed']['is_current_year'] ?? false) ? 'To date: &pound;' . number_format($resellerTargetInfo['target_to_date'], 0) : '' ?></div>
            </div>
            <?php if ($resellerTargetInfo['time_elapsed']['is_current_year'] ?? false): ?>
            <div class="text-center px-3 py-2 rounded" style="background: <?= ($resellerTargetInfo['pct_ahead'] ?? 0) >= 0 ? 'rgba(0,163,153,0.12)' : 'rgba(255,85,73,0.12)' ?>;">
                <div class="fw-bold fs-5" style="color: <?= ($resellerTargetInfo['pct_ahead'] ?? 0) >= 0 ? '#00a399' : '#ff5549' ?>"><?= ($resellerTargetInfo['pct_ahead'] ?? 0) >= 0 ? '+' : '' ?><?= $resellerTargetInfo['pct_ahead'] ?? 0 ?>%</div>
                <div class="small" style="color: #666"><?= ($resellerTargetInfo['pct_ahead'] ?? 0) >= 0 ? 'Ahead' : 'Behind' ?> target</div>
                <?php if (!empty($resellerTargetInfo['year_end_forecast'])): ?>
                <div class="small mt-1" style="color: #666">Forecast: &pound;<?= number_format($resellerTargetInfo['year_end_forecast'], 0) ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <p class="mb-0 mt-3 small"><a href="targets.php">Edit targets</a></p>
    </div>
    <?php elseif ($vendorId): ?>
    <div class="alert alert-info mb-4"><i class="ti ti-info-circle me-2"></i><a href="targets.php">Set a reseller target</a> for <?= $currentYear ?> to track performance vs time.</div>
    <?php endif; ?>
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
        <div class="card-header"><i class="ti ti-calendar me-2"></i>Seasonality - Average Sales by Month (across years)</div>
        <div class="card-body">
            <div class="chart-container">
                <canvas id="seasonalityChart"></canvas>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($portfolioByYear) || !empty($portfolioByYearMonth)): ?>
    <div class="chart-card">
        <div class="card-header"><i class="ti ti-chart-pie me-2"></i>Portfolio Mix - By product family (how sales are made up & how it changes over time)</div>
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
                        <li class="d-flex justify-content-between py-1"><span><?= htmlspecialchars($c['category']) ?></span><span>&pound;<?= number_format($c['dist_reported'], 0) ?> (<?= round($pct, 1) ?>%)</span></li>
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
                                <td title="<?= htmlspecialchars($valueTooltip) ?>">&pound;<?= number_format($tot, 0) ?></td>
                                <td><?= $chg !== null ? ($chg >= 0 ? '+' : '') . $chg . '%' : '-' ?></td>
                            </tr>
                            <?php $prev = $tot; endforeach; ?>
                            <?php if (empty($yearTotals)): ?>
                            <tr><td colspan="3" class="text-muted">No data. Select a vendor and ensure sales are mapped.</td></tr>
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
                                <td>&pound;<?= number_format($c['dist_reported'], 0) ?></td>
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

    <?php if ($vendorId || $unmappedReseller): ?>
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
                    <input type="hidden" name="vendor_id" value="<?= htmlspecialchars($vendorId) ?>">
                    <input type="hidden" name="unmapped_reseller" value="<?= htmlspecialchars($unmappedReseller) ?>">
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
                        <label class="form-label small">Distributor</label>
                        <input type="text" name="deal_search_distributor" class="form-control form-control-sm" 
                               value="<?= htmlspecialchars($dealSearchDistributor) ?>" placeholder="Search dist...">
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
                        <a href="reseller_report.php?vendor_id=<?= urlencode($vendorId) ?>&unmapped_reseller=<?= urlencode($unmappedReseller) ?>&years_back=<?= urlencode($yearsBack) ?>&forecast_months=<?= urlencode($forecastMonths) ?><?= $filterOwner !== '' ? '&owner=' . urlencode($filterOwner) : '' ?>" class="btn btn-sm btn-outline-secondary">
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
                            <th>Distributor</th>
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
                            <td><?= htmlspecialchars($deal['distributor_name']) ?></td>
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

    <?php if (($vendorId || $unmappedReseller) && !empty($topProducts)): ?>
    <div class="chart-card">
        <div class="card-header"><i class="ti ti-package me-2"></i>Top Products (All Time)</div>
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
                            <td class="text-end"><strong>&pound;<?= number_format($prod['total_value'] ?? 0, 0) ?></strong></td>
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

    <?php if ($vendorId && ($opportunitiesError !== null || !empty($opportunities))): ?>
    <div class="chart-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div><i class="ti ti-briefcase me-2"></i>Opportunities (live sheet)</div>
            <a href="opportunity_checker.php?vendor_id=<?= urlencode($vendorId) ?>" class="btn btn-sm btn-outline-secondary">Test matching</a>
        </div>
        <?php if ($opportunitiesError): ?>
        <div class="card-body">
            <p class="text-muted mb-0"><?= htmlspecialchars($opportunitiesError) ?></p>
        </div>
        <?php else: ?>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0 sortable-table" id="opportunitiesTable">
                    <thead class="table-light">
                        <tr>
                            <th class="sortable" data-sort="vendor">Vendor (Referred by) <i class="ti ti-arrows-sort ms-1"></i></th>
                            <th class="sortable" data-sort="contact">EPOS Contact <i class="ti ti-arrows-sort ms-1"></i></th>
                            <th class="sortable" data-sort="name">Opportunity Name <i class="ti ti-arrows-sort ms-1"></i></th>
                            <th class="sortable" data-sort="project">SPA/Deal ref <i class="ti ti-arrows-sort ms-1"></i></th>
                            <th class="sortable" data-sort="status">Status <i class="ti ti-arrows-sort ms-1"></i></th>
                            <th class="sortable" data-sort="stage">Stage Name <i class="ti ti-arrows-sort ms-1"></i></th>
                            <th class="sortable" data-sort="amount">Amount <i class="ti ti-arrows-sort ms-1"></i></th>
                            <th class="sortable" data-sort="age">Age (days) <i class="ti ti-arrows-sort ms-1"></i></th>
                            <th class="sortable" data-sort="owner">Opportunity Owner <i class="ti ti-arrows-sort ms-1"></i></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($opportunities as $row):
                            $d = opportunityDisplayFields($row);
                            $sfUrl = getSalesforceOpportunityUrl($d['id']);
                            $vendorDisplay = getVendorReferredByDisplay($pdo, $d['vendor_name']);
                        ?>
                        <tr data-vendor="<?= htmlspecialchars(strtolower($vendorDisplay['display'])) ?>" 
                            data-contact="<?= htmlspecialchars(strtolower($d['epos_contact'])) ?>" 
                            data-name="<?= htmlspecialchars(strtolower($d['name'])) ?>" 
                            data-project="<?= htmlspecialchars(strtolower($d['project_no'])) ?>" 
                            data-status="<?= htmlspecialchars(strtolower($d['status'])) ?>" 
                            data-stage="<?= htmlspecialchars(strtolower($d['stage_name'])) ?>" 
                            data-amount="<?= $d['amount'] ?? 0 ?>" 
                            data-age="<?= $d['age_days'] ?? '' ?>" 
                            data-owner="<?= htmlspecialchars(strtolower($d['account_owner'])) ?>">
                            <td>
                                <?php if ($vendorDisplay['vendor_id'] !== null): ?>
                                    <a href="reseller_report.php?vendor_id=<?= (int)$vendorDisplay['vendor_id'] ?>"><?= htmlspecialchars($vendorDisplay['display']) ?></a>
                                <?php else: ?>
                                    <?= htmlspecialchars($vendorDisplay['display']) ?>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($d['epos_contact']) ?></td>
                            <td>
                                <?php if ($sfUrl): ?>
                                    <a href="<?= htmlspecialchars($sfUrl) ?>" target="_blank" class="text-decoration-none">
                                        <?= htmlspecialchars($d['name']) ?> <i class="ti ti-external-link small"></i>
                                    </a>
                                <?php else: ?>
                                    <?= htmlspecialchars($d['name']) ?>
                                <?php endif; ?>
                            </td>
                            <td><code><?= htmlspecialchars($d['project_no']) ?></code></td>
                            <td><?= htmlspecialchars($d['status']) ?: '—' ?></td>
                            <td><?= htmlspecialchars($d['stage_name']) ?: '—' ?></td>
                            <td><?= $d['amount'] !== null ? '&pound;' . number_format($d['amount'], 2) : '—' ?></td>
                            <td><?= $d['age_days'] !== null ? number_format($d['age_days'], 0) : '—' ?></td>
                            <td><?= htmlspecialchars($d['account_owner']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($opportunities)): ?>
                        <tr><td colspan="9" class="text-muted">No opportunities matched for this reseller. <a href="opportunity_checker.php?vendor_id=<?= urlencode($vendorId) ?>">Check matching</a></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($vendorsWithSales)): ?>
    <div class="alert alert-info">
        <i class="ti ti-info-circle me-2"></i>No matched vendor data yet. <a href="mapping.php">Map resellers to vendors</a> and <a href="reseller_report.php">reapply mappings</a> to see vendor-level reports.
    </div>
    <?php elseif (!$vendorId && !$unmappedReseller && empty($monthlyByYear)): ?>
    <div class="alert alert-warning">
        <i class="ti ti-alert-triangle me-2"></i>Select a specific vendor to see YoY charts and forecasts.
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js" defer></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const pound = '\u00A3';
    const fmt = v => pound + parseFloat(v).toLocaleString('en-GB', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    const monthLabels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

    // Unmapped reseller search autocomplete
    (function() {
        const input = document.getElementById('unmappedResellerInput');
        const dropdown = document.getElementById('unmappedResellerDropdown');
        const vendorSelect = document.getElementById('vendorSelect');
        if (!input || !dropdown) return;

        let debounce;
        function hide() { dropdown.style.display = 'none'; }

        function escapeHtml(s) {
            const d = document.createElement('div');
            d.textContent = s || '';
            return d.innerHTML;
        }
        function showResults(items) {
            if (!items.length) { hide(); return; }
            dropdown.innerHTML = items.map(r =>
                '<a href="#" class="list-group-item list-group-item-action" data-name="' + escapeHtml(r.reseller_name || '').replace(/"/g, '&quot;') + '">' +
                escapeHtml(r.reseller_name || '') + ' <span class="badge bg-secondary ms-1">' + pound + parseFloat(r.total || 0).toLocaleString('en-GB', {maximumFractionDigits:0}) + '</span></a>'
            );
            dropdown.style.display = 'block';
            dropdown.querySelectorAll('a').forEach(a => {
                a.addEventListener('click', function(e) {
                    e.preventDefault();
                    input.value = this.dataset.name || '';
                    if (vendorSelect) vendorSelect.value = '';
                    hide();
                    input.closest('form').submit();
                });
            });
        }

        input.addEventListener('input', function() {
            clearTimeout(debounce);
            const q = input.value.trim();
            if (q.length < 1) { hide(); return; }
            debounce = setTimeout(() => {
                fetch('mapping_api.php?type=unmapped_reseller&q=' + encodeURIComponent(q))
                    .then(r => r.json())
                    .then(d => showResults(d.results || []))
                    .catch(() => hide());
            }, 200);
        });
        input.addEventListener('focus', function() {
            if (input.value.trim().length >= 1) {
                fetch('mapping_api.php?type=unmapped_reseller&q=' + encodeURIComponent(input.value.trim()))
                    .then(r => r.json())
                    .then(d => showResults(d.results || []))
                    .catch(() => hide());
            }
        });
        input.addEventListener('blur', () => setTimeout(hide, 200));
        document.addEventListener('click', function(e) {
            if (!dropdown.contains(e.target) && e.target !== input) hide();
        });

        vendorSelect && vendorSelect.addEventListener('change', function() {
            if (this.value) input.value = '';
        });
    })();

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
});

// Sortable table functionality for opportunities
(function() {
    function makeSortable(table) {
        if (!table) return;
        const headers = table.querySelectorAll('th.sortable');
        let currentSort = { col: null, dir: 'asc' };
        
        headers.forEach((th, idx) => {
            th.style.cursor = 'pointer';
            th.addEventListener('click', function() {
                const col = this.dataset.sort;
                const tbody = table.querySelector('tbody');
                const rows = Array.from(tbody.querySelectorAll('tr'));
                
                // Toggle direction if same column
                if (currentSort.col === col) {
                    currentSort.dir = currentSort.dir === 'asc' ? 'desc' : 'asc';
                } else {
                    currentSort.col = col;
                    currentSort.dir = 'asc';
                }
                
                // Update header icons
                headers.forEach(h => {
                    const icon = h.querySelector('i');
                    if (icon) {
                        icon.className = 'ti ti-arrows-sort ms-1';
                        if (h === th) {
                            icon.className = currentSort.dir === 'asc' ? 'ti ti-arrow-up ms-1' : 'ti ti-arrow-down ms-1';
                        }
                    }
                });
                
                // Sort rows
                rows.sort((a, b) => {
                    let aVal = a.dataset[col] || '';
                    let bVal = b.dataset[col] || '';
                    
                    // Numeric sort for amount
                    if (col === 'amount') {
                        aVal = parseFloat(aVal) || 0;
                        bVal = parseFloat(bVal) || 0;
                        return currentSort.dir === 'asc' ? aVal - bVal : bVal - aVal;
                    }
                    
                    // Numeric sort for age (days)
                    if (col === 'age') {
                        aVal = parseFloat(aVal) || 0;
                        bVal = parseFloat(bVal) || 0;
                        return currentSort.dir === 'asc' ? aVal - bVal : bVal - aVal;
                    }
                    
                    // String sort
                    if (aVal < bVal) return currentSort.dir === 'asc' ? -1 : 1;
                    if (aVal > bVal) return currentSort.dir === 'asc' ? 1 : -1;
                    return 0;
                });
                
                // Re-append sorted rows
                rows.forEach(row => tbody.appendChild(row));
            });
        });
    }
    
    document.querySelectorAll('.sortable-table').forEach(makeSortable);
})();
</script>
</div></div>
</body>
</html>
