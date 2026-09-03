<?php
// header.php - SIDEBAR VERSION with Tabler
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect sales_person users to salesout (they should only access salesout)
// Except for account, logout, and login pages
$req = $_SERVER['REQUEST_URI'] ?? '';
if (isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'sales_person') {
    if (!preg_match('#/(account|logout|login)\.php#', $req)) {
        header("Location: salesout/index.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= defined('APP_NAME') ? APP_NAME : 'Budget System' ?></title>
    
    <!-- Tabler Core CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.40.0/tabler-icons.min.css">
    
    <!-- Custom sidebar styles -->
    <style>
        :root {
            --sidebar-width: 260px;
            --sidebar-collapsed-width: 70px;
            --header-height: 70px;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Inter', sans-serif;
            min-height: 100vh;
        }
        
        /* Sidebar Layout */
        .page-wrapper {
            display: flex;
            min-height: 100vh;
        }
        
        /* Sidebar */
        .sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(180deg, #00353d 0%, #001a1f 100%);
            color: white;
            transition: transform 0.2s ease;
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            z-index: 1000;
            box-shadow: 4px 0 20px rgba(0, 0, 0, 0.1);
        }
        
        .sidebar-header {
            padding: 1.5rem 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .sidebar-logo {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
            color: white;
            font-weight: 700;
            font-size: 1.25rem;
        }
        
        .sidebar-logo img {
            width:100%;
            height: auto;
        }
        
        .sidebar-body {
            padding: 1rem 0;
            height: calc(100vh - 70px);
            overflow-y: auto;
        }
        
        /* Navigation */
        .nav-sidebar {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            padding: 0 0.75rem;
        }
        
        .nav-item {
            margin-bottom: 0.25rem;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            border-radius: 8px;
            transition: background-color 0.12s ease, color 0.12s ease;
            font-weight: 500;
        }
        
        .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }
        
        .nav-link.active {
            background: linear-gradient(135deg, #00a399 0%, #008f85 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(0, 163, 153, 0.3);
        }
        
        .nav-link .nav-icon {
            font-size: 1.25rem;
            width: 24px;
            text-align: center;
        }
        
        .nav-link .nav-text {
            flex-grow: 1;
        }
        
        .nav-link .nav-arrow {
            font-size: 0.875rem;
            transition: transform 0.15s ease;
        }
        
        .nav-link[aria-expanded="true"] .nav-arrow {
            transform: rotate(180deg);
        }
        
        /* Sidebar dropdown expand/collapse - faster than Bootstrap's default 350ms */
        .sidebar .collapse.collapsing {
            transition: height 0.15s ease;
        }
        
        /* Dropdown menu */
        .dropdown-menu {
            background: rgba(255, 255, 255, 0.05);
            border: none;
            box-shadow: none;
            margin: 0;
            padding: 0.5rem 0;
        }
        
        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.625rem 1rem 0.625rem 3rem;
            color: rgba(255, 255, 255, 0.8);
            border-radius: 6px;
            margin: 0 0.5rem;
            width: auto;
            transition: background-color 0.12s ease, color 0.12s ease;
        }
        
        .dropdown-item:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }
        
        .dropdown-divider {
            border-color: rgba(255, 255, 255, 0.1);
            margin: 0.5rem 1rem;
        }
        
        .dropdown-header {
            color: rgba(255, 255, 255, 0.5);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            padding: 0.5rem 1rem 0.5rem 3rem;
            margin-top: 0.5rem;
        }
        
        /* Main content area */
        .page-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            background: #f5f7fb;
            min-height: 100vh;
            transition: margin-left 0.2s ease;
        }
        
        .page-header {
            background: white;
            border-bottom: 1px solid #e1e5eb;
            padding: 1rem 1.5rem;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        
        .page-body {
            padding: 1.5rem;
        }
        
        /* User dropdown in header */
        .user-dropdown .dropdown-menu {
            background: white;
            border: 1px solid #e1e5eb;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            min-width: 200px;
        }
        
        .user-dropdown .dropdown-item {
            color: #2a3547;
            padding: 0.5rem 1rem;
        }
        
        .user-dropdown .dropdown-item:hover {
            background: #f8f9fa;
            color: #00a399;
        }
        
        /* Mobile sidebar toggle */
        .sidebar-toggle {
            display: none;
            background: none;
            border: none;
            color: #2a3547;
            font-size: 1.5rem;
            cursor: pointer;
        }
        
        /* Responsive */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.mobile-open {
                transform: translateX(0);
                transition: transform 0.2s ease;
            }
            
            .page-content {
                margin-left: 0;
            }
            
            .sidebar-toggle {
                display: block;
            }
            
            .sidebar-overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.5);
                z-index: 999;
            }
            
            .sidebar-overlay.mobile-open {
                display: block;
            }
        }
        
        /* Current page indicator */
        .current-page {
            background: rgba(0, 163, 153, 0.1);
            border-left: 3px solid #00a399;
        }
    </style>
</head>
<body>
    <div class="page-wrapper">
        <!-- Sidebar Overlay (mobile only) -->
        <div class="sidebar-overlay" id="sidebarOverlay"></div>
        
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <a href="index.php" class="sidebar-logo">
                    <img src="https://eposaudioevents.com/budgets/assets/budgettoollogo.svg" alt="Budget System">
                </a>
            </div>
            
            <div class="sidebar-body">
                <nav class="nav-sidebar">
                    <!-- Dashboard -->
                    <div class="nav-item">
                        <a href="index.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : '' ?>">
                            <span class="nav-icon"><i class="ti ti-home"></i></span>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </div>
                    
                    <!-- Data Management -->
                    <div class="nav-item">
                        <a href="#dataSubmenu" class="nav-link" data-bs-toggle="collapse" 
                           aria-expanded="<?= in_array(basename($_SERVER['PHP_SELF']), ['import.php', 'budget_export.php', 'export_filter_simple.php', 'budget_manager.php', 'excel_api.php', 'vendor_manager.php', 'add_item.php', 'edit_item.php']) ? 'true' : 'false' ?>">
                            <span class="nav-icon"><i class="ti ti-database"></i></span>
                            <span class="nav-text">Data Management</span>
                            <span class="nav-arrow"><i class="ti ti-chevron-down"></i></span>
                        </a>
                        <div class="collapse <?= in_array(basename($_SERVER['PHP_SELF']), ['import.php', 'budget_export.php', 'export_filter_simple.php', 'budget_manager.php', 'excel_api.php', 'vendor_manager.php', 'add_item.php', 'edit_item.php']) ? 'show' : '' ?>" id="dataSubmenu">
                            <!-- Import Section -->
                            <div class="dropdown-header">Import</div>
                            <a href="import.php" class="dropdown-item <?= basename($_SERVER['PHP_SELF']) == 'import.php' ? 'current-page' : '' ?>">
                                <i class="ti ti-upload"></i> Import CSV
                            </a>
                            
                            <!-- Export Section -->
                            <div class="dropdown-header">Export</div>
                            <a href="budget_export.php" class="dropdown-item <?= basename($_SERVER['PHP_SELF']) == 'budget_export.php' ? 'current-page' : '' ?>">
                                <i class="ti ti-download"></i> Full Export
                            </a>
                            <a href="export_filter_simple.php" class="dropdown-item <?= basename($_SERVER['PHP_SELF']) == 'export_filter_simple.php' ? 'current-page' : '' ?>">
                                <i class="ti ti-filter"></i> Filtered Export
                            </a>
                            
                            <!-- Budget Manager -->
                            <div class="dropdown-header">Management</div>
                            <a href="add_item.php" class="dropdown-item <?= basename($_SERVER['PHP_SELF']) == 'add_item.php' ? 'current-page' : '' ?>">
                                <i class="ti ti-plus"></i> Add Budget Item
                            </a>
                            <a href="https://eposaudioevents.com/budgets/budget_manager.php" class="dropdown-item">
                                <i class="ti ti-list-check"></i> Budget Manager
                            </a>
                            <a href="headset_finder_data_manager.php" class="dropdown-item <?= basename($_SERVER['PHP_SELF']) == 'headset_finder_data_manager.php' ? 'current-page' : '' ?>">
                                <i class="ti ti-headphones"></i> Headset finder data
                            </a>
                            <a href="vendor_manager.php" class="dropdown-item <?= basename($_SERVER['PHP_SELF']) == 'vendor_manager.php' ? 'current-page' : '' ?>">
                                <i class="ti ti-building-store"></i> Vendor Manager
                            </a>
                            
                            <!-- Excel Integration -->
                            <div class="dropdown-header">Excel Integration</div>
                            <a href="excel_api.php?format=csv" target="_blank" class="dropdown-item">
                                <i class="ti ti-file-spreadsheet"></i> CSV API
                            </a>
                            <a href="excel_api.php?format=json" target="_blank" class="dropdown-item">
                                <i class="ti ti-code"></i> JSON API
                            </a>
                        </div>
                    </div>
                    
                    <!-- Regional Views -->
                    <div class="nav-item">
                        <a href="#regionalSubmenu" class="nav-link" data-bs-toggle="collapse" 
                           aria-expanded="<?= basename($_SERVER['PHP_SELF']) == 'regional_view.php' ? 'true' : 'false' ?>">
                            <span class="nav-icon"><i class="ti ti-map"></i></span>
                            <span class="nav-text">Regional Views</span>
                            <span class="nav-arrow"><i class="ti ti-chevron-down"></i></span>
                        </a>
                        <div class="collapse <?= basename($_SERVER['PHP_SELF']) == 'regional_view.php' ? 'show' : '' ?>" id="regionalSubmenu">
                            <a href="regional_view.php" class="dropdown-item <?= basename($_SERVER['PHP_SELF']) == 'regional_view.php' && empty($_GET['region']) ? 'current-page' : '' ?>">
                                <i class="ti ti-world"></i> All Regions
                            </a>
                            <div class="dropdown-divider"></div>
                            <div class="dropdown-header">Individual Regions</div>
                            <?php 
                            $regions = [
                                'AMER' => 'AMER',
                                'ANZ' => 'ANZ', 
                                'APAC' => 'APAC',
                                'BNL' => 'BNL',
                                'DACH' => 'DACH',
                                'EMEA_PARTNERS' => 'EMEA Partners',
                                'FRANCE' => 'France',
                                'INDIA' => 'India',
                                'NORD' => 'Nord',
                                'UKI' => 'UKI'
                            ];
                            foreach ($regions as $key => $label): ?>
                            <a href="regional_view.php?region=<?= $key ?>" class="dropdown-item <?= basename($_SERVER['PHP_SELF']) == 'regional_view.php' && ($_GET['region'] ?? '') == $key ? 'current-page' : '' ?>">
                                <i class="ti ti-map-pin"></i> <?= $label ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Reports -->
                    <div class="nav-item">
                        <a href="#reportsSubmenu" class="nav-link" data-bs-toggle="collapse" 
                           aria-expanded="<?= in_array(basename($_SERVER['PHP_SELF']), ['reports.php', 'executive_summary.php', 'mbr_report.php']) ? 'true' : 'false' ?>">
                            <span class="nav-icon"><i class="ti ti-chart-pie"></i></span>
                            <span class="nav-text">Reports</span>
                            <span class="nav-arrow"><i class="ti ti-chevron-down"></i></span>
                        </a>
                        <div class="collapse <?= in_array(basename($_SERVER['PHP_SELF']), ['reports.php', 'executive_summary.php', 'mbr_report.php']) ? 'show' : '' ?>" id="reportsSubmenu">
                            <a href="reports.php" class="dropdown-item <?= basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'current-page' : '' ?>">
                                <i class="ti ti-chart-line"></i> Budget Analytics
                            </a>
                            <a href="executive_summary.php?region=all" class="dropdown-item <?= basename($_SERVER['PHP_SELF']) == 'executive_summary.php' && ($_GET['region'] ?? 'all') == 'all' ? 'current-page' : '' ?>">
                                <i class="ti ti-file-report"></i> Executive Summary <br />- All Regions
                            </a>
                            <a href="executive_summary.php?region=<?= htmlspecialchars($_SESSION['region'] ?? 'AMER') ?>" class="dropdown-item <?= basename($_SERVER['PHP_SELF']) == 'executive_summary.php' && ($_GET['region'] ?? '') != 'all' ? 'current-page' : '' ?>">
                                <i class="ti ti-map-pin"></i> Executive Summary <br />- Regional
                            </a>
                            <a href="mbr_report.php" class="dropdown-item <?= basename($_SERVER['PHP_SELF']) == 'mbr_report.php' ? 'current-page' : '' ?>">
                                <i class="ti ti-layout-grid"></i> MBR Report
                            </a>
                            <div class="dropdown-divider"></div>
                            <a href="salesout/index.php" class="dropdown-item">
                                <i class="ti ti-shopping-cart"></i> Sales Out Reporting
                            </a>
                        </div>
                    </div>
                    
                    <!-- Conversion Rates -->
                    <div class="nav-item">
                        <a href="conversion_rates.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'conversion_rates.php' ? 'active' : '' ?>">
                            <span class="nav-icon"><i class="ti ti-exchange"></i></span>
                            <span class="nav-text">Conversion Rates</span>
                        </a>
                    </div>
                    
                    <!-- Admin Menu -->
                    <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                    <div class="nav-item">
                        <a href="user_management.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'user_management.php' ? 'active' : '' ?>">
                            <span class="nav-icon"><i class="ti ti-users"></i></span>
                            <span class="nav-text">User Management</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="form_manager.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'form_manager.php' ? 'active' : '' ?>">
                            <span class="nav-icon"><i class="ti ti-list-details"></i></span>
                            <span class="nav-text">Form Manager</span>
                        </a>
                    </div>
                    <?php endif; ?>
                </nav>
            </div>
        </aside>
        
        <!-- Main Content -->
        <main class="page-content">
            <!-- Page Header -->
            <header class="page-header">
                <div class="d-flex justify-content-between align-items-center">
                    <!-- Mobile Toggle -->
                    <button class="sidebar-toggle" id="sidebarToggle">
                        <i class="ti ti-menu-2"></i>
                    </button>
                    
                    <!-- Page Title -->
                    <div class="d-none d-md-block">
                        <h1 class="h3 mb-0"><?= defined('APP_NAME') ? APP_NAME : 'Budget System' ?></h1>
                    </div>
                    
                    <!-- User Dropdown -->
                    <div class="user-dropdown">
                        <?php if (isset($_SESSION['user_id'])): ?>
                        <div class="dropdown">
                            <button class="btn btn-outline-secondary dropdown-toggle d-flex align-items-center gap-2" 
                                    type="button" data-bs-toggle="dropdown">
                                <i class="ti ti-user-circle"></i>
                                <span><?= htmlspecialchars($_SESSION['display_name'] ?? 'User') ?></span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li>
                                    <span class="dropdown-item-text">
                                        <small class="text-muted">Role: <?= htmlspecialchars($_SESSION['role'] ?? 'Unknown') ?></small>
                                    </span>
                                </li>
                                <li>
                                    <span class="dropdown-item-text">
                                        <small class="text-muted">Region: <?= !empty($_SESSION['region']) ? htmlspecialchars($_SESSION['region']) : 'All Regions' ?></small>
                                    </span>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <a class="dropdown-item" href="account.php">
                                        <i class="ti ti-user-cog me-2"></i>My Account
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="logout.php">
                                        <i class="ti ti-logout me-2"></i>Logout
                                    </a>
                                </li>
                            </ul>
                        </div>
                        <?php else: ?>
                        <a href="login.php" class="btn btn-primary">
                            <i class="ti ti-login me-2"></i>Login
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </header>
            <script>
document.addEventListener('DOMContentLoaded', function() {
    // Fix for sidebar dropdown collapse
    const sidebarDropdowns = document.querySelectorAll('.sidebar .nav-link[data-bs-toggle="collapse"]');
    
    sidebarDropdowns.forEach(dropdown => {
        dropdown.addEventListener('click', function(e) {
            // Prevent default Bootstrap behavior
            e.preventDefault();
            
            // Get the target collapse element
            const targetId = this.getAttribute('href');
            const target = document.querySelector(targetId);
            
            if (!target) return;
            
            // Check if it's currently collapsed
            const isCollapsed = target.classList.contains('show');
            
            // Close all other dropdowns first
            sidebarDropdowns.forEach(otherDropdown => {
                if (otherDropdown !== this) {
                    const otherTargetId = otherDropdown.getAttribute('href');
                    const otherTarget = document.querySelector(otherTargetId);
                    if (otherTarget && otherTarget.classList.contains('show')) {
                        otherTarget.classList.remove('show');
                        otherDropdown.setAttribute('aria-expanded', 'false');
                    }
                }
            });
            
            // Toggle the clicked dropdown
            if (isCollapsed) {
                target.classList.remove('show');
                this.setAttribute('aria-expanded', 'false');
            } else {
                target.classList.add('show');
                this.setAttribute('aria-expanded', 'true');
            }
        });
    });
    
    // Close dropdowns when clicking outside (for mobile)
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.sidebar') && window.innerWidth < 992) {
            sidebarDropdowns.forEach(dropdown => {
                const targetId = dropdown.getAttribute('href');
                const target = document.querySelector(targetId);
                if (target && target.classList.contains('show')) {
                    target.classList.remove('show');
                    dropdown.setAttribute('aria-expanded', 'false');
                }
            });
        }
    });
    
    // Auto-expand the current section
    const currentPage = window.location.pathname.split('/').pop();
    sidebarDropdowns.forEach(dropdown => {
        const targetId = dropdown.getAttribute('href');
        const target = document.querySelector(targetId);
        
        if (target) {
            // Check if any child link matches current page
            const childLinks = target.querySelectorAll('.dropdown-item');
            let isActiveSection = false;
            
            childLinks.forEach(link => {
                if (link.getAttribute('href') && link.getAttribute('href').includes(currentPage)) {
                    isActiveSection = true;
                }
            });
            
            // If it's the active section, expand it
            if (isActiveSection) {
                target.classList.add('show');
                dropdown.setAttribute('aria-expanded', 'true');
            }
        }
    });
});
</script>
            
            <!-- Page Body -->
            <div class="page-body">
                <!-- Content will be inserted here by each page -->