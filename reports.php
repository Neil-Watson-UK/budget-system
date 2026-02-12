<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'config.php';
require_once 'functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode('reports.php'));
    exit;
}

$pdo = getDBConnection();

// Default filters
$selected_region = $_GET['region'] ?? 'all';
$selected_country = $_GET['country'] ?? '';
$selected_vendor = $_GET['vendor'] ?? '';
$selected_external_vendor = $_GET['external_vendor'] ?? '';
$selected_account = $_GET['account'] ?? '';
$selected_sub_account = $_GET['sub_account'] ?? '';
$selected_staff = $_GET['staff'] ?? '';
$selected_status = $_GET['status'] ?? '';
$selected_year = $_GET['year'] ?? date('Y');

// Build WHERE clause for filters
$whereConditions = [];
$params = [];

if ($selected_region && $selected_region !== 'all') {
    $whereConditions[] = 'region = ?';
    $params[] = $selected_region;
}

if (!empty($selected_country)) {
    $whereConditions[] = 'country = ?';
    $params[] = $selected_country;
}

if (!empty($selected_vendor)) {
    $whereConditions[] = 'vendor = ?';
    $params[] = $selected_vendor;
}

if (!empty($selected_external_vendor)) {
    $whereConditions[] = 'external_vendor = ?';
    $params[] = $selected_external_vendor;
}

if (!empty($selected_account)) {
    $whereConditions[] = 'account = ?';
    $params[] = $selected_account;
}

if (!empty($selected_sub_account)) {
    $whereConditions[] = 'sub_account = ?';
    $params[] = $selected_sub_account;
}

if (!empty($selected_staff)) {
    $whereConditions[] = 'associated_epos_staff = ?';
    $params[] = $selected_staff;
}

if (!empty($selected_status)) {
    $whereConditions[] = 'status = ?';
    $params[] = $selected_status;
}

// Year filter
if ($selected_year && $selected_year !== 'all') {
    $whereConditions[] = "(YEAR(start_date) = ? OR YEAR(end_date) = ? OR (YEAR(start_date) IS NULL AND YEAR(entry_creation_date) = ?))";
    $params[] = $selected_year;
    $params[] = $selected_year;
    $params[] = $selected_year;
}

$whereClause = empty($whereConditions) ? '1=1' : implode(' AND ', $whereConditions);

// Get basic metrics
$regional_spent = getRegionalSpentWithFilters($pdo, $whereClause, $params);

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

$remaining_budget = $budget_limit - $regional_spent;
$usage_percentage = $budget_limit > 0 ? min(100, ($regional_spent / $budget_limit) * 100) : 0;

// Get filter options
if ($selected_region !== 'all') {
    $countries = getFilterOptions($pdo, 'country', $selected_region);
    $vendors = getFilterOptions($pdo, 'vendor', $selected_region);
    $external_vendors = getFilterOptions($pdo, 'external_vendor', $selected_region);
    $accounts = getFilterOptions($pdo, 'account', $selected_region);
    $sub_accounts = getFilterOptions($pdo, 'sub_account', $selected_region);
    $staff_members = getFilterOptions($pdo, 'associated_epos_staff', $selected_region);
    $statuses = getFilterOptions($pdo, 'status', $selected_region);
} else {
    $countries = getFilterOptionsAllRegions($pdo, 'country');
    $vendors = getFilterOptionsAllRegions($pdo, 'vendor');
    $external_vendors = getFilterOptionsAllRegions($pdo, 'external_vendor');
    $accounts = getFilterOptionsAllRegions($pdo, 'account');
    $sub_accounts = getFilterOptionsAllRegions($pdo, 'sub_account');
    $staff_members = getFilterOptionsAllRegions($pdo, 'associated_epos_staff');
    $statuses = getFilterOptionsAllRegions($pdo, 'status');
}

$vendor_stats = getVendorMatchStats($pdo, $whereClause, $params);
$matched_count   = $vendor_stats['matched_count'] ?? 0;
$unmatched_count = $vendor_stats['unmatched_count'] ?? 0;
$matched_spend   = $vendor_stats['matched_spend'] ?? 0;
$unmatched_spend = $vendor_stats['unmatched_spend'] ?? 0;
$total_budget    = $vendor_stats['total_budget'] ?? 0;

$avg_partner_spend = $matched_count > 0 ? $matched_spend / $matched_count : 0;
$avg_account_spend = $unmatched_count > 0 ? $unmatched_spend / $unmatched_count : 0;
$partner_pct = $total_budget > 0 ? ($matched_spend / $total_budget) * 100 : 0;
$account_pct = $total_budget > 0 ? ($unmatched_spend / $total_budget) * 100 : 0;

if ($selected_region !== 'all') {
    $spend_by_account_type = getSpendByAccountType($pdo, $selected_region, $whereClause, $params);
    $spend_by_amplify_level = getSpendByAmplifyLevel($pdo, $selected_region, $whereClause, $params);
    $top_spending_vendors = getTopSpendingVendors($pdo, $selected_region, 10, $whereClause, $params);
    $vendor_activity_timeline = getVendorActivityTimeline($pdo, $selected_region, $whereClause, $params);
} else {
    $spend_by_account_type = getSpendByAccountType($pdo, 'all', $whereClause, $params);
    $spend_by_amplify_level = getSpendByAmplifyLevel($pdo, 'all', $whereClause, $params);
    $top_spending_vendors = getTopSpendingVendors($pdo, 'all', 10, $whereClause, $params);
    $vendor_activity_timeline = getVendorActivityTimeline($pdo, 'all', $whereClause, $params);
}

$account_type_labels = json_encode(array_column($spend_by_account_type, 'account_type'));
$account_type_data = json_encode(array_column($spend_by_account_type, 'total_spent'));
$amplify_level_labels = json_encode(array_column($spend_by_amplify_level, 'amplify_level'));
$amplify_level_data = json_encode(array_column($spend_by_amplify_level, 'total_spent'));

$timeline_months = [];
$timeline_spend_by_account = [];
$account_types = array_unique(array_column($vendor_activity_timeline, 'account_type'));
foreach ($account_types as $account_type) {
    $timeline_spend_by_account[$account_type] = [];
}
foreach ($vendor_activity_timeline as $record) {
    $month = $record['month'];
    if (!in_array($month, $timeline_months)) $timeline_months[] = $month;
    $account_type = $record['account_type'];
    $timeline_spend_by_account[$account_type][$month] = (float)$record['monthly_spend'];
}
foreach ($timeline_spend_by_account as &$data) {
    foreach ($timeline_months as $month) {
        if (!isset($data[$month])) $data[$month] = 0;
    }
    ksort($data);
}
$timeline_labels = json_encode($timeline_months);
$timeline_datasets = [];
foreach ($timeline_spend_by_account as $account_type => $monthly_data) {
    $timeline_datasets[] = ['label' => $account_type, 'data' => array_values($monthly_data)];
}
$timeline_datasets_json = json_encode($timeline_datasets);

$current_year_data = getMonthlyTrendData($pdo, $selected_year, $whereClause, $params);
$previous_year_data = getMonthlyTrendData($pdo, $selected_year - 1, $whereClause, $params);
$quarterly_data = getQuarterlyData($pdo, $selected_year, $whereClause, $params);
$top_vendors = getTopVendors($pdo, $selected_region, $whereClause, $params);
$recent_items = getRecentItems($pdo, $whereClause, $params);
$status_distribution = getStatusDistributionData($pdo, $selected_year, $whereClause, $params);
$matched_data = getMatchedUnmatchedData($pdo, $selected_year, $whereClause, $params);

$monthly_labels = json_encode(['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']);
$current_year_monthly_data = json_encode(array_values($current_year_data));
$previous_year_monthly_data = json_encode(array_values($previous_year_data));
$quarterly_labels = json_encode(['Q1', 'Q2', 'Q3', 'Q4']);
$quarterly_data_js = json_encode(array_values($quarterly_data));

$status_labels = json_encode(array_column($status_distribution, 'status'));
$status_data = json_encode(array_column($status_distribution, 'total'));
$status_counts = json_encode(array_column($status_distribution, 'count'));
$matched_labels = json_encode(array_column($matched_data, 'category'));
$matched_values = json_encode(array_column($matched_data, 'total'));
$matched_counts = json_encode(array_column($matched_data, 'count'));

$statusColors = [
    'Planned' => '#3498db',
    'Invoiced' => '#f39c12',
    'Executed' => '#2ecc71',
    'Cancelled' => '#e74c3c',
    'Allocated' => '#9b59b6'
];
$status_colors_json = json_encode(array_map(function($s) use ($statusColors) {
    return $statusColors[$s] ?? '#95a5a6';
}, array_column($status_distribution, 'status')));
$matched_colors_json = json_encode(['#00a399', '#00353d']);
$account_type_colors = ['#00a399', '#3498db', '#9b59b6', '#e74c3c', '#f39c12', '#2ecc71', '#34495e'];
$account_type_colors_json = json_encode($account_type_colors);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Budget Analytics Dashboard - <?= defined('APP_NAME') ? APP_NAME : 'Budget System' ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.40.0/tabler-icons.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <style>
        :root { --tblr-primary: #00a399; --tblr-primary-rgb: 0, 163, 153; --tblr-secondary: #00353d; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; min-height: 100vh; background: #f5f7fb; }
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
        .speedometer-container { height: 250px; width: 100%; }
        .table-card { border-radius: 12px; border: 1px solid #e1e5eb; box-shadow: 0 2px 8px rgba(0,0,0,0.05); background: white; }
        .table-card .card-header { background: #f8f9fa; border-bottom: 1px solid #e1e5eb; padding: 1rem 1.25rem; font-weight: 600; color: #2a3547; }
        .status-badge { border-radius: 20px; padding: 4px 12px; font-weight: 500; font-size: 0.75rem; }
        .metric-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; font-size: 1.5rem; }
        .trend-indicator { font-size: 0.875rem; margin-top: 0.5rem; }
        .btn-filter-reset { border: 1px dashed #dee2e6; color: #6c757d; }
        .btn-filter-reset:hover { border-color: #00a399; color: #00a399; }
    </style>
</head>
<body>
<?php require_once 'header.php'; ?>

<div class="container-fluid py-4">
    <div class="dashboard-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <div class="d-flex align-items-center mb-2">
                        <?php if ($selected_region === 'all'): ?>
                            <i class="ti ti-world me-2" style="font-size: 1.5rem; color: white;"></i>
                        <?php else:
                            $regionFlag = getCountryFlagClass($selected_country ?: $selected_region);
                            if (strpos($regionFlag, 'ti ti-') === 0): ?>
                                <i class="<?= $regionFlag ?> me-2" style="font-size: 1.5rem;"></i>
                            <?php else: ?>
                                <span class="<?= $regionFlag ?> me-2"></span>
                            <?php endif; ?>
                        <?php endif; ?>
                        <h1 class="h2 mb-0">Budget Analytics Dashboard</h1>
                    </div>
                    <p class="mb-0 opacity-75">
                        <i class="ti ti-calendar me-1"></i> <?= date('F Y') ?>
                        <span class="mx-2">•</span>
                        <i class="ti ti-currency-euro me-1"></i> <?= $currency ?>
                        <span class="mx-2">•</span>
                        <i class="ti ti-filter me-1"></i> <?= count($whereConditions) ?> filter<?= count($whereConditions) != 1 ? 's' : '' ?> applied
                    </p>
                </div>
                <div class="col-md-4 text-md-end">
                    <button class="btn btn-outline-light" onclick="window.print()"><i class="ti ti-printer me-1"></i> Export</button>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="filter-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div><i class="ti ti-filter me-2"></i><span>Filters</span></div>
                <button type="button" class="btn btn-sm btn-filter-reset" onclick="resetFilters()"><i class="ti ti-refresh me-1"></i> Clear All</button>
            </div>
            <div class="card-body">
                <form method="GET" id="filterForm" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Region</label>
                        <select name="region" class="form-select" onchange="this.form.submit()">
                            <option value="all" <?= $selected_region == 'all' ? 'selected' : '' ?>>All Regions</option>
                            <?php foreach ($REGIONAL_SETTINGS as $region => $settings): ?>
                            <option value="<?= $region ?>" <?= $selected_region == $region ? 'selected' : '' ?>><?= $region ?> (<?= $settings['currency'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Country</label>
                        <select name="country" class="form-select">
                            <option value="">All Countries</option>
                            <?php foreach ($countries as $c): ?>
                            <option value="<?= htmlspecialchars($c) ?>" <?= $selected_country == $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Year</label>
                        <select name="year" class="form-select">
                            <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                            <option value="<?= $y ?>" <?= $selected_year == $y ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All Statuses</option>
                            <?php foreach ($statuses as $s): ?>
                            <option value="<?= htmlspecialchars($s) ?>" <?= $selected_status == $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Vendor</label>
                        <select name="vendor" class="form-select">
                            <option value="">All Vendors</option>
                            <?php foreach ($vendors as $v): ?>
                            <option value="<?= htmlspecialchars($v) ?>" <?= $selected_vendor == $v ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">EPOS Staff</label>
                        <select name="staff" class="form-select">
                            <option value="">All Staff</option>
                            <?php foreach ($staff_members as $s): ?>
                            <option value="<?= htmlspecialchars($s) ?>" <?= $selected_staff == $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Account</label>
                        <select name="account" class="form-select">
                            <option value="">All Accounts</option>
                            <?php foreach ($accounts as $a): ?>
                            <option value="<?= htmlspecialchars($a) ?>" <?= $selected_account == $a ? 'selected' : '' ?>><?= htmlspecialchars($a) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Sub Account</label>
                        <select name="sub_account" class="form-select">
                            <option value="">All Sub</option>
                            <?php foreach ($sub_accounts as $sa): ?>
                            <option value="<?= htmlspecialchars($sa) ?>" <?= $selected_sub_account == $sa ? 'selected' : '' ?>><?= htmlspecialchars($sa) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <div class="d-flex gap-2 justify-content-end">
                            <button type="reset" class="btn btn-outline-secondary" onclick="resetFilters()"><i class="ti ti-x me-1"></i> Reset</button>
                            <button type="submit" class="btn btn-primary"><i class="ti ti-filter me-1"></i> Apply Filters</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-3">
                <div class="kpi-card">
                    <div class="metric-icon" style="background: rgba(52, 73, 94, 0.1); color: #34495e;"><i class="ti ti-list"></i></div>
                    <div class="kpi-value text-info"><?= getFilteredItemsCount($pdo, $whereClause, $params) ?></div>
                    <div class="kpi-label">Filtered Items</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="kpi-card">
                    <div class="metric-icon" style="background: rgba(39, 174, 96, 0.1); color: #27ae60;"><i class="ti ti-cash"></i></div>
                    <div class="kpi-value <?= $usage_percentage > 75 ? 'text-warning' : 'text-success' ?>"><?= formatCurrency($regional_spent, $currency) ?></div>
                    <div class="kpi-label">Amount Spent</div>
                    <span class="badge <?= $usage_percentage > 75 ? 'bg-warning' : 'bg-success' ?>"><?= number_format($usage_percentage, 1) ?>% Utilized</span>
                </div>
            </div>
            <div class="col-md-3">
                <div class="kpi-card">
                    <div class="metric-icon" style="background: rgba(155, 89, 182, 0.1); color: #9b59b6;"><i class="ti ti-pig-money"></i></div>
                    <div class="kpi-value <?= $remaining_budget < ($budget_limit * 0.1) ? 'text-danger' : 'text-success' ?>"><?= formatCurrency($remaining_budget, $currency) ?></div>
                    <div class="kpi-label">Remaining Budget</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="kpi-card">
                    <div class="metric-icon" style="background: rgba(52, 73, 94, 0.1); color: #34495e;"><i class="ti ti-users"></i></div>
                    <div class="kpi-value text-info"><?= $matched_count + $unmatched_count ?></div>
                    <div class="kpi-label">Unique Vendors</div>
                    <span class="badge bg-success"><?= $matched_count ?> matched</span>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-8">
                <div class="chart-card">
                    <div class="card-header"><i class="ti ti-chart-line me-2"></i>Monthly Spend Trend (<?= $selected_year ?> vs <?= $selected_year - 1 ?>)</div>
                    <div class="card-body">
                        <div class="chart-container"><canvas id="monthlyTrendChart"></canvas></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="chart-card">
                    <div class="card-header"><i class="ti ti-gauge me-2"></i>Budget Utilization Gauge</div>
                    <div class="card-body">
                        <div class="speedometer-container"><div id="speedometerChart"></div></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-6">
                <div class="chart-card">
                    <div class="card-header"><i class="ti ti-chart-bar me-2"></i>Quarterly Spend (<?= $selected_year ?>)</div>
                    <div class="card-body">
                        <div class="chart-container"><canvas id="quarterlyChart"></canvas></div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="chart-card">
                    <div class="card-header"><i class="ti ti-chart-donut me-2"></i>Spend by Status</div>
                    <div class="card-body">
                        <div class="chart-container"><canvas id="statusDonutChart"></canvas></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-6">
                <div class="chart-card">
                    <div class="card-header"><i class="ti ti-chart-pie me-2"></i>Channel vs Other Spend</div>
                    <div class="card-body">
                        <div class="chart-container"><canvas id="matchedDonutChart"></canvas></div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="table-card">
                    <div class="card-header"><i class="ti ti-building-store me-2"></i>Top Vendors by Spend</div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover table-striped table-bordered">
                                <thead class="table-light"><tr><th>Vendor</th><th class="text-end">Items</th><th class="text-end">Total Spend</th><th class="text-end">%</th></tr></thead>
                                <tbody>
                                    <?php
                                    $total_vendor_spend = array_sum(array_column($top_vendors, 'total_spent'));
                                    foreach ($top_vendors as $v):
                                        $pct = $total_vendor_spend > 0 ? ($v['total_spent'] / $total_vendor_spend) * 100 : 0;
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($v['vendor']) ?></td>
                                        <td class="text-end"><?= $v['item_count'] ?></td>
                                        <td class="text-end fw-bold"><?= formatCurrency($v['total_spent'], $currency) ?></td>
                                        <td class="text-end"><?= number_format($pct, 1) ?>%</td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-3 mb-4">
                <div class="kpi-card border-start border-primary border-4 p-3">
                    <h6 class="text-muted text-uppercase small">Funded Channel Partners</h6>
                    <div class="h3 mb-1"><?= number_format($matched_count) ?> <small class="text-muted" style="font-size: 0.6em;">Vendors</small></div>
                    <div class="small">Total: <?= formatCurrency($matched_spend, $currency) ?></div>
                    <div class="progress mt-2" style="height: 10px;"><div class="progress-bar bg-primary" style="width: <?= $partner_pct ?>%"></div></div>
                    <div class="text-end small mt-1"><?= round($partner_pct, 1) ?>% of Budget</div>
                </div>
            </div>
            <div class="col-md-3 mb-4">
                <div class="kpi-card border-start border-info border-4 p-3">
                    <h6 class="text-muted text-uppercase small">Funded Accounts (Direct/Other)</h6>
                    <div class="h3 mb-1"><?= number_format($unmatched_count) ?> <small class="text-muted" style="font-size: 0.6em;">Accounts</small></div>
                    <div class="small">Total: <?= formatCurrency($unmatched_spend, $currency) ?></div>
                    <div class="progress mt-2" style="height: 10px;"><div class="progress-bar bg-info" style="width: <?= $account_pct ?>%"></div></div>
                    <div class="text-end small mt-1"><?= round($account_pct, 1) ?>% of Budget</div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-6">
                <div class="chart-card">
                    <div class="card-header"><i class="ti ti-chart-pie me-2"></i>Spend by Account Type</div>
                    <div class="card-body">
                        <div class="chart-container"><canvas id="accountTypeChart"></canvas></div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="chart-card">
                    <div class="card-header"><i class="ti ti-chart-bar me-2"></i>Spend by AMPLIFY Level</div>
                    <div class="card-body">
                        <div class="chart-container"><canvas id="amplifyLevelChart"></canvas></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-12">
                <div class="chart-card">
                    <div class="card-header"><i class="ti ti-timeline me-2"></i>Vendor Activity Timeline by Account Type</div>
                    <div class="card-body">
                        <div class="chart-container" style="height: 350px;"><canvas id="vendorTimelineChart"></canvas></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="table-card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div><i class="ti ti-list-details me-2"></i>Recent Budget Items</div>
                <span class="badge bg-primary"><?= count($recent_items) ?> items</span>
            </div>
            <div class="card-body">
                <?php if (count($recent_items) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover table-striped table-bordered">
                        <thead class="table-light"><tr><th>PO Number</th><th>Activity</th><th class="text-end">Amount</th><th>Status</th><th>Vendor</th><th>Date</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($recent_items as $item): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($item['po_number'] ?? 'N/A') ?></strong></td>
                                <td><?= htmlspecialchars($item['activity_title'] ?? 'N/A') ?></td>
                                <td class="text-end fw-bold"><?= formatCurrency($item['amount_requested'] ?? 0, $currency) ?></td>
                                <td>
                                    <span class="status-badge" style="background: <?= getStatusColorHex($item['status'] ?? '') ?>20; color: <?= getStatusColorHex($item['status'] ?? '') ?>; border: 1px solid <?= getStatusColorHex($item['status'] ?? '') ?>40;">
                                        <?= htmlspecialchars($item['status'] ?? 'Unknown') ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($item['vendor'] ?? 'N/A') ?></td>
                                <td><?= !empty($item['start_date']) ? date('M j, Y', strtotime($item['start_date'])) : 'N/A' ?></td>
                                <td><a href="add_item.php?id=<?= $item['id'] ?? '' ?>" class="btn btn-sm btn-outline-primary"><i class="ti ti-eye"></i></a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-center py-5">
                    <i class="ti ti-inbox" style="font-size: 3rem; color: #dee2e6;"></i>
                    <h5>No items match your filters</h5>
                    <button type="button" class="btn btn-primary" onclick="resetFilters()"><i class="ti ti-filter-off me-1"></i> Reset Filters</button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/js/tabler.min.js"></script>
<script>
function formatCurrency(v) {
    return '<?= $currency_symbol ?>' + parseFloat(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function resetFilters() { window.location.href = 'reports.php?region=all'; }

document.addEventListener('DOMContentLoaded', function() {
    const tc = { primary: '#00a399', secondary: '#00353d', success: '#28a745', info: '#17a2b8', warning: '#ffc107', danger: '#dc3545' };
    const acColors = JSON.parse('<?= $account_type_colors_json ?>');

    const mc = document.getElementById('monthlyTrendChart');
    if (mc) {
        new Chart(mc.getContext('2d'), {
            type: 'line',
            data: {
                labels: JSON.parse('<?= $monthly_labels ?>'),
                datasets: [
                    { label: '<?= $selected_year ?>', data: JSON.parse('<?= $current_year_monthly_data ?>'), borderColor: tc.primary, backgroundColor: tc.primary + '20', borderWidth: 3, fill: true, tension: 0.4 },
                    { label: '<?= $selected_year - 1 ?>', data: JSON.parse('<?= $previous_year_monthly_data ?>'), borderColor: tc.secondary, backgroundColor: tc.secondary + '20', borderWidth: 2, borderDash: [5,5], fill: true, tension: 0.4 }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top' }, tooltip: { callbacks: { label: c => c.dataset.label + ': ' + formatCurrency(c.raw) } } }, scales: { y: { beginAtZero: true, ticks: { callback: v => formatCurrency(v) } } } }
        });
    }

    const qc = document.getElementById('quarterlyChart');
    if (qc) {
        new Chart(qc.getContext('2d'), {
            type: 'bar',
            data: {
                labels: JSON.parse('<?= $quarterly_labels ?>'),
                datasets: [{ label: 'Spend', data: JSON.parse('<?= $quarterly_data_js ?>'), backgroundColor: [tc.primary+'80', tc.success+'80', tc.warning+'80', tc.danger+'80'], borderColor: [tc.primary, tc.success, tc.warning, tc.danger], borderWidth: 2 }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => formatCurrency(c.raw) } } }, scales: { y: { beginAtZero: true, ticks: { callback: v => formatCurrency(v) } } } }
        });
    }

    const sp = document.getElementById('speedometerChart');
    if (sp) {
        new ApexCharts(sp, {
            series: [<?= number_format($usage_percentage, 2) ?>],
            chart: { height: 250, type: 'radialBar' },
            plotOptions: { radialBar: { startAngle: -135, endAngle: 135, dataLabels: { name: { fontSize: '16px', color: '#6c757d', offsetY: 120 }, value: { offsetY: 76, fontSize: '32px', formatter: v => v.toFixed(1) + '%' } } } },
            fill: { type: 'gradient', gradient: { shade: 'dark', shadeIntensity: 0.15, inverseColors: false, opacityFrom: 1, opacityTo: 1, stops: [0, 50, 65, 91] } },
            stroke: { dashArray: 4 },
            colors: [<?= $usage_percentage ?> > 75 ? tc.warning : (<?= $usage_percentage ?> > 50 ? tc.success : tc.primary)],
            labels: ['Budget Utilization']
        }).render();
    }

    const sd = document.getElementById('statusDonutChart');
    if (sd) {
        const sl = JSON.parse('<?= $status_labels ?>'), sdata = JSON.parse('<?= $status_data ?>'), sc = JSON.parse('<?= $status_colors_json ?>');
        if (sdata.length && !sdata.every(x=>x===0)) {
            new Chart(sd.getContext('2d'), { type: 'doughnut', data: { labels: sl, datasets: [{ data: sdata, backgroundColor: sc, borderColor: 'white', borderWidth: 2 }] }, options: { responsive: true, maintainAspectRatio: false, cutout: '60%', plugins: { legend: { position: 'right' }, tooltip: { callbacks: { label: c => c.label + ': ' + formatCurrency(c.raw) } } } } });
        } else sd.parentElement.innerHTML = '<div class="text-center py-5 text-muted">No status data</div>';
    }

    const md = document.getElementById('matchedDonutChart');
    if (md) {
        const ml = JSON.parse('<?= $matched_labels ?>'), mdata = JSON.parse('<?= $matched_values ?>'), mc = JSON.parse('<?= $matched_colors_json ?>');
        if (mdata.length && !mdata.every(x=>x===0)) {
            new Chart(md.getContext('2d'), { type: 'doughnut', data: { labels: ml, datasets: [{ data: mdata, backgroundColor: mc, borderColor: 'white', borderWidth: 2 }] }, options: { responsive: true, maintainAspectRatio: false, cutout: '60%', plugins: { legend: { position: 'right' }, tooltip: { callbacks: { label: c => c.label + ': ' + formatCurrency(c.raw) } } } } });
        } else md.parentElement.innerHTML = '<div class="text-center py-5 text-muted">No matched/unmatched data</div>';
    }

    const at = document.getElementById('accountTypeChart');
    if (at) {
        const al = JSON.parse('<?= $account_type_labels ?>'), ad = JSON.parse('<?= $account_type_data ?>');
        if (ad.length && !ad.every(x=>x===0)) {
            new Chart(at.getContext('2d'), { type: 'doughnut', data: { labels: al, datasets: [{ data: ad, backgroundColor: acColors.slice(0, al.length), borderColor: 'white', borderWidth: 2 }] }, options: { responsive: true, maintainAspectRatio: false, cutout: '60%', plugins: { legend: { position: 'right' }, tooltip: { callbacks: { label: c => c.label + ': ' + formatCurrency(c.raw) } } } } });
        } else at.parentElement.innerHTML = '<div class="text-center py-5 text-muted">No account type data</div>';
    }

    const amp = document.getElementById('amplifyLevelChart');
    if (amp) {
        const al = JSON.parse('<?= $amplify_level_labels ?>'), ad = JSON.parse('<?= $amplify_level_data ?>');
        if (ad.length && !ad.every(x=>x===0)) {
            new Chart(amp.getContext('2d'), { type: 'bar', data: { labels: al, datasets: [{ label: 'Spend', data: ad, backgroundColor: acColors.slice(0, al.length) }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => formatCurrency(c.raw) } } }, scales: { y: { beginAtZero: true, ticks: { callback: v => formatCurrency(v) } } } } });
        } else amp.parentElement.innerHTML = '<div class="text-center py-5 text-muted">No AMPLIFY level data</div>';
    }

    const vt = document.getElementById('vendorTimelineChart');
    if (vt) {
        const tl = JSON.parse('<?= $timeline_labels ?>'), td = JSON.parse('<?= $timeline_datasets_json ?>');
        td.forEach((ds, i) => { ds.borderColor = acColors[i % acColors.length]; ds.backgroundColor = acColors[i % acColors.length] + '20'; ds.borderWidth = 2; ds.fill = true; ds.tension = 0.4; });
        if (td.length) {
            new Chart(vt.getContext('2d'), { type: 'line', data: { labels: tl, datasets: td }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top' }, tooltip: { callbacks: { label: c => c.dataset.label + ': ' + formatCurrency(c.raw) } } }, scales: { y: { beginAtZero: true, ticks: { callback: v => formatCurrency(v) } } } } });
        } else vt.parentElement.innerHTML = '<div class="text-center py-5 text-muted">No timeline data</div>';
    }
});
</script>
</body>
</html>
