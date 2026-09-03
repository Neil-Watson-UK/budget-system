<?php
// salesout/header.php - Nav for Sales Out (mega nav)
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!defined('SALESOUT_APP_NAME')) define('SALESOUT_APP_NAME', 'Sales Out Report');

$current = basename($_SERVER['PHP_SELF'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= defined('SALESOUT_APP_NAME') ? SALESOUT_APP_NAME : 'Sales Out' ?></title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="preconnect" href="https://images.icecat.biz" crossorigin>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.40.0/tabler-icons.min.css">
    <link rel="stylesheet" href="assets/salesout-theme.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" defer></script>
</head>
<body>
    <div class="page-wrapper">
        <header class="navbar navbar-expand-md navbar-dark salesout-navbar d-print-none">
            <div class="container-xl">
                <a href="index.php" class="navbar-brand"><?= SALESOUT_APP_NAME ?></a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain" aria-controls="navbarMain" aria-expanded="false">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="navbarMain">
                    <ul class="navbar-nav me-auto">
                        <li class="nav-item <?= $current === 'index.php' ? 'active' : '' ?>">
                            <a href="index.php" class="nav-link">Dashboard</a>
                        </li>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown" aria-expanded="false">Reporting</a>
                            <ul class="dropdown-menu salesout-megadrop">
                                <li><a class="dropdown-item" href="index.php"><i class="ti ti-dashboard"></i> Dashboard</a></li>
                                <li><a class="dropdown-item" href="insights.php"><i class="ti ti-chart-line"></i> Insights</a></li>
                                <li><a class="dropdown-item" href="reseller_report.php"><i class="ti ti-report-analytics"></i> Reseller Report</a></li>
                                <li><a class="dropdown-item" href="account_manager_report.php"><i class="ti ti-users"></i> Account Manager Report</a></li>
                                <li><a class="dropdown-item" href="distributor_report.php"><i class="ti ti-building-store"></i> Distributor Report</a></li>
                                <li><a class="dropdown-item" href="top_sellers.php"><i class="ti ti-trophy"></i> Top Sellers</a></li>
                                <li><a class="dropdown-item" href="product_portfolio.php"><i class="ti ti-chart-bubble"></i> Product Portfolio</a></li>
                                <li><a class="dropdown-item" href="executive_summary.php"><i class="ti ti-file-report"></i> Executive Summary</a></li>
                                <li><a class="dropdown-item" href="inventory_report.php"><i class="ti ti-package"></i> Inventory &amp; Weeks of Stock</a></li>
                                <li><a class="dropdown-item" href="inventory_trends.php"><i class="ti ti-chart-line"></i> Inventory Trends</a></li>
                                <li><a class="dropdown-item" href="inventory_movement.php"><i class="ti ti-arrows-exchange"></i> Stock Movement</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="missing_data.php"><i class="ti ti-alert-circle"></i> Missing Data</a></li>
                                <?php if (defined('SALESOUT_OPPORTUNITIES_ENABLED') && SALESOUT_OPPORTUNITIES_ENABLED): ?>
                                <li><a class="dropdown-item" href="opportunity_checker.php"><i class="ti ti-briefcase"></i> Opportunity Checker</a></li>
                                <?php endif; ?>
                            </ul>
                        </li>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown" aria-expanded="false">Manage Data</a>
                            <ul class="dropdown-menu salesout-megadrop">
                                <li><a class="dropdown-item" href="import.php"><i class="ti ti-upload"></i> Import</a></li>
                                <li><a class="dropdown-item" href="inventory_import.php"><i class="ti ti-package-import"></i> Import Inventory</a></li>
                                <li><a class="dropdown-item" href="export.php"><i class="ti ti-file-export"></i> Export Excel</a></li>
                                <li><a class="dropdown-item" href="excel_reports.php"><i class="ti ti-file-spreadsheet"></i> Excel Reports</a></li>
                                <li><a class="dropdown-item" href="data_editor.php"><i class="ti ti-edit"></i> Data Editor</a></li>
                                <li><a class="dropdown-item" href="backfill_total_value.php"><i class="ti ti-calculator"></i> Backfill Total Value</a></li>
                                <li><a class="dropdown-item" href="unmatched_skus.php"><i class="ti ti-barcode-off"></i> Unmatched SKUs</a></li>
                                <li><a class="dropdown-item" href="targets.php"><i class="ti ti-target"></i> Targets</a></li>
                                <li><a class="dropdown-item" href="mapping.php"><i class="ti ti-link"></i> Reseller Mapping</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="products.php"><i class="ti ti-package"></i> Products</a></li>
                                <li><a class="dropdown-item" href="icecat_import.php"><i class="ti ti-photo"></i> Icecat Images</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="../vendor_manager.php"><i class="ti ti-users"></i> Vendors</a></li>
                            </ul>
                        </li>
                    </ul>
                    <div class="navbar-nav flex-row order-md-last align-items-center">
                        <?php
                        $valueMode = getSalesOutValueMode();
                        $valueLabels = getSalesOutValueModeLabels();
                        $valueBack = htmlspecialchars(($_SERVER['PHP_SELF'] ?? 'index.php') . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''));
                        ?>
                        <div class="dropdown me-2">
                            <a class="nav-link px-2 dropdown-toggle" href="#" data-bs-toggle="dropdown" aria-expanded="false" title="How totals are calculated">Totals: <?= htmlspecialchars($valueLabels[$valueMode]) ?></a>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item <?= $valueMode === 'disti' ? 'active' : '' ?>" href="set_value_mode.php?mode=disti&back=<?= $valueBack ?>">Distributor reported</a></li>
                                <li><a class="dropdown-item <?= $valueMode === 'trade' ? 'active' : '' ?>" href="set_value_mode.php?mode=trade&back=<?= $valueBack ?>">Trade</a></li>
                                <li><a class="dropdown-item <?= $valueMode === 'msrp' ? 'active' : '' ?>" href="set_value_mode.php?mode=msrp&back=<?= $valueBack ?>">MSRP</a></li>
                            </ul>
                        </div>
                        <?php if (isset($_SESSION['role']) && $_SESSION['role'] !== 'sales_person'): ?>
                        <a href="../index.php" class="nav-link px-2">Budget System</a>
                        <?php endif; ?>
                        <?php if (isset($_SESSION['user_id'])): ?>
                        <div class="dropdown">
                            <a href="#" class="nav-link px-2 dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><?= htmlspecialchars($_SESSION['display_name'] ?? $_SESSION['username'] ?? 'User') ?></a>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="../account.php"><i class="ti ti-user-cog me-2"></i>My Account</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="../logout.php"><i class="ti ti-logout me-2"></i>Logout</a></li>
                            </ul>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </header>
        <div class="page-body" style="padding: 1.5rem;">