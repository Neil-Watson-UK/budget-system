<?php
// salesout/insights.php - Category analysis, product families, trends & forecasting
session_start();
ob_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=' . urlencode('salesout/insights.php'));
    exit;
}

$pdo = getDBConnection();
require_once __DIR__ . '/header.php';
if (ob_get_level()) { ob_flush(); }
flush();

$dateFrom = $_GET['date_from'] ?? date('Y-m-01', strtotime('-12 months')); // default: 12 months ago (start of month)
$dateTo = $_GET['date_to'] ?? date('Y-m-d');     // default: today
$distributor = $_GET['distributor'] ?? '';
$category = $_GET['category'] ?? '';
$productSeries = $_GET['product_series'] ?? '';
$productName = trim($_GET['product_name'] ?? '');
$productSku = trim($_GET['product_sku'] ?? '');
$forecastMonths = (int) ($_GET['forecast_months'] ?? 3);

$params = [$dateFrom, $dateTo];
$where = "s.report_date BETWEEN ? AND ?";
if (!empty($distributor)) {
    $where .= " AND s.distributor_name = ?";
    $params[] = $distributor;
}
if (!empty($category)) {
    $where .= " AND COALESCE(p.product_category, p.product_line, 'Uncategorised') = ?";
    $params[] = $category;
}
if (!empty($productSeries)) {
    $where .= " AND COALESCE(p.product_series, p.product_line, 'Other') = ?";
    $params[] = $productSeries;
}
if (!empty($productName)) {
    $where .= " AND (s.product_name LIKE ? OR COALESCE(p.product_name, '') LIKE ?)";
    $pnLike = '%' . $productName . '%';
    $params[] = $pnLike;
    $params[] = $pnLike;
}
if (!empty($productSku)) {
    $where .= " AND (s.sku LIKE ? OR (p.sku IS NOT NULL AND p.sku LIKE ?))";
    $skuLike = '%' . $productSku . '%';
    $params[] = $skuLike;
    $params[] = $skuLike;
}

$byProductType = [];
$byProductLine = [];
$byProductSeries = [];
$monthlyTrend = [];
$topSkuByCategory = [];
$emergingResellers = [];
$topGrowingProducts = [];
$topShrinkingProducts = [];
$topGrowersByAmplify = [];
$forecast = null;
$distributors = [];
$categories = [];
$inventorySummary = null;
$totalDistReported = 0;
$totalAtTrade = 0;
$totalRows = 0;
$dbError = null;

$hasImageCol = false;
try {
    $hasImageCol = (bool)$pdo->query("SHOW COLUMNS FROM sales_out_products LIKE 'image_thumb'")->fetch();
} catch (PDOException $e) { /* ignore */ }

$currentYear = (int) date('Y');
$dateFromYear = (int) substr($dateFrom, 0, 4);
$dateToYear = (int) substr($dateTo, 0, 4);

try {
    // === PHASE 1: Critical path for LCP (KPI cards) - run first, output & flush before heavy queries ===
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

    $inventorySummary = getInventorySummary($pdo, $distributor ?: '', 8);

    $insightsTargetInfo = null;
    if ($dateFromYear === $currentYear && $dateToYear === $currentYear) {
        try {
            $tstmt = null;
            if (!empty($distributor)) {
                $tstmt = $pdo->prepare("SELECT annual_target FROM sales_out_targets WHERE target_type = 'distributor' AND entity_key = ? AND year = ?");
                $tstmt->execute([$distributor, $currentYear]);
            } else {
                $tstmt = $pdo->prepare("SELECT SUM(annual_target) as annual_target FROM sales_out_targets WHERE target_type = 'distributor' AND year = ?");
                $tstmt->execute([$currentYear]);
            }
            $trow = $tstmt->fetch(PDO::FETCH_ASSOC);
            if ($trow && (float)($trow['annual_target'] ?? 0) > 0) {
                $targetSeasonality = getSeasonalityPercentages($pdo);
                $timeElapsed = getTimeElapsedForYear($currentYear);
                $targetToDate = getTargetToDate((float)$trow['annual_target'], $targetSeasonality, $currentYear);
                $insightsTargetInfo = [
                    'annual_target' => (float)$trow['annual_target'],
                    'actual' => $totalDistReported,
                    'target_to_date' => $targetToDate,
                    'time_elapsed' => $timeElapsed,
                    'label' => !empty($distributor) ? $distributor : 'All distributors',
                    'pct' => round(100 * $totalDistReported / (float)$trow['annual_target'], 1),
                    'vs_time' => $targetToDate > 0 ? round(100 * $totalDistReported / $targetToDate, 1) : 0,
                ];
            }
        } catch (PDOException $e) { /* targets table may not exist */ }
    }

    // Filter options: categories/series from products table (fast); distributors from sales (needed for filter)
    $distributors = $pdo->query("SELECT DISTINCT distributor_name FROM sales_out_raw WHERE distributor_name != '' ORDER BY distributor_name LIMIT 500")->fetchAll(PDO::FETCH_COLUMN);
    $categories = $pdo->query("SELECT DISTINCT COALESCE(product_category, product_line, 'Uncategorised') FROM sales_out_products WHERE (product_category IS NOT NULL OR product_line IS NOT NULL) ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
    $seriesList = $pdo->query("SELECT DISTINCT product_series FROM sales_out_products WHERE product_series IS NOT NULL AND product_series != '' ORDER BY product_series")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

if (!empty($dbError)) {
    echo '<div class="container-xl py-4"><div class="alert alert-danger">' . htmlspecialchars($dbError) . '</div></div></div></div></body></html>';
    exit;
}

// === PHASE 2: Heavy queries for charts and tables ===
try {
    $dbError = null;
    $byProductType = $pdo->prepare("
        SELECT COALESCE(p.product_category, p.product_line, 'Uncategorised') as category,
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
        SELECT COALESCE(p.product_line, p.product_category, 'Other') as product_line,
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
    // Example product per series: deferred for performance (was 12 extra queries)

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

    // Simple forecast: average of last N months projected forward (join products when filtering by category/product/series)
    $forecastJoin = (!empty($category) || !empty($productName) || !empty($productSku) || !empty($productSeries)) ? " LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))" : "";
    $forecastData = $pdo->prepare("
        SELECT DATE_FORMAT(s.report_date, '%Y-%m') as month, COALESCE(SUM(s.total_value), 0) as dist_reported
        FROM sales_out_raw s $forecastJoin
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
        $imgSel = $hasImageCol ? ", MAX(p.image_thumb) as image_thumb" : ", NULL as image_thumb";
        $stmt = $pdo->prepare("
            SELECT s.sku, COALESCE(p.product_name, s.product_name) as product_name,
                SUM(s.quantity) as total_qty,
                COALESCE(SUM(s.total_value), 0) as dist_reported $imgSel
            FROM sales_out_raw s
            LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
            WHERE $where
            AND COALESCE(p.product_category, p.product_line, 'Uncategorised') = ?
            GROUP BY s.sku, product_name
            ORDER BY dist_reported DESC
            LIMIT 5
        ");
        $stmt->execute(array_merge($params, [$cat]));
        $topSkuByCategory[$cat] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Emerging resellers: growing accounts (prior half vs recent half of date range)
    $tsFrom = strtotime($dateFrom);
    $tsTo = strtotime($dateTo);
    if ($tsTo > $tsFrom + 86400 * 60) { // at least ~60 days
        $midTs = (int)(($tsFrom + $tsTo) / 2);
        $midDate = date('Y-m-d', $midTs);
        $emergingStmt = $pdo->prepare("
            SELECT * FROM (
                SELECT v.vendor_name, v.id as vendor_id,
                    SUM(CASE WHEN s.report_date < ? THEN s.total_value ELSE 0 END) as prior_val,
                    SUM(CASE WHEN s.report_date >= ? THEN s.total_value ELSE 0 END) as recent_val
                FROM sales_out_raw s
                INNER JOIN vendors v ON s.matched_vendor_id = v.id
                LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
                WHERE s.matched_vendor_id IS NOT NULL AND s.report_date BETWEEN ? AND ?
                " . (!empty($distributor) ? " AND s.distributor_name = ?" : "") . "
                " . (!empty($category) ? " AND COALESCE(p.product_category, p.product_line, 'Uncategorised') = ?" : "") . "
                " . (!empty($productName) ? " AND (s.product_name LIKE ? OR COALESCE(p.product_name, '') LIKE ?)" : "") . "
                " . (!empty($productSku) ? " AND (s.sku LIKE ? OR (p.sku IS NOT NULL AND p.sku LIKE ?))" : "") . "
                GROUP BY s.matched_vendor_id, v.vendor_name, v.id
            ) sub
            WHERE prior_val >= 500 AND recent_val > prior_val
            ORDER BY (recent_val - prior_val) / prior_val DESC, recent_val DESC
            LIMIT 15
        ");
        $emergingBind = [$midDate, $midDate, $dateFrom, $dateTo];
        if (!empty($distributor)) $emergingBind[] = $distributor;
        if (!empty($category)) $emergingBind[] = $category;
        if (!empty($productName)) { $pn = '%' . $productName . '%'; $emergingBind[] = $pn; $emergingBind[] = $pn; }
        if (!empty($productSku)) { $sku = '%' . $productSku . '%'; $emergingBind[] = $sku; $emergingBind[] = $sku; }
        $emergingStmt->execute($emergingBind);
        $raw = $emergingStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($raw as $r) {
            $r['growth_pct'] = $r['prior_val'] > 0 ? round(100 * ($r['recent_val'] - $r['prior_val']) / $r['prior_val'], 1) : 0;
            $emergingResellers[] = $r;
        }
        
        // Top 5 growing products (comparing first half vs second half)
        $growingProductsStmt = $pdo->prepare("
            SELECT * FROM (
                SELECT COALESCE(p.sku, s.sku) as sku,
                    COALESCE(p.product_name, s.product_name) as product_name,
                    SUM(CASE WHEN s.report_date < ? THEN s.total_value ELSE 0 END) as prior_val,
                    SUM(CASE WHEN s.report_date >= ? THEN s.total_value ELSE 0 END) as recent_val,
                    SUM(CASE WHEN s.report_date < ? THEN s.quantity ELSE 0 END) as prior_qty,
                    SUM(CASE WHEN s.report_date >= ? THEN s.quantity ELSE 0 END) as recent_qty
                FROM sales_out_raw s
                LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
                WHERE s.report_date BETWEEN ? AND ?
                AND s.sku IS NOT NULL AND s.sku != ''
                " . (!empty($distributor) ? " AND s.distributor_name = ?" : "") . "
                " . (!empty($category) ? " AND COALESCE(p.product_category, p.product_line, 'Uncategorised') = ?" : "") . "
                " . (!empty($productName) ? " AND (s.product_name LIKE ? OR COALESCE(p.product_name, '') LIKE ?)" : "") . "
                " . (!empty($productSku) ? " AND (s.sku LIKE ? OR (p.sku IS NOT NULL AND p.sku LIKE ?))" : "") . "
                GROUP BY COALESCE(p.sku, s.sku), COALESCE(p.product_name, s.product_name)
            ) sub
            WHERE prior_val >= 100 AND recent_val > prior_val
            ORDER BY (recent_val - prior_val) / prior_val DESC, recent_val DESC
            LIMIT 5
        ");
        $growingBind = [$midDate, $midDate, $midDate, $midDate, $dateFrom, $dateTo];
        if (!empty($distributor)) $growingBind[] = $distributor;
        if (!empty($category)) $growingBind[] = $category;
        if (!empty($productName)) { $pn = '%' . $productName . '%'; $growingBind[] = $pn; $growingBind[] = $pn; }
        if (!empty($productSku)) { $sku = '%' . $productSku . '%'; $growingBind[] = $sku; $growingBind[] = $sku; }
        $growingProductsStmt->execute($growingBind);
        $raw = $growingProductsStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($raw as $r) {
            $r['growth_pct'] = $r['prior_val'] > 0 ? round(100 * ($r['recent_val'] - $r['prior_val']) / $r['prior_val'], 1) : 0;
            $topGrowingProducts[] = $r;
        }
        
        // Top 5 fastest shrinking products
        $shrinkingProductsStmt = $pdo->prepare("
            SELECT * FROM (
                SELECT COALESCE(p.sku, s.sku) as sku,
                    COALESCE(p.product_name, s.product_name) as product_name,
                    SUM(CASE WHEN s.report_date < ? THEN s.total_value ELSE 0 END) as prior_val,
                    SUM(CASE WHEN s.report_date >= ? THEN s.total_value ELSE 0 END) as recent_val,
                    SUM(CASE WHEN s.report_date < ? THEN s.quantity ELSE 0 END) as prior_qty,
                    SUM(CASE WHEN s.report_date >= ? THEN s.quantity ELSE 0 END) as recent_qty
                FROM sales_out_raw s
                LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
                WHERE s.report_date BETWEEN ? AND ?
                AND s.sku IS NOT NULL AND s.sku != ''
                " . (!empty($distributor) ? " AND s.distributor_name = ?" : "") . "
                " . (!empty($category) ? " AND COALESCE(p.product_category, p.product_line, 'Uncategorised') = ?" : "") . "
                " . (!empty($productName) ? " AND (s.product_name LIKE ? OR COALESCE(p.product_name, '') LIKE ?)" : "") . "
                " . (!empty($productSku) ? " AND (s.sku LIKE ? OR (p.sku IS NOT NULL AND p.sku LIKE ?))" : "") . "
                GROUP BY COALESCE(p.sku, s.sku), COALESCE(p.product_name, s.product_name)
            ) sub
            WHERE prior_val >= 100 AND recent_val < prior_val AND recent_val > 0
            ORDER BY (recent_val - prior_val) / prior_val ASC, prior_val DESC
            LIMIT 5
        ");
        $shrinkingBind = [$midDate, $midDate, $midDate, $midDate, $dateFrom, $dateTo];
        if (!empty($distributor)) $shrinkingBind[] = $distributor;
        if (!empty($category)) $shrinkingBind[] = $category;
        if (!empty($productName)) { $pn = '%' . $productName . '%'; $shrinkingBind[] = $pn; $shrinkingBind[] = $pn; }
        if (!empty($productSku)) { $sku = '%' . $productSku . '%'; $shrinkingBind[] = $sku; $shrinkingBind[] = $sku; }
        $shrinkingProductsStmt->execute($shrinkingBind);
        $raw = $shrinkingProductsStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($raw as $r) {
            $r['decline_pct'] = $r['prior_val'] > 0 ? round(100 * ($r['recent_val'] - $r['prior_val']) / $r['prior_val'], 1) : 0;
            $topShrinkingProducts[] = $r;
        }
        
        // Top 5 growers by reseller for each Amplify level
        try {
            $amplifyCols = $pdo->query("SHOW COLUMNS FROM vendors LIKE 'AMPLIFY_Level__c'")->fetch();
            if ($amplifyCols) {
                // Get all Amplify levels
                $allAmplifyLevelsStmt = $pdo->prepare("
                    SELECT DISTINCT COALESCE(NULLIF(TRIM(v.AMPLIFY_Level__c), ''), 'Uncategorised') as amplify_level
                    FROM sales_out_raw s
                    INNER JOIN vendors v ON s.matched_vendor_id = v.id
                    WHERE s.matched_vendor_id IS NOT NULL AND s.report_date BETWEEN ? AND ?
                    " . (!empty($distributor) ? " AND s.distributor_name = ?" : "") . "
                    ORDER BY amplify_level
                ");
                $amplifyLevelsBind = [$dateFrom, $dateTo];
                if (!empty($distributor)) $amplifyLevelsBind[] = $distributor;
                $allAmplifyLevelsStmt->execute($amplifyLevelsBind);
                $allAmplifyLevels = $allAmplifyLevelsStmt->fetchAll(PDO::FETCH_COLUMN);
                
                foreach ($allAmplifyLevels as $level) {
                    $growersByLevelStmt = $pdo->prepare("
                        SELECT * FROM (
                            SELECT v.vendor_name, v.id as vendor_id,
                                COALESCE(NULLIF(TRIM(v.AMPLIFY_Level__c), ''), 'Uncategorised') as amplify_level,
                                SUM(CASE WHEN s.report_date < ? THEN s.total_value ELSE 0 END) as prior_val,
                                SUM(CASE WHEN s.report_date >= ? THEN s.total_value ELSE 0 END) as recent_val
                            FROM sales_out_raw s
                            INNER JOIN vendors v ON s.matched_vendor_id = v.id
                            LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
                            WHERE s.matched_vendor_id IS NOT NULL 
                            AND s.report_date BETWEEN ? AND ?
                            AND COALESCE(NULLIF(TRIM(v.AMPLIFY_Level__c), ''), 'Uncategorised') = ?
                            " . (!empty($distributor) ? " AND s.distributor_name = ?" : "") . "
                            " . (!empty($category) ? " AND COALESCE(p.product_category, p.product_line, 'Uncategorised') = ?" : "") . "
                            " . (!empty($productName) ? " AND (s.product_name LIKE ? OR COALESCE(p.product_name, '') LIKE ?)" : "") . "
                            " . (!empty($productSku) ? " AND (s.sku LIKE ? OR (p.sku IS NOT NULL AND p.sku LIKE ?))" : "") . "
                            GROUP BY s.matched_vendor_id, v.vendor_name, v.id, amplify_level
                        ) sub
                        WHERE prior_val >= 500 AND recent_val > prior_val
                        ORDER BY (recent_val - prior_val) / prior_val DESC, recent_val DESC
                        LIMIT 5
                    ");
                    $growersBind = [$midDate, $midDate, $dateFrom, $dateTo, $level];
                    if (!empty($distributor)) $growersBind[] = $distributor;
                    if (!empty($category)) $growersBind[] = $category;
                    if (!empty($productName)) { $pn = '%' . $productName . '%'; $growersBind[] = $pn; $growersBind[] = $pn; }
                    if (!empty($productSku)) { $sku = '%' . $productSku . '%'; $growersBind[] = $sku; $growersBind[] = $sku; }
                    $growersByLevelStmt->execute($growersBind);
                    $raw = $growersByLevelStmt->fetchAll(PDO::FETCH_ASSOC);
                    $levelGrowers = [];
                    foreach ($raw as $r) {
                        $r['growth_pct'] = $r['prior_val'] > 0 ? round(100 * ($r['recent_val'] - $r['prior_val']) / $r['prior_val'], 1) : 0;
                        $levelGrowers[] = $r;
                    }
                    if (!empty($levelGrowers)) {
                        $topGrowersByAmplify[$level] = $levelGrowers;
                    }
                }
            }
        } catch (PDOException $e) { /* column may not exist */ }
    }

    $inventorySummary = getInventorySummary($pdo, $distributor ?: '', 8);

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
$chartColors = ['#00a399', '#00353d', '#ff5549', '#666666', '#cccccc', '#f59e0b', '#00a399', '#00353d', '#ff5549', '#666666'];
$categoryColors = json_encode(array_slice($chartColors, 0, count($byProductType)));

if (!empty($dbError)) {
    echo '<div class="container-xl py-4"><div class="alert alert-danger">' . htmlspecialchars($dbError) . '</div></div></div></div></body></html>';
    exit;
}

$INSIGHTS_PHASE = 1;
include __DIR__ . '/insights_render.php';
?>
<div class="container-xl">
    <div class="report-section">
        <h2 class="report-section-title"><span class="section-num">2</span> Performance by category &amp; product line</h2>
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
            <p class="text-muted small mt-2 mb-0 report-serif">Last month in data: <?= htmlspecialchars($forecast['last_month']) ?>. Projection based on historical average.</p>
        </div>
    </div>
    <?php endif; ?>
    </div><!-- /report-section 2 -->

    <div class="report-section">
        <h2 class="report-section-title"><span class="section-num">3</span> Growth &amp; emerging trends</h2>
    <?php if (!empty($topGrowingProducts)): ?>
    <div class="chart-card mb-4">
        <div class="card-header"><i class="ti ti-trending-up me-2"></i>Top 5 Growing Products</div>
        <div class="card-body">
            <p class="text-muted small mb-3 report-serif">Products with highest growth comparing first half vs second half of date range. Min £100 in prior period.</p>
            <div class="table-responsive">
                <table class="table table-hover table-striped table-bordered mb-0">
                    <thead class="table-light"><tr><th>SKU</th><th>Product Name</th><th>Prior Period</th><th>Recent Period</th><th>Growth</th></tr></thead>
                    <tbody>
                        <?php foreach ($topGrowingProducts as $p): ?>
                        <tr>
                            <td><a href="product_detail.php?sku=<?= urlencode($p['sku']) ?>"><code><?= htmlspecialchars($p['sku']) ?></code></a></td>
                            <td><a href="product_detail.php?sku=<?= urlencode($p['sku']) ?>" class="text-decoration-none"><?= htmlspecialchars($p['product_name']) ?></a></td>
                            <td>£<?= number_format($p['prior_val'], 0) ?></td>
                            <td>£<?= number_format($p['recent_val'], 0) ?></td>
                            <td><span class="badge" style="background-color:#00a399">+<?= $p['growth_pct'] ?? 0 ?>%</span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($topShrinkingProducts)): ?>
    <div class="chart-card mb-4">
        <div class="card-header"><i class="ti ti-trending-down me-2"></i>Top 5 Fastest Shrinking Products</div>
        <div class="card-body">
            <p class="text-muted small mb-3 report-serif">Products with largest decline comparing first half vs second half of date range. Min £100 in prior period.</p>
            <div class="table-responsive">
                <table class="table table-hover table-striped table-bordered mb-0">
                    <thead class="table-light"><tr><th>SKU</th><th>Product Name</th><th>Prior Period</th><th>Recent Period</th><th>Decline</th></tr></thead>
                    <tbody>
                        <?php foreach ($topShrinkingProducts as $p): ?>
                        <tr>
                            <td><a href="product_detail.php?sku=<?= urlencode($p['sku']) ?>"><code><?= htmlspecialchars($p['sku']) ?></code></a></td>
                            <td><a href="product_detail.php?sku=<?= urlencode($p['sku']) ?>" class="text-decoration-none"><?= htmlspecialchars($p['product_name']) ?></a></td>
                            <td>£<?= number_format($p['prior_val'], 0) ?></td>
                            <td>£<?= number_format($p['recent_val'], 0) ?></td>
                            <td><span class="badge" style="background-color:#ff5549"><?= $p['decline_pct'] ?? 0 ?>%</span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($topGrowersByAmplify)): ?>
    <div class="chart-card mb-4">
        <div class="card-header"><i class="ti ti-users me-2"></i>Top 5 Growers by Amplify Level</div>
        <div class="card-body">
            <p class="text-muted small mb-3 report-serif">Resellers with highest growth by Amplify level, comparing first half vs second half of date range. Min £500 in prior period.</p>
            <div class="row">
                <?php foreach ($topGrowersByAmplify as $level => $growers): ?>
                <div class="col-md-6 mb-3">
                    <h6 class="text-muted mb-2"><?= htmlspecialchars($level) ?></h6>
                    <div class="table-responsive">
                        <table class="table table-hover table-striped table-bordered table-sm mb-0">
                            <thead class="table-light"><tr><th>Reseller</th><th>Prior</th><th>Recent</th><th>Growth</th></tr></thead>
                            <tbody>
                                <?php foreach ($growers as $g): ?>
                                <tr>
                                    <td><a href="reseller_report.php?vendor_id=<?= (int)($g['vendor_id'] ?? 0) ?>" class="text-decoration-none"><?= htmlspecialchars($g['vendor_name']) ?></a></td>
                                    <td>£<?= number_format($g['prior_val'], 0) ?></td>
                                    <td>£<?= number_format($g['recent_val'], 0) ?></td>
                                    <td><span class="badge" style="background-color:#00a399">+<?= $g['growth_pct'] ?? 0 ?>%</span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($emergingResellers)): ?>
    <div class="chart-card mb-4">
        <div class="card-header"><i class="ti ti-trending-up me-2"></i>Emerging Resellers — Growing Accounts</div>
        <div class="card-body">
            <p class="text-muted small mb-3 report-serif">Vendors with highest growth comparing first half vs second half of date range. Min £500 in prior period. Updates with category filter.</p>
            <div class="table-responsive">
                <table class="table table-hover table-striped table-bordered mb-0">
                    <thead class="table-light"><tr><th>Vendor</th><th>Prior period</th><th>Recent period</th><th>Growth</th></tr></thead>
                    <tbody>
                        <?php foreach ($emergingResellers as $r): ?>
                        <tr>
                            <td><a href="reseller_report.php?vendor_id=<?= (int)($r['vendor_id'] ?? 0) ?>"><?= htmlspecialchars($r['vendor_name']) ?></a></td>
                            <td>£<?= number_format($r['prior_val'], 0) ?></td>
                            <td>£<?= number_format($r['recent_val'], 0) ?></td>
                            <td><span class="badge" style="background-color:#00a399">+<?= $r['growth_pct'] ?? 0 ?>%</span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
    </div><!-- /report-section 3 -->

    <div class="report-section">
        <h2 class="report-section-title"><span class="section-num">4</span> Detailed data tables</h2>
    <div class="row">
        <div class="col-lg-6">
            <div class="chart-card">
                <div class="card-header"><i class="ti ti-table me-2"></i>By Product Type / Category (Detail)</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped table-bordered mb-0">
                            <thead class="table-light"><tr><th>Category</th><th>Orders</th><th>Dist. Reported</th><th>At MSRP</th><th>At Trade</th></tr></thead>
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
                            <thead class="table-light"><tr><th>Product Line</th><th>Orders</th><th>Dist. Reported</th><th>At Trade</th></tr></thead>
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
                            <thead class="table-light"><tr><th>Series</th><th>Example product</th><th>Orders</th><th>Dist. Reported</th></tr></thead>
                            <tbody>
                                <?php foreach ($byProductSeries as $r): 
                                    $ex = $r['example'] ?? null;
                                    $seriesUrl = '?product_series=' . urlencode($r['product_series']) . '&date_from=' . urlencode($dateFrom) . '&date_to=' . urlencode($dateTo) . ($distributor ? '&distributor=' . urlencode($distributor) : '') . ($category ? '&category=' . urlencode($category) : '') . ($productName ? '&product_name=' . urlencode($productName) : '') . ($productSku ? '&product_sku=' . urlencode($productSku) : '');
                                ?>
                                <tr role="button" onclick="window.location.href='insights.php<?= $seriesUrl ?>'" style="cursor:pointer" title="Filter by this series">
                                    <td><a href="insights.php<?= $seriesUrl ?>" class="text-decoration-none text-dark" onclick="event.stopPropagation()"><?= htmlspecialchars($r['product_series']) ?></a></td>
                                    <td onclick="event.stopPropagation()">
                                        <?php if ($ex): ?>
                                        <div class="d-flex align-items-center gap-2">
                                            <?php if (!empty($ex['example_thumb'])): ?><img src="<?= htmlspecialchars($ex['example_thumb']) ?>" alt="" class="flex-shrink-0" style="width:36px;height:36px;object-fit:contain;border-radius:6px;background:#f1f5f9" loading="lazy" decoding="async" fetchpriority="low"><?php endif; ?>
                                            <div class="small">
                                                <div class="text-truncate" style="max-width:180px" title="<?= htmlspecialchars($ex['example_name'] ?? '') ?>">
                                                    <a href="product_detail.php?sku=<?= urlencode($ex['example_sku'] ?? '') ?>" class="text-decoration-none"><?= htmlspecialchars($ex['example_name'] ?? '-') ?></a>
                                                </div>
                                                <code class="small"><a href="product_detail.php?sku=<?= urlencode($ex['example_sku'] ?? '') ?>" class="text-decoration-none"><?= htmlspecialchars($ex['example_sku'] ?? '') ?></a></code>
                                            </div>
                                        </div>
                                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                    </td>
                                    <td><?= number_format($r['row_count']) ?></td>
                                    <td>£<?= number_format($r['dist_reported'], 0) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($byProductSeries)): ?>
                                <tr><td colspan="4" class="text-muted">No series data.</td></tr>
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
                            <thead class="table-light"><tr><th>Month</th><th>Orders</th><th>Dist. Reported</th><th>At Trade</th></tr></thead>
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
                                <td class="d-flex align-items-center gap-2">
                                    <?php if (!empty($s['image_thumb'])): ?><img src="<?= htmlspecialchars($s['image_thumb']) ?>" alt="" class="prod-thumb flex-shrink-0" style="width:32px;height:32px;object-fit:contain;border-radius:4px;background:#f1f5f9" loading="lazy" decoding="async" fetchpriority="low"><?php endif; ?>
                                    <code><a href="product_detail.php?sku=<?= urlencode($s['sku'] ?? '') ?>" class="text-decoration-none"><?= htmlspecialchars($s['sku']) ?></a></code>
                                </td>
                                <td class="small text-truncate" style="max-width:120px" title="<?= htmlspecialchars($s['product_name'] ?? '') ?>"><a href="product_detail.php?sku=<?= urlencode($s['sku'] ?? '') ?>" class="text-decoration-none"><?= htmlspecialchars($s['product_name'] ?? '-') ?></a></td>
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

<script src="https://cdn.jsdelivr.net/npm/chart.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/apexcharts" defer></script>
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
                        { label: 'Dist. Reported', data: distData, borderColor: '#00a399', backgroundColor: 'rgba(0,163,153,0.2)', borderWidth: 2, fill: true, tension: 0.4 },
                        { label: 'At Trade', data: tradeData, borderColor: '#00353d', backgroundColor: 'rgba(0,53,61,0.2)', borderWidth: 2, borderDash: [4,4], fill: true, tension: 0.4 }
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
                    { label: 'Historical', data: histData.concat(proj.map(() => null)), borderColor: '#00a399', backgroundColor: 'rgba(0,163,153,0.2)', borderWidth: 2, fill: true, tension: 0.4 },
                    { label: 'Forecast', data: hist.map(() => null).concat(proj.map(p => p.value)), borderColor: '#f59e0b', backgroundColor: 'transparent', borderWidth: 2, borderDash: [6,4], tension: 0.4 }
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
