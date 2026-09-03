<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$pdo = getDBConnection();
$selected_region = $_GET['region'] ?? 'AMER';

// Get basic data
$regional_spent = getRegionalSpent($pdo, $selected_region);
$budget_limit = getRegionalBudgetLimit($selected_region);
$currency = $REGIONAL_SETTINGS[$selected_region]['currency'];
$currency_symbol = $CURRENCY_SYMBOLS[$currency];

// Get regional items
$regional_items = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM budget_items WHERE region = ? ORDER BY entry_creation_date DESC");
    if ($stmt->execute([$selected_region])) {
        $regional_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log("Error getting regional items: " . $e->getMessage());
}

// Calculate metrics
$remaining_budget = $budget_limit - $regional_spent;
$usage_percentage = $budget_limit > 0 ? min(100, ($regional_spent / $budget_limit) * 100) : 0;
$avg_item_amount = count($regional_items) > 0 ? $regional_spent / count($regional_items) : 0;

// Get status distribution for chart
$status_distribution = [];
try {
    $stmt = $pdo->prepare("
        SELECT status, COUNT(*) as count, SUM(amount_requested) as total 
        FROM budget_items 
        WHERE region = ? 
        GROUP BY status
    ");
    if ($stmt->execute([$selected_region])) {
        $status_distribution = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log("Error getting status distribution: " . $e->getMessage());
}

$current_year = date('Y');
$current_month = date('F Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($selected_region) ?> Regional Report</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .glass-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin-bottom: 20px;
        }
        
        .glass-card-header {
            background: rgba(52, 152, 219, 0.8);
            color: white;
            padding: 15px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .report-header {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }
        
        .metric-card {
            transition: transform 0.3s ease;
            height: 100%;
        }
        
        .metric-card:hover {
            transform: translateY(-5px);
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        
        .kpi-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0;
        }
        
        .kpi-label {
            font-size: 0.9rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .region-selector {
            background: rgba(255, 255, 255, 0.9);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .navbar {
            background: rgba(44, 62, 80, 0.95) !important;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <i class="fas fa-chart-line"></i> Budget System Reports
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="index.php"><i class="fas fa-chart-pie"></i> Global Dashboard</a>
                <a class="nav-link" href="regional.php?region=<?= $selected_region ?>"><i class="fas fa-map-marked-alt"></i> Regional View</a>
                <a class="nav-link active" href="#"><i class="fas fa-chart-bar"></i> Reports</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <!-- Header -->
        <div class="report-header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="display-5 fw-bold">
                        <span style="background: <?= getRegionalColor($selected_region) ?>; width: 30px; height: 20px; display: inline-block; margin-right: 10px; border-radius: 3px;"></span>
                        <?= htmlspecialchars($selected_region) ?> Regional Report
                    </h1>
                    <p class="lead mb-0">Comprehensive budget analysis | <?= $current_month ?></p>
                </div>
                <div class="col-md-4 text-end">
                    <button class="btn btn-outline-primary" onclick="window.print()">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                </div>
            </div>
        </div>

        <!-- Region Selector -->
        <div class="region-selector">
            <div class="row g-3 align-items-center">
                <div class="col-md-6">
                    <label class="form-label fw-bold">Select Region:</label>
                    <form method="GET" class="d-flex">
                        <select name="region" class="form-select me-2" onchange="this.form.submit()">
                            <?php foreach ($REGIONAL_SETTINGS as $region => $settings): ?>
                            <option value="<?= $region ?>" <?= $selected_region == $region ? 'selected' : '' ?>>
                                <?= $region ?> (<?= $settings['currency'] ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-primary">Go</button>
                    </form>
                </div>
                <div class="col-md-6 text-end">
                    <small class="text-muted">Generated: <?= date('M j, Y g:i A') ?></small>
                </div>
            </div>
        </div>

        <!-- Key Metrics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="glass-card metric-card text-center p-3">
                    <div class="kpi-number text-primary"><?= formatCurrency($budget_limit, $currency) ?></div>
                    <div class="kpi-label">Total Budget</div>
                    <div class="mt-2">
                        <span class="badge bg-primary">Annual</span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="glass-card metric-card text-center p-3">
                    <div class="kpi-number <?= $usage_percentage > 75 ? 'text-warning' : 'text-success' ?>"><?= formatCurrency($regional_spent, $currency) ?></div>
                    <div class="kpi-label">Amount Spent</div>
                    <div class="mt-2">
                        <span class="badge <?= $usage_percentage > 75 ? 'bg-warning' : 'bg-success' ?>"><?= number_format($usage_percentage, 1) ?>%</span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="glass-card metric-card text-center p-3">
                    <div class="kpi-number <?= $remaining_budget < ($budget_limit * 0.1) ? 'text-danger' : 'text-success' ?>"><?= formatCurrency($remaining_budget, $currency) ?></div>
                    <div class="kpi-label">Remaining</div>
                    <div class="mt-2">
                        <span class="badge <?= $remaining_budget < ($budget_limit * 0.1) ? 'bg-danger' : 'bg-success' ?>">
                            <?= number_format(100 - $usage_percentage, 1) ?>%
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="glass-card metric-card text-center p-3">
                    <div class="kpi-number text-info"><?= count($regional_items) ?></div>
                    <div class="kpi-label">Total Items</div>
                    <div class="mt-2">
                        <span class="badge bg-info">Avg: <?= formatCurrency($avg_item_amount, $currency) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="glass-card">
                    <div class="glass-card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Budget Utilization</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="budgetPieChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="glass-card">
                    <div class="glass-card-header">
                        <h5 class="mb-0"><i class="fas fa-tasks"></i> Status Distribution</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="statusChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Items -->
        <div class="glass-card">
            <div class="glass-card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-list"></i> Recent Budget Items</h5>
                <span class="badge bg-light text-dark"><?= count($regional_items) ?> items</span>
            </div>
            <div class="card-body">
                <?php if (count($regional_items) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>PO Number</th>
                                <th>Activity</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Vendor</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($regional_items as $item): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($item['po_number'] ?? 'N/A') ?></strong></td>
                                <td><?= htmlspecialchars($item['activity_title'] ?? 'N/A') ?></td>
                                <td><?= formatCurrency($item['amount_requested'] ?? 0, $currency) ?></td>
                                <td><?= getStatusBadge($item['status'] ?? 'Unknown') ?></td>
                                <td><?= htmlspecialchars($item['vendor'] ?? 'N/A') ?></td>
                                <td><?= !empty($item['start_date']) ? date('M j, Y', strtotime($item['start_date'])) : 'N/A' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-center py-4">
                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                    <h5>No budget items found</h5>
                    <p class="text-muted">Budget items will appear here once added</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Budget Utilization Pie Chart
        document.addEventListener('DOMContentLoaded', function() {
            const budgetCtx = document.getElementById('budgetPieChart').getContext('2d');
            const budgetPieChart = new Chart(budgetCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Spent', 'Remaining'],
                    datasets: [{
                        data: [<?= $regional_spent ?>, <?= max(0, $remaining_budget) ?>],
                        backgroundColor: [
                            '<?= $usage_percentage > 75 ? '#e74c3c' : '#27ae60' ?>',
                            '#3498db'
                        ],
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw;
                                    const total = <?= $budget_limit ?>;
                                    const percentage = ((value / total) * 100).toFixed(1);
                                    return `${label}: ${formatCurrency(value)} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });

            // Status Distribution Chart
            const statusCtx = document.getElementById('statusChart').getContext('2d');
            const statusChart = new Chart(statusCtx, {
                type: 'pie',
                data: {
                    labels: [<?= implode(',', array_map(function($item) { return "'" . ($item['status'] ?? 'Unknown') . "'"; }, $status_distribution)) ?>],
                    datasets: [{
                        data: [<?= implode(',', array_column($status_distribution, 'count')) ?>],
                        backgroundColor: [
                            '#3498db', '#27ae60', '#f39c12', '#e74c3c', '#9b59b6', '#34495e'
                        ],
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });

            // Add hover effects to metric cards
            const metricCards = document.querySelectorAll('.metric-card');
            metricCards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-5px)';
                });
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });
        });

        function formatCurrency(value) {
            return '<?= $currency_symbol ?>' + value.toLocaleString('en-US', { 
                minimumFractionDigits: 2, 
                maximumFractionDigits: 2 
            });
        }
    </script>
</body>
</html>