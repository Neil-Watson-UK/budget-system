<?php
// index.php - UPDATED with Tabler styling
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Redirect sales_person users to salesout (they should only access salesout)
if (isset($_SESSION['role']) && $_SESSION['role'] === 'sales_person') {
    header("Location: salesout/index.php");
    exit;
}

// Redirect users with a specific region to their regional view as default
$user_region = trim($_SESSION['region'] ?? '');
if ($user_region !== '') {
    require_once __DIR__ . '/config.php';
    if (isset($REGIONAL_SETTINGS[$user_region])) {
        header("Location: regional_view.php?region=" . urlencode($user_region));
        exit;
    }
}

require_once 'config.php';
require_once 'functions.php';

$pdo = getDBConnection();

// Get selected year from URL or default to current year
$selected_year = $_GET['year'] ?? date('Y');

// Validate year (must be between 2020 and 2030)
if (!preg_match('/^\d{4}$/', $selected_year) || $selected_year < 2020 || $selected_year > 2030) {
    $selected_year = date('Y');
}

// Get global summary for selected year
$global_summary = getGlobalSummary($pdo, $selected_year);

// Get summary for all years (for comparison)
$all_years_summary = getAllYearsSummary($pdo);

// Calculate totals for selected year
$total_budget = 0;
$total_spent = 0;

foreach ($global_summary as $region_data) {
    $bl = $region_data['budget_limit'];
    $ccy = $region_data['budget_currency'] ?? 'EUR';
    $total_budget += ($ccy === 'EUR') ? $bl : convertToEUR($bl, $ccy, $pdo);
    $total_spent += $region_data['total_amount'];
}

$utilization = $total_budget > 0 ? ($total_spent / $total_budget) * 100 : 0;

// Get previous year data for comparison
$prev_year = $selected_year - 1;
$prev_year_summary = getGlobalSummary($pdo, $prev_year);
$prev_total_budget = 0;
$prev_total_spent = 0;

foreach ($prev_year_summary as $region_data) {
    $bl = $region_data['budget_limit'];
    $ccy = $region_data['budget_currency'] ?? 'EUR';
    $prev_total_budget += ($ccy === 'EUR') ? $bl : convertToEUR($bl, $ccy, $pdo);
    $prev_total_spent += $region_data['total_amount'];
}

// Get previous year utilization
$prev_utilization = ($prev_total_budget > 0 && $prev_total_spent > 0) ? 
    ($prev_total_spent / $prev_total_budget) * 100 : 0;
$utilization_change = $utilization - $prev_utilization;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Global Dashboard</title>
    
    <!-- Tabler CSS is already included in header.php -->
    <!-- Custom styles for dashboard -->
    <style>
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Inter', sans-serif; 
            margin: 0;
            padding: 0;
            background: #f5f7fb;
            min-height: 100vh;
        }
        
        /* Add margin top to account for fixed navbar */
        .dashboard-container {
            padding-top: 100px !important;
        }
        
        /* Dashboard cards */
        .dashboard-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            height: 100%;
        }
        
        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
        }
        
         .card-body {
        padding: 20px !important;
    }
    
     /* Make sure dropdowns work properly */
    .navbar-custom .dropdown-menu {
        position: absolute !important;
        z-index: 1000 !important;
        margin-top: 0.125rem !important;
    }
    
    /* Fix for dropdown positioning */
    .navbar-custom .nav-item.dropdown {
        position: relative;
    }
    
    /* Ensure dropdown triggers work */
    .navbar-custom .dropdown-toggle::after {
        display: inline-block;
        margin-left: 0.255em;
        vertical-align: 0.255em;
        content: "";
        border-top: 0.3em solid;
        border-right: 0.3em solid transparent;
        border-bottom: 0;
        border-left: 0.3em solid transparent;
    }
        
        .trend-up {
            background: rgba(46, 204, 113, 0.1);
            color: #27ae60;
        }
        
        .trend-down {
            background: rgba(231, 76, 60, 0.1);
            color: #e74c3c;
        }
        
        .year-badge {
            background: linear-gradient(135deg, #00a399, #00353d);
            color: white;
            padding: 0.5rem 1.25rem;
            border-radius: 20px;
            font-size: 1rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        /* Quick actions */
        .quick-action-btn {
            border-radius: 8px;
            padding: 0.75rem 1.25rem;
            font-weight: 500;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .quick-action-btn:hover {
            transform: translateY(-2px);
        }
        
        /* Progress bar */
        .utilization-progress {
            height: 10px;
            border-radius: 10px;
            background: #e9ecef;
            overflow: hidden;
        }
        
        .utilization-progress-bar {
            height: 100%;
            background: linear-gradient(135deg, #00a399, #00353d);
            border-radius: 10px;
            transition: width 1s ease;
        }
        
        /* Table styles */
        .dashboard-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }
        
        .dashboard-table th {
            background: #f8f9fa;
            border-bottom: 2px solid #e1e5eb;
            font-weight: 600;
            color: #2a3547;
            padding: 1rem;
        }
        
        .dashboard-table td {
            padding: 1rem;
            vertical-align: middle;
        }
        
        .dashboard-table tbody tr:hover {
            background: #f8f9fa;
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease-out;
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .dashboard-container {
                padding-top: 120px !important;
            }
            
        }
    </style>
</head>
<body>
    <!-- Include the header navigation -->
    <?php require_once 'header.php'; ?>

    <div class="container dashboard-container">
        <!-- Welcome Message with Year Selector -->
        <div class="row mb-4 fade-in">
            <div class="col-12">
                <div class="dashboard-card">
                    <div class="card-body">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center">
                            <div>
                                <h4 class="mb-2">Welcome back, <?= htmlspecialchars($_SESSION['display_name']) ?>! 👋</h4>
                                <p class="mb-0 text-muted">
                                    You are logged in as <strong><?= htmlspecialchars($_SESSION['role']) ?></strong>
                                    <?php if (!empty($_SESSION['region'])): ?>
                                        for region: <strong><?= htmlspecialchars($_SESSION['region']) ?></strong>
                                    <?php else: ?>
                                        with <strong>global access</strong> to all regions
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="mt-3 mt-md-0">
                                <span class="year-badge">
                                    <i class="ti ti-calendar"></i> <?= $selected_year ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="dashboard-card mb-4 fade-in">
            <div class="card-body">
                <h5 class="card-title mb-3">
                    <i class="ti ti-bolt me-2"></i>Quick Actions for <?= $selected_year ?>
                </h5>
                <div class="d-flex flex-wrap gap-2">
                    <a href="add_item.php?year=<?= $selected_year ?>" class="btn btn-success quick-action-btn">
                        <i class="ti ti-plus"></i>Add <?= $selected_year ?> Item
                    </a>
                    
                    <a href="regional_view.php?year=<?= $selected_year ?>" class="btn btn-primary quick-action-btn">
                        <i class="ti ti-map"></i><?= $selected_year ?> Regional Views
                    </a>
                    
                    <a href="budget_export.php?year=<?= $selected_year ?>" class="btn btn-info quick-action-btn">
                        <i class="ti ti-download"></i>Export <?= $selected_year ?> Data
                    </a>
                    
                    <a href="reports.php?year=<?= $selected_year ?>" class="btn btn-warning quick-action-btn">
                        <i class="ti ti-chart-pie"></i><?= $selected_year ?> Analytics
                    </a>

                </div>
            </div>
        </div>

        <!-- Global Utilization -->
        <div class="dashboard-card mb-4 fade-in">
            <div class="card-body">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4">
                    <h5 class="card-title mb-2 mb-md-0">
                        <i class="ti ti-chart-line me-2"></i><?= $selected_year ?> Global Budget Utilization
                    </h5>
                    <div class="text-md-end">
                        <div class="fs-4 fw-bold"><?= round($utilization, 1) ?>%</div>
                        <?php if ($prev_total_budget > 0 && $prev_total_spent > 0): ?>
                            <small class="text-muted <?= $utilization_change >= 0 ? 'trend-up' : 'trend-down' ?>">
                                <?= $utilization_change >= 0 ? '+' : '' ?><?= round($utilization_change, 1) ?>% vs <?= $prev_year ?>
                            </small>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="mb-4">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Budget Utilization</span>
                        <span class="fw-bold"><?= round($utilization, 1) ?>%</span>
                    </div>
                    <div class="utilization-progress">
                        <div class="utilization-progress-bar" style="width: <?= $utilization ?>%"></div>
                    </div>
                </div>
                
                <div class="row text-center mt-4">
                    <div class="col-md-4">
                        <div class="fs-5 fw-bold"><?= formatCurrency($total_budget, 'EUR') ?></div>
                        <small class="text-muted">Total Budget</small>
                    </div>
                    <div class="col-md-4">
                        <div class="fs-5 fw-bold"><?= formatCurrency($total_spent, 'EUR') ?></div>
                        <small class="text-muted">Total Spent</small>
                    </div>
                    <div class="col-md-4">
                        <div class="fs-5 fw-bold"><?= formatCurrency($total_budget - $total_spent, 'EUR') ?></div>
                        <small class="text-muted">Remaining</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Multi-Year Summary -->
        <?php if (count($all_years_summary) > 1): ?>
        <div class="dashboard-card mb-4 fade-in">
            <div class="card-body">
                <h5 class="card-title mb-4">
                    <i class="ti ti-chart-bar me-2"></i>Multi-Year Overview
                </h5>
                <div class="table-responsive">
                    <table class="table dashboard-table">
                        <thead>
                            <tr>
                                <th>Year</th>
                                <th>Budget</th>
                                <th>Spent</th>
                                <th>Remaining</th>
                                <th>Utilization</th>
                                <th>Items</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_years_summary as $year_data): 
                                $year_utilization = $year_data['budget'] > 0 ? 
                                    ($year_data['spent'] / $year_data['budget']) * 100 : 0;
                            ?>
                            <tr class="<?= $year_data['year'] == $selected_year ? 'table-active' : '' ?>">
                                <td>
                                    <div class="d-flex align-items-center">
                                        <strong class="me-2"><?= $year_data['year'] ?></strong>
                                        <?php if ($year_data['year'] == date('Y')): ?>
                                            <span class="badge bg-info">Current</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="fw-bold"><?= formatCurrency($year_data['budget'], 'EUR') ?></td>
                                <td class="fw-bold"><?= formatCurrency($year_data['spent'], 'EUR') ?></td>
                                <td class="fw-bold"><?= formatCurrency($year_data['budget'] - $year_data['spent'], 'EUR') ?></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="flex-grow-1 me-2">
                                            <div class="utilization-progress">
                                                <div class="utilization-progress-bar" 
                                                     style="width: <?= min($year_utilization, 100) ?>%">
                                                </div>
                                            </div>
                                        </div>
                                        <span class="fw-bold"><?= round($year_utilization, 1) ?>%</span>
                                    </div>
                                </td>
                                <td><span class="badge bg-secondary"><?= $year_data['items'] ?></span></td>
                                <td class="text-end">
                                    <a href="?year=<?= $year_data['year'] ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="ti ti-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Add your other sections here (Regional Utilization, Recent Items, etc.) -->
        
    </div>

    <!-- Tabler and Bootstrap JS are already included in header.php -->
    <script>
        // Add subtle animations
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.fade-in');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
            });
            
            // Update page title with selected year
            document.title = "<?= APP_NAME ?> - <?= $selected_year ?> Dashboard";
            
            // Animate progress bars on load
            setTimeout(() => {
                document.querySelectorAll('.utilization-progress-bar').forEach(bar => {
                    const width = bar.style.width;
                    bar.style.width = '0';
                    setTimeout(() => {
                        bar.style.width = width;
                    }, 100);
                });
            }, 500);
        });
    </script>
    <script>
document.addEventListener('DOMContentLoaded', function() {
    // Debug dropdowns
    console.log('Dropdown elements on page:', document.querySelectorAll('.dropdown-toggle').length);
    
    // Force Bootstrap dropdown initialization
    var dropdowns = document.querySelectorAll('.dropdown-toggle');
    dropdowns.forEach(function(dropdown) {
        dropdown.addEventListener('click', function(e) {
            e.preventDefault();
            var parent = this.closest('.dropdown');
            var menu = parent.querySelector('.dropdown-menu');
            
            console.log('Dropdown clicked:', this);
            console.log('Parent element:', parent);
            console.log('Menu element:', menu);
            
            // Toggle manually if Bootstrap isn't working
            if (menu.style.display === 'block') {
                menu.style.display = 'none';
            } else {
                menu.style.display = 'block';
            }
        });
    });
    
    // Check if Bootstrap is loaded
    console.log('Bootstrap loaded?', typeof bootstrap !== 'undefined');
    console.log('Tabler loaded?', typeof Tabler !== 'undefined');
});
</script>
            <!-- Your dashboard content ends here -->
        </div> <!-- Close page-body -->
    </div> <!-- Close page-content -->
</div> <!-- Close page-wrapper -->

<!-- Tabler JavaScript -->
<script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/js/tabler.min.js"></script>
<!-- Bootstrap Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Your existing dashboard JavaScript
    document.addEventListener('DOMContentLoaded', function() {
        const cards = document.querySelectorAll('.fade-in');
        cards.forEach((card, index) => {
            card.style.animationDelay = `${index * 0.1}s`;
        });
        
        document.title = "<?= APP_NAME ?> - <?= $selected_year ?> Dashboard";
        
        setTimeout(() => {
            document.querySelectorAll('.utilization-progress-bar').forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0';
                setTimeout(() => {
                    bar.style.width = width;
                }, 100);
            });
        }, 500);
    });
</script>

<!-- Sidebar functionality -->
<script>
    // Mobile sidebar toggle
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('mobile-open');
            sidebarOverlay.classList.toggle('mobile-open');
        });
        
        sidebarOverlay.addEventListener('click', function() {
            sidebar.classList.remove('mobile-open');
            sidebarOverlay.classList.remove('mobile-open');
        });
    }
    
    // Auto-collapse other dropdowns when one opens
    document.querySelectorAll('.nav-link[data-bs-toggle="collapse"]').forEach(link => {
        link.addEventListener('click', function(e) {
            if (!this.getAttribute('aria-expanded') || this.getAttribute('aria-expanded') === 'false') {
                document.querySelectorAll('.nav-link[data-bs-toggle="collapse"]').forEach(otherLink => {
                    if (otherLink !== this && otherLink.getAttribute('aria-expanded') === 'true') {
                        const targetId = otherLink.getAttribute('href');
                        const target = document.querySelector(targetId);
                        if (target) {
                            target.classList.remove('show');
                            otherLink.setAttribute('aria-expanded', 'false');
                        }
                    }
                });
            }
        });
    });
</script>
</body>
</html>