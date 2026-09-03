<?php
// executive_summary.php - Executive summary of budget for management (1–2 pager)
// Supports: All Regions (region=all) and Regional View (region=X)
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode('executive_summary.php'));
    exit;
}

$pdo = getDBConnection();

$selected_year = $_GET['year'] ?? date('Y');
$selected_region = $_GET['region'] ?? 'all';

// Build WHERE clause for filters
$whereConditions = [];
$params = [];
if ($selected_region !== 'all') {
    $whereConditions[] = 'region = ?';
    $params[] = $selected_region;
}
if ($selected_year && $selected_year !== 'all') {
    $whereConditions[] = budgetYearMatchSql();
    $params = array_merge($params, budgetYearMatchParams($selected_year));
}
$whereClause = empty($whereConditions) ? '1=1' : implode(' AND ', $whereConditions);
$whereClauseInvoiced = $whereClause . " AND status = 'Invoiced'";

// Budget limits and currency
if ($selected_region === 'all') {
    $budget_limit = 0;
    foreach ($REGIONAL_SETTINGS as $region => $settings) {
        $budget_limit += $settings['budget_limit'] ?? 0;
    }
    $currency = 'EUR';
    $currency_symbol = '€';
} else {
    $budget_limit = getRegionalBudgetLimit($selected_region);
    $currency = $REGIONAL_SETTINGS[$selected_region]['currency'] ?? 'EUR';
    $currency_symbol = $CURRENCY_SYMBOLS[$currency] ?? '€';
}

// Global view: amounts converted to EUR. Regional view: amounts in region's local currency
if ($selected_region === 'all') {
    $regional_spent = getSpendInEUR($pdo, $whereClause, $params);
    $invoiced_spent = getSpendInEUR($pdo, $whereClauseInvoiced, $params);
    $vendor_stats = getVendorMatchStatsInEUR($pdo, $whereClause, $params);
} else {
    $regional_spent = convertEURToDisplayCurrency(
        getRegionalSpentWithFiltersInEUR($pdo, $whereClause, $params),
        $currency,
        $pdo
    );
    $invoiced_spent = convertEURToDisplayCurrency(
        getRegionalSpentWithFiltersInEUR($pdo, $whereClauseInvoiced, $params),
        $currency,
        $pdo
    );
    $vendor_stats = getVendorMatchStatsInEUR($pdo, $whereClause, $params);
    foreach (['matched_spend', 'unmatched_spend', 'total_budget'] as $vk) {
        $vendor_stats[$vk] = convertEURToDisplayCurrency((float) ($vendor_stats[$vk] ?? 0), $currency, $pdo);
    }
}
$remaining_budget = $budget_limit - $regional_spent;
$usage_percentage = $budget_limit > 0 ? min(100, ($regional_spent / $budget_limit) * 100) : 0;
$invoiced_usage_percentage = $budget_limit > 0 ? min(100, ($invoiced_spent / $budget_limit) * 100) : 0;
$item_count = getFilteredItemsCount($pdo, $whereClause, $params);
$matched_count = $vendor_stats['matched_count'] ?? 0;
$unmatched_count = $vendor_stats['unmatched_count'] ?? 0;
$matched_spend = $vendor_stats['matched_spend'] ?? 0;
$unmatched_spend = $vendor_stats['unmatched_spend'] ?? 0;

// Region split (only when viewing all regions) - in EUR
$regionSplit = [];
if ($selected_region === 'all') {
    $yearCond = ($selected_year && $selected_year !== 'all') 
        ? " AND " . budgetYearMatchSql('bi.') 
        : "";
    $regionParams = ($selected_year && $selected_year !== 'all') ? budgetYearMatchParams($selected_year) : [];
    $regionSplit = getRegionSplitInEUR($pdo, $yearCond, $regionParams);
}

// Top vendors - EUR for global, local currency for regional
if ($selected_region === 'all') {
    $top_vendors = getTopVendorsInEUR($pdo, $whereClause, $params);
} else {
    $top_vendors = convertReportRowsMoneyFromEUR(getTopVendorsInEUR($pdo, $whereClause, $params), ['total_spent'], $currency, $pdo);
}
$top_vendors = array_slice($top_vendors, 0, 5);

// Status distribution - EUR for global, local for regional
if ($selected_region === 'all') {
    $status_distribution = getStatusDistributionDataInEUR($pdo, $selected_year, $whereClause, $params);
} else {
    $status_distribution = convertReportRowsMoneyFromEUR(
        getStatusDistributionDataInEUR($pdo, $selected_year, $whereClause, $params),
        ['total'],
        $currency,
        $pdo
    );
}

// Monthly trend (when year selected) - EUR for global, local for regional
$byMonth = [];
if ($selected_year && $selected_year !== 'all') {
    if ($selected_region === 'all') {
        $byMonth = getMonthlyTrendDataInEUR($pdo, $selected_year, $whereClause, $params);
    } else {
        $byMonth = convertReportSeriesFromEUR(
            getMonthlyTrendDataInEUR($pdo, $selected_year, $whereClause, $params),
            $currency,
            $pdo
        );
    }
}

// Spend vs time elapsed: are we on track, at risk of over/under spend?
$spendVsTime = null;
if ($selected_year && $selected_year !== 'all' && $budget_limit > 0) {
    $year = (int)$selected_year;
    $isCurrentYear = ($year === (int)date('Y'));
    $yearStart = "$year-01-01";
    $daysInYear = (($year % 4 == 0 && $year % 100 != 0) || ($year % 400 == 0)) ? 366 : 365;
    if ($isCurrentYear) {
        $now = time();
        $start = strtotime($yearStart);
        $daysElapsed = max(0, (int)floor(($now - $start) / 86400));
    } else {
        $daysElapsed = $daysInYear; // Past year = full year
    }
    $timeElapsedPct = min(100, $daysElapsed / $daysInYear * 100);
    $expectedToDate = $budget_limit * ($daysElapsed / $daysInYear);
    $variance = $regional_spent - $expectedToDate;
    $variancePct = $expectedToDate > 0 ? round(100 * ($regional_spent / $expectedToDate) - 100, 1) : 0;
    $projected_full_year = null;
    if ($isCurrentYear && $timeElapsedPct > 0 && $timeElapsedPct < 100) {
        $projected_full_year = $regional_spent / ($timeElapsedPct / 100);
    }
    $spendVsTime = [
        'actual' => $regional_spent,
        'expected_to_date' => $expectedToDate,
        'time_elapsed_pct' => round($timeElapsedPct, 1),
        'days_elapsed' => $daysElapsed,
        'days_in_year' => $daysInYear,
        'variance' => $variance,
        'variance_pct' => $variancePct,
        'is_current_year' => $isCurrentYear,
        'projected_full_year' => $projected_full_year,
    ];
}

// Key takeaways (overspend / budget-exceeded based on invoiced amount only; total = planned + invoiced etc.)
$takeaways = [];
if ($invoiced_usage_percentage >= 100) {
    $takeaways[] = ['type' => 'attention', 'text' => sprintf('Budget exceeded (invoiced): %.1f%% of budget already invoiced (%s).', $invoiced_usage_percentage, formatCurrency($invoiced_spent, $currency))];
} elseif ($usage_percentage >= 100 && $invoiced_usage_percentage < 100) {
    $takeaways[] = ['type' => 'info', 'text' => sprintf('Total planned + invoiced exceeds budget (%.1f%%), but invoiced to date is %s (%.1f%%). Overspend is not yet actual.', $usage_percentage, formatCurrency($invoiced_spent, $currency), $invoiced_usage_percentage)];
}
if ($invoiced_usage_percentage < 100) {
    if ($usage_percentage >= 100) {
        // already added above
    } elseif ($usage_percentage >= 90) {
        $takeaways[] = ['type' => 'attention', 'text' => sprintf('Near budget limit: %.1f%% total utilized. Invoiced: %s (%.1f%%). %s remaining.', $usage_percentage, formatCurrency($invoiced_spent, $currency), $invoiced_usage_percentage, formatCurrency($remaining_budget, $currency))];
    } elseif ($usage_percentage >= 75) {
        $takeaways[] = ['type' => 'info', 'text' => sprintf('Budget on track: %.1f%% utilized. Invoiced: %s. %s remaining.', $usage_percentage, formatCurrency($invoiced_spent, $currency), formatCurrency($remaining_budget, $currency))];
    } else {
        $takeaways[] = ['type' => 'positive', 'text' => sprintf('Budget headroom: %.1f%% utilized. Invoiced: %s. %s remaining.', $usage_percentage, formatCurrency($invoiced_spent, $currency), formatCurrency($remaining_budget, $currency))];
    }
}
if (count($top_vendors) > 0 && $regional_spent > 0) {
    $topShare = round(100 * ($top_vendors[0]['total_spent'] ?? 0) / $regional_spent, 0);
    if ($topShare >= 25) {
        $takeaways[] = ['type' => 'info', 'text' => sprintf('%s accounts for %s%% of spend', $top_vendors[0]['vendor'], $topShare)];
    }
}
if ($matched_count + $unmatched_count > 0) {
    $matchRate = round(100 * $matched_count / ($matched_count + $unmatched_count), 0);
    if ($matchRate < 50) {
        $takeaways[] = ['type' => 'attention', 'text' => sprintf('%.0f%% of vendors matched to Salesforce. Consider improving vendor mapping.', $matchRate)];
    }
}
// Spend vs time: base "at risk of overspend" on invoiced vs expected, not total planned
if ($spendVsTime && $spendVsTime['is_current_year'] && $spendVsTime['expected_to_date'] > 0) {
    $invoiced_variance_pct = round(100 * ($invoiced_spent / $spendVsTime['expected_to_date']) - 100, 1);
    if ($invoiced_variance_pct > 10) {
        $takeaways[] = ['type' => 'attention', 'text' => sprintf('Invoiced amount ahead of schedule: %s%% above expected for %s time elapsed. At risk of overspend (actuals).', $invoiced_variance_pct, $spendVsTime['time_elapsed_pct'] . '%')];
    } elseif ($spendVsTime['variance_pct'] > 10 && $invoiced_variance_pct <= 10) {
        $takeaways[] = ['type' => 'info', 'text' => sprintf('Total planned is ahead of schedule (%s%%), but invoiced to date (%s) is on track. Overspend is not yet actual.', $spendVsTime['variance_pct'], formatCurrency($invoiced_spent, $currency))];
    } elseif ($spendVsTime['variance_pct'] < -10) {
        $takeaways[] = ['type' => 'info', 'text' => sprintf('Spending behind schedule: %s%% below expected for %s time elapsed. May under-utilise budget.', abs($spendVsTime['variance_pct']), $spendVsTime['time_elapsed_pct'] . '%')];
    } else {
        $takeaways[] = ['type' => 'positive', 'text' => sprintf('On track: spend aligns with %s of year elapsed.', $spendVsTime['time_elapsed_pct'] . '%')];
    }
    if (!empty($spendVsTime['projected_full_year']) && $budget_limit > 0) {
        $projPct = round(100 * $spendVsTime['projected_full_year'] / $budget_limit, 0);
        if ($projPct > 110) {
            $takeaways[] = ['type' => 'attention', 'text' => sprintf('Run-rate forecast: %s projected full-year (%.0f%% of budget). Consider reining in planned spend.', formatCurrency($spendVsTime['projected_full_year'], $currency), $projPct)];
        } elseif ($projPct > 100) {
            $takeaways[] = ['type' => 'attention', 'text' => sprintf('Run-rate forecast: %s projected full-year — slightly over budget.', formatCurrency($spendVsTime['projected_full_year'], $currency))];
        } elseif ($projPct < 80) {
            $takeaways[] = ['type' => 'info', 'text' => sprintf('Run-rate forecast: %s projected full-year (%.0f%% of budget). May under-utilise.', formatCurrency($spendVsTime['projected_full_year'], $currency), $projPct)];
        }
    }
}

$chartColors = ['#00a399', '#00353d', '#ff5549', '#666666', '#f59e0b', '#cccccc', '#3498db', '#9b59b6'];
$regionLabels = json_encode(array_column($regionSplit, 'region'));
$regionData = json_encode(array_map('floatval', array_column($regionSplit, 'total')));
// Spend vs time gone (Sales Out style): per-region progress bars with on-track line
$spendVsTimeRegions = [];
if ($selected_region === 'all' && $selected_year && $selected_year !== 'all') {
    $year = (int)$selected_year;
    $isCurrentYear = ($year === (int)date('Y'));
    $daysInYear = (($year % 4 == 0 && $year % 100 != 0) || ($year % 400 == 0)) ? 366 : 365;
    $daysElapsed = $isCurrentYear ? max(0, (int)floor((time() - strtotime("$year-01-01")) / 86400)) : $daysInYear;
    $timeElapsedPct = min(100, $daysElapsed / $daysInYear * 100);
    $regionSpendMap = [];
    foreach ($regionSplit as $r) {
        $regionSpendMap[$r['region']] = (float)$r['total'];
    }
    foreach ($REGIONAL_SETTINGS as $reg => $settings) {
        $bl = (float)($settings['budget_limit'] ?? 0);
        if ($bl <= 0) continue;
        $actual = $regionSpendMap[$reg] ?? 0;
        $expectedToDate = $bl * ($daysElapsed / $daysInYear);
        $refLinePct = min(99, $timeElapsedPct);
        $pct = $bl > 0 ? min(100, $actual / $bl * 100) : 0;
        $pctAhead = $expectedToDate > 0 ? round(100 * ($actual / $expectedToDate) - 100, 1) : 0;
        $spendVsTimeRegions[] = [
            'region' => $reg,
            'actual' => $actual,
            'budget' => $bl,
            'expected_to_date' => $expectedToDate,
            'pct' => round($pct, 1),
            'ref_line_pct' => $refLinePct,
            'pct_ahead' => $pctAhead,
            'is_current_year' => $isCurrentYear,
            'color' => $settings['color'] ?? '#00a399',
        ];
    }
}
$statusLabels = json_encode(array_column($status_distribution, 'status'));
$statusData = json_encode(array_map('floatval', array_column($status_distribution, 'total')));

// Format currency without decimals for spend display
$fmtCurr = function($amt) use ($currency_symbol) { return $currency_symbol . number_format((float)$amt, 0); };
// Region to flag emoji for quick reference
$regionFlags = ['AMER' => "\u{1F1FA}\u{1F1F8}", 'DACH' => "\u{1F1E9}\u{1F1EA}", 'UKI' => "\u{1F1EC}\u{1F1E7}", 'APAC' => "\u{1F1ED}\u{1F1F0}", 'ANZ' => "\u{1F1E6}\u{1F1FA}", 'NORD' => "\u{1F1F8}\u{1F1EA}", 'BNL' => "\u{1F1F3}\u{1F1F1}", 'FRANCE' => "\u{1F1EB}\u{1F1F7}", 'EMEA_PARTNERS' => "\u{1F310}", 'INDIA' => "\u{1F1EE}\u{1F1F3}"];
$monthLabels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

// Available years
$available_years = [];
for ($y = date('Y'); $y >= date('Y') - 5; $y--) {
    $available_years[] = $y;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Budget Executive Summary - <?= defined('APP_NAME') ? APP_NAME : 'Budget System' ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.40.0/tabler-icons.min.css">
</head>
<body>
<?php require_once 'header.php'; ?>

<style>
.exec-summary { max-width: 900px; margin: 0 auto; font-size: 0.95rem; }
.exec-summary .es-header { background: linear-gradient(135deg, #00353d 0%, #00a399 100%); color: white; padding: 1.5rem 2rem; border-radius: 10px; margin-bottom: 1.5rem; }
.exec-summary .es-header h1 { font-size: 1.5rem; margin-bottom: 0.25rem; }
.exec-summary .es-header .es-meta { opacity: 0.9; font-size: 0.85rem; }
.exec-summary .es-section { margin-bottom: 1.25rem; }
.exec-summary .es-section-title { font-weight: 600; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; color: #00353d; margin-bottom: 0.5rem; border-bottom: 2px solid #00a399; padding-bottom: 0.25rem; }
.exec-summary .es-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 1rem; margin-bottom: 1.25rem; }
.exec-summary .es-kpi { background: white; padding: 1rem 1.25rem; border-radius: 8px; text-align: center; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.04); border-left: 4px solid #00a399; }
.exec-summary .es-kpi-value { font-size: 1.4rem; font-weight: 700; color: #0f172a; line-height: 1.3; }
.exec-summary .es-kpi-label { font-size: 0.7rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 0.25rem; }
.exec-summary .takeaway-positive { color: #059669; }
.exec-summary .takeaway-attention { color: #dc2626; }
.exec-summary .takeaway-info { color: #0369a1; }
.exec-summary .es-chart-wrap { display: flex; gap: 1rem; align-items: flex-start; flex-wrap: wrap; }
.exec-summary .es-chart-donut { flex: 0 0 180px; max-width: 180px; height: 180px; }
.exec-summary .es-chart-bar { position: relative; height: 280px; width: 100%; }
/* Spend vs time gone (Sales Out style) */
.exec-summary .target-combined { background: white; border-radius: 10px; padding: 1.25rem; border: 1px solid #D7D2CB; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 1rem; }
.exec-summary .target-combined-main { display: flex; gap: 1.25rem; align-items: flex-start; flex-wrap: wrap; }
.exec-summary .target-combined-left { flex: 1; min-width: 0; }
.exec-summary .target-combined-right { flex: 0 0 22%; min-width: 120px; text-align: center; padding: 0.75rem 1rem; border-radius: 8px; }
.exec-summary .target-combined-value { font-size: 1.35rem; font-weight: 700; line-height: 1.2; margin-bottom: 0.25rem; }
.exec-summary .target-combined-label { font-size: 0.8rem; color: #6b7280; margin-bottom: 0.5rem; }
.exec-summary .target-combined-bar-wrap { position: relative; height: 10px; background: #e5e7eb; border-radius: 5px; overflow: visible; margin-bottom: 0.35rem; }
.exec-summary .target-combined-bar-fill { position: absolute; left: 0; top: 0; height: 100%; border-radius: 5px; transition: width 0.3s ease; z-index: 1; }
.exec-summary .target-combined-ref-line { position: absolute; top: -2px; bottom: -2px; width: 2px; background: rgba(0,0,0,0.5); z-index: 2; }
.exec-summary .target-combined-meta { font-size: 0.7rem; color: #9ca3af; }
.exec-summary .target-combined-pct { font-size: 1.25rem; font-weight: 700; }
.exec-summary .target-combined-pct-label { font-size: 0.65rem; color: #6b7280; }
.exec-summary .spend-vs-time-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem; }
@media print {
    .d-print-none, .navbar, .btn, .filter-card, nav { display: none !important; }
    .exec-summary { max-width: 100%; font-size: 10pt; }
}
</style>

<div class="container-xl py-4">
    <div class="filter-card d-print-none mb-4" style="background: white; border: 1px solid #D7D2CB; border-radius: 10px; padding: 1rem 1.5rem;">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Scope</label>
                <select name="region" class="form-select">
                    <option value="all" <?= $selected_region === 'all' ? 'selected' : '' ?>>All Regions</option>
                    <?php foreach ($REGIONAL_SETTINGS as $region => $settings): ?>
                    <option value="<?= htmlspecialchars($region) ?>" <?= $selected_region === $region ? 'selected' : '' ?>><?= htmlspecialchars($region) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Year</label>
                <select name="year" class="form-select">
                    <option value="all" <?= $selected_year === 'all' ? 'selected' : '' ?>>All Years</option>
                    <?php foreach ($available_years as $y): ?>
                    <option value="<?= (int)$y ?>" <?= $selected_year === (string)$y ? 'selected' : '' ?>><?= (int)$y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary"><i class="ti ti-filter me-1"></i> Apply</button>
            </div>
            <div class="col-md-2">
                <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="ti ti-printer me-1"></i> Print / PDF</button>
            </div>
        </form>
    </div>

    <div class="exec-summary">
        <div class="es-header">
            <h1>Budget — Executive Summary</h1>
            <div class="es-meta">
                <?= $selected_region === 'all' ? 'All Regions' : htmlspecialchars($selected_region) ?>
                · <?= $selected_year === 'all' ? 'All Years' : htmlspecialchars($selected_year) ?>
                · <strong><?= $selected_region === 'all' ? 'All amounts in €' : 'Amounts in ' . $currency_symbol . ' (' . $currency . ')' ?></strong>
                <span class="ms-3">Generated <?= date('d M Y') ?></span>
            </div>
        </div>

        <div class="es-section">
            <div class="es-section-title">Key Metrics</div>
            <div class="es-kpis">
                <div class="es-kpi">
                    <div class="es-kpi-value"><?= formatCurrency($budget_limit, $currency) ?></div>
                    <div class="es-kpi-label">Budget Limit</div>
                </div>
                <div class="es-kpi">
                    <div class="es-kpi-value"><?= formatCurrency($invoiced_spent, $currency) ?></div>
                    <div class="es-kpi-label">Invoiced</div>
                </div>
                <div class="es-kpi">
                    <div class="es-kpi-value"><?= formatCurrency($regional_spent, $currency) ?></div>
                    <div class="es-kpi-label">Total (planned + invoiced)</div>
                </div>
                <div class="es-kpi">
                    <div class="es-kpi-value"><?= formatCurrency($remaining_budget, $currency) ?></div>
                    <div class="es-kpi-label">Remaining</div>
                </div>
                <div class="es-kpi">
                    <div class="es-kpi-value"><?= number_format($item_count) ?></div>
                    <div class="es-kpi-label">Items</div>
                </div>
                <div class="es-kpi" style="border-color: <?= $invoiced_usage_percentage >= 100 ? '#dc2626' : ($usage_percentage >= 75 ? '#f59e0b' : '#00a399') ?>;">
                    <div class="es-kpi-value"><?= number_format($invoiced_usage_percentage, 1) ?>%</div>
                    <div class="es-kpi-label">Invoiced vs budget</div>
                </div>
                <div class="es-kpi" style="border-color: <?= $usage_percentage >= 100 ? '#dc2626' : ($usage_percentage >= 75 ? '#f59e0b' : '#00a399') ?>;">
                    <div class="es-kpi-value"><?= number_format($usage_percentage, 1) ?>%</div>
                    <div class="es-kpi-label">Total utilized</div>
                </div>
                <?php if ($spendVsTime && !empty($spendVsTime['projected_full_year'])): ?>
                <div class="es-kpi" style="border-left-color: #8b5cf6;">
                    <div class="es-kpi-value"><?= formatCurrency($spendVsTime['projected_full_year'], $currency) ?></div>
                    <div class="es-kpi-label">Forecast (run rate)</div>
                    <div class="es-kpi-meta" style="font-size: 0.65rem; color: #94a3b8; margin-top: 0.2rem;">Based on <?= $spendVsTime['time_elapsed_pct'] ?>% of year</div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($takeaways)): ?>
        <div class="es-section">
            <div class="es-section-title">Key Takeaways</div>
            <ul class="mb-0" style="padding-left: 1.25rem;">
                <?php foreach ($takeaways as $t): ?>
                <li class="takeaway-<?= $t['type'] ?> mb-1"><?= htmlspecialchars($t['text']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if ($spendVsTime): ?>
        <div class="es-section">
            <div class="es-section-title">Spend vs Time Gone (<?= $selected_year ?>)</div>
            <p class="small text-muted mb-3">Shows if <?= $selected_region === 'all' ? 'regions are' : 'region is' ?> on track to utilise budget. Bar = actual spend; vertical line = expected spend to date (<?= $spendVsTime['time_elapsed_pct'] ?>% of year elapsed).</p>
            <?php if ($selected_region === 'all'): ?>
            <div class="target-combined mb-4">
                <div class="target-combined-main">
                    <div class="target-combined-left">
                        <div class="target-combined-value" style="color: <?= $spendVsTime['variance_pct'] > 10 ? '#dc2626' : ($spendVsTime['variance_pct'] < -10 ? '#0369a1' : '#059669') ?>"><?= $fmtCurr($spendVsTime['actual']) ?></div>
                        <div class="target-combined-label">Total Budget Spend — All Regions (<?= $selected_year ?>)</div>
                        <div class="target-combined-bar-wrap">
                            <div class="target-combined-bar-fill" style="width: <?= min(100, max(0, $usage_percentage)) ?>%; background: <?= $spendVsTime['variance_pct'] > 10 ? '#dc2626' : ($spendVsTime['variance_pct'] < -10 ? '#0369a1' : '#059669') ?>;"></div>
                            <?php if ($spendVsTime['is_current_year'] && $spendVsTime['time_elapsed_pct'] > 0 && $spendVsTime['time_elapsed_pct'] < 100): ?>
                            <div class="target-combined-ref-line" style="left: <?= $spendVsTime['time_elapsed_pct'] ?>%;" title="On-track: <?= $fmtCurr($spendVsTime['expected_to_date']) ?>"></div>
                            <?php endif; ?>
                        </div>
                        <div class="target-combined-meta">
                            <span><i class="ti ti-target"></i> Annual budget: <?= $fmtCurr($budget_limit) ?></span>
                            <?php if ($spendVsTime['is_current_year']): ?>
                            <span class="ms-2"><i class="ti ti-line-dashed"></i> On-track: <?= $fmtCurr($spendVsTime['expected_to_date']) ?></span>
                            <?php endif; ?>
                            <span class="ms-2"><i class="ti ti-clock"></i> <?= date('d.m.Y') ?></span>
                        </div>
                    </div>
                    <div class="target-combined-right" style="background: <?= $spendVsTime['variance_pct'] > 10 ? 'rgba(255,85,73,0.12)' : ($spendVsTime['variance_pct'] < -10 ? 'rgba(3,105,161,0.12)' : 'rgba(0,163,153,0.12)') ?>;">
                        <div class="target-combined-pct" style="color: <?= $spendVsTime['variance_pct'] > 10 ? '#ff5549' : ($spendVsTime['variance_pct'] < -10 ? '#0369a1' : '#00a399') ?>">
                            <?= $spendVsTime['variance_pct'] >= 0 ? '+' : '' ?><?= $spendVsTime['variance_pct'] ?>%
                        </div>
                        <div class="target-combined-pct-label"><?= $spendVsTime['is_current_year'] ? ($spendVsTime['variance_pct'] > 10 ? 'Ahead (overspend risk)' : ($spendVsTime['variance_pct'] < -10 ? 'Behind (under-utilising)' : 'On track')) : ($spendVsTime['variance_pct'] > 0 ? 'Overspent' : ($spendVsTime['variance_pct'] < 0 ? 'Under-utilised' : 'On budget')) ?></div>
                    </div>
                </div>
            </div>
            <?php if (!empty($spendVsTimeRegions)): ?>
            <div class="spend-vs-time-grid">
                <?php foreach ($spendVsTimeRegions as $r): 
                    $flag = $regionFlags[$r['region']] ?? '';
                ?>
                <div class="target-combined">
                    <div class="target-combined-main">
                        <div class="target-combined-left">
                            <div class="target-combined-value" style="color: <?= $r['pct_ahead'] > 10 ? '#dc2626' : ($r['pct_ahead'] < -10 ? '#0369a1' : '#059669') ?>"><?= $fmtCurr($r['actual']) ?></div>
                            <div class="target-combined-label"><?= $flag ? $flag . ' ' : '' ?><?= htmlspecialchars($r['region']) ?> (<?= $selected_year ?>)</div>
                            <div class="target-combined-bar-wrap">
                                <div class="target-combined-bar-fill" style="width: <?= min(100, max(0, $r['pct'])) ?>%; background: <?= $r['pct_ahead'] > 10 ? '#dc2626' : ($r['pct_ahead'] < -10 ? '#0369a1' : '#059669') ?>;"></div>
                                <?php if ($r['is_current_year'] && $r['ref_line_pct'] > 0 && $r['ref_line_pct'] < 100): ?>
                                <div class="target-combined-ref-line" style="left: <?= $r['ref_line_pct'] ?>%;" title="On-track: <?= $fmtCurr($r['expected_to_date']) ?>"></div>
                                <?php endif; ?>
                            </div>
                            <div class="target-combined-meta">
                                <span>Budget <?= $fmtCurr($r['budget']) ?></span>
                            </div>
                        </div>
                        <div class="target-combined-right" style="background: <?= $r['pct_ahead'] > 10 ? 'rgba(255,85,73,0.12)' : ($r['pct_ahead'] < -10 ? 'rgba(3,105,161,0.12)' : 'rgba(0,163,153,0.12)') ?>;">
                            <div class="target-combined-pct" style="color: <?= $r['pct_ahead'] > 10 ? '#ff5549' : ($r['pct_ahead'] < -10 ? '#0369a1' : '#00a399') ?>"><?= $r['pct_ahead'] >= 0 ? '+' : '' ?><?= $r['pct_ahead'] ?>%</div>
                            <div class="target-combined-pct-label"><?= $r['is_current_year'] ? ($r['pct_ahead'] > 10 ? 'Ahead' : ($r['pct_ahead'] < -10 ? 'Behind' : 'On track')) : ($r['pct_ahead'] > 0 ? 'Overspent' : ($r['pct_ahead'] < 0 ? 'Under' : 'On budget')) ?></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php else: 
                $regFlag = $regionFlags[$selected_region] ?? '';
            ?>
            <div class="target-combined">
                <div class="target-combined-main">
                    <div class="target-combined-left">
                        <div class="target-combined-value" style="color: <?= $spendVsTime['variance_pct'] > 10 ? '#dc2626' : ($spendVsTime['variance_pct'] < -10 ? '#0369a1' : '#059669') ?>"><?= $fmtCurr($spendVsTime['actual']) ?></div>
                        <div class="target-combined-label"><?= $regFlag ? $regFlag . ' ' : '' ?><?= htmlspecialchars($selected_region) ?> Budget Spend (<?= $selected_year ?>)</div>
                        <div class="target-combined-bar-wrap">
                            <div class="target-combined-bar-fill" style="width: <?= min(100, max(0, $usage_percentage)) ?>%; background: <?= $spendVsTime['variance_pct'] > 10 ? '#dc2626' : ($spendVsTime['variance_pct'] < -10 ? '#0369a1' : '#059669') ?>;"></div>
                            <?php if ($spendVsTime['is_current_year'] && $spendVsTime['time_elapsed_pct'] > 0 && $spendVsTime['time_elapsed_pct'] < 100): ?>
                            <div class="target-combined-ref-line" style="left: <?= $spendVsTime['time_elapsed_pct'] ?>%;" title="On-track: <?= $fmtCurr($spendVsTime['expected_to_date']) ?>"></div>
                            <?php endif; ?>
                        </div>
                        <div class="target-combined-meta">
                            <span><i class="ti ti-target"></i> Annual budget: <?= $fmtCurr($budget_limit) ?></span>
                            <?php if ($spendVsTime['is_current_year']): ?>
                            <span class="ms-2"><i class="ti ti-line-dashed"></i> On-track: <?= $fmtCurr($spendVsTime['expected_to_date']) ?></span>
                            <?php endif; ?>
                            <span class="ms-2"><i class="ti ti-clock"></i> <?= date('d.m.Y') ?></span>
                        </div>
                    </div>
                    <div class="target-combined-right" style="background: <?= $spendVsTime['variance_pct'] > 10 ? 'rgba(255,85,73,0.12)' : ($spendVsTime['variance_pct'] < -10 ? 'rgba(3,105,161,0.12)' : 'rgba(0,163,153,0.12)') ?>;">
                        <div class="target-combined-pct" style="color: <?= $spendVsTime['variance_pct'] > 10 ? '#ff5549' : ($spendVsTime['variance_pct'] < -10 ? '#0369a1' : '#00a399') ?>"><?= $spendVsTime['variance_pct'] >= 0 ? '+' : '' ?><?= $spendVsTime['variance_pct'] ?>%</div>
                        <div class="target-combined-pct-label"><?= $spendVsTime['is_current_year'] ? ($spendVsTime['variance_pct'] > 10 ? 'Ahead (overspend risk)' : ($spendVsTime['variance_pct'] < -10 ? 'Behind (under-utilising)' : 'On track')) : ($spendVsTime['variance_pct'] > 0 ? 'Overspent' : ($spendVsTime['variance_pct'] < 0 ? 'Under-utilised' : 'On budget')) ?></div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-6">
                <?php if ($selected_region === 'all' && !empty($regionSplit)): ?>
                <div class="es-section">
                    <div class="es-section-title">Region Split</div>
                    <div class="es-chart-wrap">
                        <div class="es-chart-donut"><canvas id="regionChart"></canvas></div>
                        <div class="es-chart-legend" style="flex: 1; min-width: 140px;">
                            <?php $tot = array_sum(array_column($regionSplit, 'total')); foreach ($regionSplit as $i => $r): ?>
                            <div class="d-flex justify-content-between align-items-center small mb-1">
                                <span><span class="d-inline-block rounded" style="width:8px;height:8px;background:<?= $chartColors[$i % count($chartColors)] ?>"></span> <?= htmlspecialchars($r['region']) ?></span>
                                <span><?= formatCurrency($r['total'], $currency) ?><?= $tot > 0 ? ' (' . round(100 * $r['total'] / $tot, 0) . '%)' : '' ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="es-section">
                    <div class="es-section-title">Spend by Status</div>
                    <div class="es-chart-wrap">
                        <div class="es-chart-donut"><canvas id="statusChart"></canvas></div>
                        <div class="es-chart-legend" style="flex: 1; min-width: 140px;">
                            <?php $tot = array_sum(array_column($status_distribution, 'total')); foreach ($status_distribution as $i => $r): ?>
                            <div class="d-flex justify-content-between align-items-center small mb-1">
                                <span><span class="d-inline-block rounded" style="width:8px;height:8px;background:<?= $chartColors[$i % count($chartColors)] ?>"></span> <?= htmlspecialchars($r['status']) ?></span>
                                <span><?= formatCurrency($r['total'], $currency) ?><?= $tot > 0 ? ' (' . round(100 * $r['total'] / $tot, 0) . '%)' : '' ?></span>
                            </div>
                            <?php endforeach; ?>
                            <?php if (empty($status_distribution)): ?><div class="text-muted">No data</div><?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <div class="col-md-6">
                <div class="es-section">
                    <div class="es-section-title">Top 5 Vendors</div>
                    <table class="table table-sm table-bordered">
                        <thead class="table-light"><tr><th>Vendor</th><th class="text-end">Items</th><th class="text-end">Value</th></tr></thead>
                        <tbody>
                            <?php foreach ($top_vendors as $r): ?>
                            <tr><td><?= htmlspecialchars($r['vendor']) ?></td><td class="text-end"><?= $r['item_count'] ?></td><td class="text-end"><?= formatCurrency($r['total_spent'], $currency) ?></td></tr>
                            <?php endforeach; ?>
                            <?php if (empty($top_vendors)): ?><tr><td colspan="3" class="text-muted">No vendor data</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if ($selected_region === 'all' && !empty($regionSplit)): ?>
        <div class="es-section">
            <div class="es-section-title">Spend by Status</div>
            <table class="table table-sm table-bordered">
                <thead class="table-light"><tr><th>Status</th><th class="text-end">Count</th><th class="text-end">Value</th></tr></thead>
                <tbody>
                    <?php foreach ($status_distribution as $r): ?>
                    <tr><td><?= htmlspecialchars($r['status']) ?></td><td class="text-end"><?= $r['count'] ?></td><td class="text-end"><?= formatCurrency($r['total'], $currency) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (empty($status_distribution)): ?><tr><td colspan="3" class="text-muted">No status data</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if (!empty($byMonth) && $selected_year !== 'all'): ?>
        <div class="es-section">
            <div class="es-section-title">Monthly Trend (<?= htmlspecialchars($selected_year) ?>)</div>
            <table class="table table-sm table-bordered">
                <thead class="table-light"><tr><?php foreach ($monthLabels as $m): ?><th class="text-center"><?= $m ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                    <tr>
                        <?php for ($i = 1; $i <= 12; $i++): ?>
                        <td class="text-end"><?= formatCurrency($byMonth[$i] ?? 0, $currency) ?></td>
                        <?php endfor; ?>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <div class="es-section">
            <div class="es-section-title">Channel Partners vs Other</div>
            <table class="table table-sm table-bordered">
                <tr><th>Channel Partners (matched)</th><td class="text-end"><?= formatCurrency($matched_spend, $currency) ?></td><td class="text-end"><?= $matched_count ?> vendors</td></tr>
                <tr><th>Other / Direct</th><td class="text-end"><?= formatCurrency($unmatched_spend, $currency) ?></td><td class="text-end"><?= $unmatched_count ?> vendors</td></tr>
            </table>
        </div>

        <p class="text-muted small mt-3 mb-0"><em>Budget Executive Summary. Data from budget_items. Invoiced = status Invoiced only; total = all statuses (planned + invoiced + etc.). Overspend and “at risk” messages are based on invoiced amount so planned-only overage is not shown as actual overspend.<?= $selected_region === 'all' ? ' Global view: all amounts converted to EUR using conversion rates.' : '' ?></em></p>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var fmt = function(v) { return '<?= addslashes($currency_symbol) ?>' + parseFloat(v).toLocaleString('en-GB', { minimumFractionDigits: 0, maximumFractionDigits: 0 }); };
    var colors = <?= json_encode($chartColors) ?>;

    var regionEl = document.getElementById('regionChart');
    if (regionEl) {
        var regionLabels = <?= $regionLabels ?: '[]' ?>;
        var regionData = <?= $regionData ?: '[]' ?>;
        if (regionLabels.length && regionData.some(function(x) { return x > 0; })) {
            new Chart(regionEl.getContext('2d'), {
                type: 'doughnut',
                data: { labels: regionLabels, datasets: [{ data: regionData, backgroundColor: colors.slice(0, regionLabels.length), borderColor: '#fff', borderWidth: 2 }] },
                options: { responsive: true, maintainAspectRatio: true, cutout: '55%', plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(c) { return c.label + ': ' + fmt(c.raw); } } } } }
            });
        } else regionEl.parentElement.innerHTML = '<div class="text-center text-muted small py-4">No data</div>';
    }

    var statusEl = document.getElementById('statusChart');
    if (statusEl) {
        var statusLabels = <?= $statusLabels ?: '[]' ?>;
        var statusData = <?= $statusData ?: '[]' ?>;
        if (statusLabels.length && statusData.some(function(x) { return x > 0; })) {
            new Chart(statusEl.getContext('2d'), {
                type: 'doughnut',
                data: { labels: statusLabels, datasets: [{ data: statusData, backgroundColor: colors.slice(0, statusLabels.length), borderColor: '#fff', borderWidth: 2 }] },
                options: { responsive: true, maintainAspectRatio: true, cutout: '55%', plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(c) { return c.label + ': ' + fmt(c.raw); } } } } }
            });
        } else statusEl.parentElement.innerHTML = '<div class="text-center text-muted small py-4">No data</div>';
    }
});
</script>
</div></div>
</body>
</html>
