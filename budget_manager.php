<?php
// budget_manager.php - Manage yearly budgets for regions
session_start();
require_once 'config.php';
require_once 'functions.php';

// Check if user is admin
if ($_SESSION['role'] !== 'admin') {
    header("Location: index.php?error=Access denied");
    exit;
}

$pdo = getDBConnection();
$message = '';
$error = '';

// Handle form submissions
// Handle form submissions
// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle individual budget update (from single save buttons)
    if (isset($_POST['update_budget'])) {
        $region = $_POST['region'] ?? '';
        $year = $_POST['year'] ?? '';
        $amount = floatval($_POST['amount'] ?? 0);
        $currency = $_POST['currency'] ?? 'EUR';
        
        if (empty($region)) {
            $error = "Region cannot be empty!";
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO region_budgets (region, year, budget_amount, currency) 
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    budget_amount = VALUES(budget_amount),
                    currency = VALUES(currency),
                    updated_at = CURRENT_TIMESTAMP
                ");
                $stmt->execute([$region, $year, $amount, $currency]);
                $message = "Budget updated for $region in $year";
            } catch (PDOException $e) {
                $error = "Error updating budget: " . $e->getMessage();
            }
        }
    }
    
    // Handle copy_budget
    if (isset($_POST['copy_budget'])) {
        $from_year = $_POST['copy_from_year'];
        $to_year = $_POST['copy_to_year'];
        
        try {
            // Copy all budgets from one year to another
            $stmt = $pdo->prepare("
                INSERT INTO region_budgets (region, year, budget_amount, currency)
                SELECT region, ?, budget_amount, currency
                FROM region_budgets 
                WHERE year = ?
                ON DUPLICATE KEY UPDATE 
                budget_amount = VALUES(budget_amount),
                currency = VALUES(currency),
                updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$to_year, $from_year]);
            $message = "Budgets copied from $from_year to $to_year";
        } catch (PDOException $e) {
            $error = "Error copying budgets: " . $e->getMessage();
        }
    }
    
    // Handle apply_defaults
    if (isset($_POST['apply_defaults'])) {
        $year = $_POST['default_year'];
        $percentage_change = floatval($_POST['percentage_change']) / 100;
        
        try {
            // Get all regions
            global $REGIONAL_SETTINGS;
            
            foreach ($REGIONAL_SETTINGS as $region => $settings) {
                $default_budget = $settings['budget_limit'] ?? 0;
                $adjusted_budget = $default_budget * (1 + $percentage_change);
                $currency = $settings['currency'];
                
                $stmt = $pdo->prepare("
                    INSERT INTO region_budgets (region, year, budget_amount, currency) 
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    budget_amount = VALUES(budget_amount),
                    currency = VALUES(currency),
                    updated_at = CURRENT_TIMESTAMP
                ");
                $stmt->execute([$region, $year, $adjusted_budget, $currency]);
            }
            $message = "Default budgets applied for $year with " . ($percentage_change * 100) . "% adjustment";
        } catch (PDOException $e) {
            $error = "Error applying defaults: " . $e->getMessage();
        }
    }
}

// Get current year and available years
$current_year = date('Y');
$available_years = getAvailableYears($pdo);

// Get all budgets
$budgets = $pdo->query("
    SELECT region, year, budget_amount, currency 
    FROM region_budgets 
    ORDER BY year DESC, region
")->fetchAll(PDO::FETCH_ASSOC);

// Group budgets by year
$budgets_by_year = [];
foreach ($budgets as $budget) {
    $budgets_by_year[$budget['year']][] = $budget;
}

// Calculate totals
$yearly_totals = [];
foreach ($budgets_by_year as $year => $year_budgets) {
    $total = 0;
    foreach ($year_budgets as $budget) {
        $total += $budget['budget_amount'];
    }
    $yearly_totals[$year] = $total;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Budget Manager - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #00a399 0%, #00353d 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
            padding: 20px;
        }
        
        .card-header {
            background: rgba(52, 152, 219, 0.9);
            color: white;
            border-radius: 15px 15px 0 0 !important;
            padding: 20px;
        }
        
        .budget-cell {
            font-family: 'Courier New', monospace;
            font-weight: 600;
        }
        
        .year-total {
            background-color: #f8f9fa;
            font-weight: 700;
            border-top: 2px solid #dee2e6;
        }
        
        .budget-change-positive {
            color: #27ae60;
        }
        
        .budget-change-negative {
            color: #e74c3c;
        }
        
        .region-color-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
        }
    </style>
</head>
<body>
    <?php require_once 'header.php'; ?>
    
    <div class="container mt-5">
        <h1 class="text-dark mb-4">
            <i class="fas fa-wallet"></i> Budget Manager
        </h1>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Quick Actions -->
        <div class="glass-card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-bolt"></i> Quick Actions</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <!-- Copy Budgets from Previous Year -->
                    <div class="col-md-6 mb-3">
                        <div class="card h-100">
                            <div class="card-body">
                                <h6><i class="fas fa-copy"></i> Copy Budgets</h6>
                                <form method="post" class="row g-2">
                                    <div class="col-md-5">
                                        <select name="copy_from_year" class="form-select form-select-sm" required>
                                            <option value="">From Year</option>
                                            <?php foreach ($available_years as $year): ?>
                                                <option value="<?= $year ?>"><?= $year ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-5">
                                        <select name="copy_to_year" class="form-select form-select-sm" required>
                                            <option value="">To Year</option>
                                            <?php for ($y = $current_year; $y <= $current_year + 5; $y++): ?>
                                                <option value="<?= $y ?>"><?= $y ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <button type="submit" name="copy_budget" class="btn btn-primary btn-sm w-100">
                                            <i class="fas fa-copy"></i> Copy
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Apply Default Budgets -->
                    <div class="col-md-6 mb-3">
                        <div class="card h-100">
                            <div class="card-body">
                                <h6><i class="fas fa-magic"></i> Apply Defaults</h6>
                                <form method="post" class="row g-2">
                                    <div class="col-md-5">
                                        <select name="default_year" class="form-select form-select-sm" required>
                                            <option value="">Select Year</option>
                                            <?php for ($y = $current_year; $y <= $current_year + 5; $y++): ?>
                                                <option value="<?= $y ?>"><?= $y ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-5">
                                        <div class="input-group input-group-sm">
                                            <input type="number" name="percentage_change" class="form-control" 
                                                   placeholder="% Change" step="0.1" value="0">
                                            <span class="input-group-text">%</span>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <button type="submit" name="apply_defaults" class="btn btn-success btn-sm w-100">
                                            <i class="fas fa-check"></i> Apply
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Budget Overview -->
        <div class="glass-card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Budget Overview by Year</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Year</th>
                                <th>Total Budget</th>
                                <th>Regions</th>
                                <th>Avg per Region</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($yearly_totals as $year => $total): 
                                $region_count = count($budgets_by_year[$year] ?? []);
                                $average = $region_count > 0 ? $total / $region_count : 0;
                            ?>
                            <tr>
                                <td>
                                    <strong><?= $year ?></strong>
                                    <?php if ($year == $current_year): ?>
                                        <span class="badge bg-info">Current</span>
                                    <?php endif; ?>
                                </td>
                                <td class="budget-cell"><?= formatCurrency($total, 'EUR') ?></td>
                                <td>
                                    <span class="badge bg-secondary"><?= $region_count ?> regions</span>
                                </td>
                                <td class="budget-cell"><?= formatCurrency($average, 'EUR') ?></td>
                                <td>
                                    <a href="#year-<?= $year ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                    <a href="budget_export.php?year=<?= $year ?>" class="btn btn-sm btn-outline-info">
                                        <i class="fas fa-download"></i> Export
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Edit Budgets by Year -->
        <?php 
        // Show years with budgets or next 3 years
        $display_years = array_unique(array_merge(
            array_keys($budgets_by_year),
            [$current_year, $current_year + 1, $current_year + 2]
        ));
        rsort($display_years);
        ?>
        
        <?php foreach ($display_years as $year): ?>
        <div class="glass-card mb-4" id="year-<?= $year ?>">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-calendar-alt"></i> <?= $year ?> Budgets
                    <?php if ($year == $current_year): ?>
                        <span class="badge bg-success">Current Year</span>
                    <?php elseif ($year > $current_year): ?>
                        <span class="badge bg-warning">Future Year</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">Past Year</span>
                    <?php endif; ?>
                </h5>
                <div>
                    <span class="badge bg-light text-dark">
                        Total: <?= formatCurrency($yearly_totals[$year] ?? 0, 'EUR') ?>
                    </span>
                </div>
            </div>
            <div class="card-body">
                <form method="post" id="budget-form-<?= $year ?>">
                    <input type="hidden" name="year" value="<?= $year ?>">
                    
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
    <tr>
        <th>Region</th>
        <th>Currency</th>
        <th>Budget Amount</th>
        <th>Status</th>
        <th>% Change</th>
        <th>Action</th>
    </tr>
</thead>
                            <tbody>
                                <?php 
                                global $REGIONAL_SETTINGS;
                                $year_budgets = $budgets_by_year[$year] ?? [];
                                $budgets_indexed = [];
                                
                                foreach ($year_budgets as $budget) {
                                    $budgets_indexed[$budget['region']] = $budget;
                                }
                                
                                $row_total = 0;
                                
                                foreach ($REGIONAL_SETTINGS as $region => $settings): 
                                    $budget_data = $budgets_indexed[$region] ?? null;
                                    $amount = $budget_data ? $budget_data['budget_amount'] : $settings['budget_limit'] ?? 0;
                                    $currency = $budget_data ? $budget_data['currency'] : $settings['currency'];
                                    $row_total += $amount;
                                    
                                    // Calculate % change from previous year
                                    $prev_year = $year - 1;
                                    $prev_budget = 0;
                                    if (isset($budgets_by_year[$prev_year])) {
                                        foreach ($budgets_by_year[$prev_year] as $prev) {
                                            if ($prev['region'] == $region) {
                                                $prev_budget = $prev['budget_amount'];
                                                break;
                                            }
                                        }
                                    }
                                    $percent_change = $prev_budget > 0 ? (($amount - $prev_budget) / $prev_budget) * 100 : 0;
                                ?>
                                <tr>
    <td>
        <span class="region-color-dot" style="background-color: <?= $settings['color'] ?? '#666666' ?>"></span>
        <strong><?= $region ?></strong>
    </td>
    <td>
        <select name="currency[<?= $region ?>]" class="form-select form-select-sm" style="width: 100px;">
            <option value="EUR" <?= $currency == 'EUR' ? 'selected' : '' ?>>EUR</option>
            <option value="USD" <?= $currency == 'USD' ? 'selected' : '' ?>>USD</option>
            <option value="GBP" <?= $currency == 'GBP' ? 'selected' : '' ?>>GBP</option>
            <option value="AUD" <?= $currency == 'AUD' ? 'selected' : '' ?>>AUD</option>
            <option value="INR" <?= $currency == 'INR' ? 'selected' : '' ?>>INR</option>
        </select>
    </td>
    <td>
        <div class="input-group input-group-sm">
            <span class="input-group-text"><?= $CURRENCY_SYMBOLS[$currency] ?? '€' ?></span>
            <input type="number" 
                   name="amount[<?= $region ?>]" 
                   class="form-control budget-cell"
                   value="<?= number_format($amount, 2, '.', '') ?>"
                   step="0.01"
                   min="0">
        </div>
    </td>
                                       <td>
        <?php if ($budget_data): ?>
            <span class="badge bg-success">Set</span>
        <?php else: ?>
            <span class="badge bg-warning">Default</span>
        <?php endif; ?>
    </td>
                                     <td>
        <?php if ($prev_budget > 0): ?>
            <span class="<?= $percent_change >= 0 ? 'budget-change-positive' : 'budget-change-negative' ?>">
                <?= $percent_change >= 0 ? '+' : '' ?><?= round($percent_change, 1) ?>%
            </span>
        <?php else: ?>
            <span class="text-muted">N/A</span>
        <?php endif; ?>
    </td>
                                        <td>
        <!-- Individual save button -->
        <button type="button" 
                class="btn btn-sm btn-outline-primary save-single" 
                data-region="<?= $region ?>" 
                data-year="<?= $year ?>">
            <i class="fas fa-save"></i> Save
        </button>
    </td>
                                </tr>
                                <?php endforeach; ?>
                                
                                <!-- Total Row -->
                               <!-- Total Row -->
<tr class="year-total">
    <td colspan="4">
        <strong>Total for <?= $year ?>: <?= formatCurrency($row_total, 'EUR') ?></strong>
    </td>
</tr>
</tr>
                            </tbody>
                        </table>
                    </div>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
        
        <!-- Budget Planning for Future Years -->
        <div class="glass-card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-line"></i> Budget Planning</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6>Create New Budget Year</h6>
                        <form method="post" class="row g-3">
                            <div class="col-md-8">
                                <select name="new_year" class="form-select" required>
                                    <option value="">Select Year</option>
                                    <?php for ($y = $current_year + 1; $y <= $current_year + 5; $y++): ?>
                                        <option value="<?= $y ?>"><?= $y ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <button type="submit" name="create_year" class="btn btn-success w-100">
                                    <i class="fas fa-plus"></i> Create
                                </button>
                            </div>
                        </form>
                    </div>
                    <div class="col-md-6">
                        <h6>Budget Growth Rate</h6>
                        <div class="input-group">
                            <input type="number" class="form-control" placeholder="Annual growth rate" step="0.1">
                            <span class="input-group-text">%</span>
                            <button class="btn btn-outline-secondary" type="button">
                                <i class="fas fa-calculator"></i> Project
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Function to save single budget
    function saveSingleBudget(button) {
        const region = button.getAttribute('data-region');
        const year = button.getAttribute('data-year');
        const row = button.closest('tr');
        const amountInput = row.querySelector('input[name^="amount["]');
        const currencySelect = row.querySelector('select[name^="currency["]');
        
        const amount = amountInput.value;
        const currency = currencySelect.value;
        
        // Submit via form
        const form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';
        
        const regionInput = document.createElement('input');
        regionInput.type = 'hidden';
        regionInput.name = 'region';
        regionInput.value = region;
        form.appendChild(regionInput);
        
        const yearInput = document.createElement('input');
        yearInput.type = 'hidden';
        yearInput.name = 'year';
        yearInput.value = year;
        form.appendChild(yearInput);
        
        const amountInput2 = document.createElement('input');
        amountInput2.type = 'hidden';
        amountInput2.name = 'amount';
        amountInput2.value = amount;
        form.appendChild(amountInput2);
        
        const currencyInput = document.createElement('input');
        currencyInput.type = 'hidden';
        currencyInput.name = 'currency';
        currencyInput.value = currency;
        form.appendChild(currencyInput);
        
        const submitInput = document.createElement('input');
        submitInput.type = 'hidden';
        submitInput.name = 'update_budget';
        submitInput.value = '1';
        form.appendChild(submitInput);
        
        document.body.appendChild(form);
        form.submit();
    }
    
    // Auto-update currency symbol when currency changes
    document.addEventListener('change', function(e) {
        if (e.target.name && e.target.name.startsWith('currency[')) {
            const row = e.target.closest('tr');
            const currency = e.target.value;
            const inputGroup = row.querySelector('.input-group');
            const currencySpan = inputGroup.querySelector('.input-group-text');
            const symbol = getCurrencySymbol(currency);
            if (currencySpan) {
                currencySpan.textContent = symbol;
            }
        }
    });
    
    function getCurrencySymbol(currency) {
        const symbols = {
            'EUR': '€',
            'USD': '$',
            'GBP': '£',
            'AUD': 'A$',
            'INR': '₹'
        };
        return symbols[currency] || '€';
    }
    
    // Add event listeners to individual save buttons
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.save-single').forEach(button => {
            button.addEventListener('click', function() {
                saveSingleBudget(this);
            });
        });
    });
    
    // Smooth scroll to year sections
    document.querySelectorAll('a[href^="#year-"]').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const targetId = this.getAttribute('href');
            const targetElement = document.querySelector(targetId);
            if (targetElement) {
                targetElement.scrollIntoView({ behavior: 'smooth' });
                targetElement.classList.add('highlight');
                setTimeout(() => {
                    targetElement.classList.remove('highlight');
                }, 2000);
            }
        });
    });
</script>
</body>
</html>