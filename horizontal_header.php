<?php
// header.php - UPDATED with Tabler styling
if (session_status() === PHP_SESSION_NONE) {
    session_start();
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
    
    <!-- Custom header styles -->
    <style>
        .navbar-custom {
            background: linear-gradient(135deg, #00a399 0%, #00353d 100%) !important;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            padding: 0.5rem 0;
        }
        
        .navbar-custom .navbar-brand {
            font-weight: 700;
            font-size: 1.25rem;
            color: white !important;
        }
        
        .navbar-custom .navbar-brand img {
            height: 40px;
            margin-right: 10px;
        }
        
        .navbar-custom .nav-link {
            color: rgba(255, 255, 255, 0.9) !important;
            font-weight: 500;
            padding: 0.5rem 1rem !important;
            border-radius: 6px;
            transition: all 0.2s ease;
        }
        
        .navbar-custom .nav-link:hover {
            color: white !important;
            background: rgba(255, 255, 255, 0.1);
        }
        
        .navbar-custom .nav-link.active {
            background: rgba(255, 255, 255, 0.15);
            color: white !important;
        }
        
        .navbar-custom .dropdown-menu {
            background: white;
            border: none;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            border-radius: 10px;
            padding: 0.5rem;
        }
        
        .navbar-custom .dropdown-item {
            border-radius: 6px;
            padding: 0.5rem 1rem;
            transition: all 0.2s ease;
        }
        
        .navbar-custom .dropdown-item:hover {
            background: linear-gradient(135deg, #00a399 0%, #00353d 100%);
            color: white !important;
        }
        
        .navbar-custom .dropdown-header {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            color: #6c757d;
            padding: 0.5rem 1rem;
        }
        
        .user-dropdown-btn {
            background: rgba(255, 255, 255, 0.15) !important;
            border: 1px solid rgba(255, 255, 255, 0.2) !important;
            color: white !important;
            border-radius: 8px !important;
            padding: 0.5rem 1rem;
        }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-custom fixed-top">
    <div class="container">
        <a class="navbar-brand" href="index.php">
            <img src="https://eposaudioevents.com/budgets/assets/budgettoollogo.svg" 
                 alt="Budget System Logo" 
                 height="40" 
                 class="me-2">
            <?= defined('APP_NAME') ? APP_NAME : 'Budget System' ?>
        </a>
        
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link" href="index.php">
                        <i class="ti ti-home me-1"></i>Dashboard
                    </a>
                </li>
                
                <!-- Data Management Dropdown -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="ti ti-database me-1"></i>Data
                    </a>
                    <ul class="dropdown-menu">
                        <!-- Import Section -->
                        <li><span class="dropdown-header">Import</span></li>
                        <li><a class="dropdown-item" href="import.php">
                            <i class="ti ti-upload me-2"></i>Import CSV
                        </a></li>
                        
                        <!-- Export Section -->
                        <li><hr class="dropdown-divider"></li>
                        <li><span class="dropdown-header">Export</span></li>
                        <li><a class="dropdown-item" href="export.php">
                            <i class="ti ti-download me-2"></i>Full Export
                        </a></li>
                        <li><a class="dropdown-item" href="export_filter_simple.php">
                            <i class="ti ti-filter me-2"></i>Filtered Export
                        </a></li>
                        
                        <!-- Budget Manager -->
                        <li><hr class="dropdown-divider"></li>
                        <li><span class="dropdown-header">Management</span></li>
                        <li><a class="dropdown-item" href="https://eposaudioevents.com/budgets/budget_manager.php">
                            <i class="ti ti-list-check me-2"></i>Budget Manager
                        </a></li>
                        <li><a class="dropdown-item" href="headset_finder_data_manager.php">
                            <i class="ti ti-headphones me-2"></i>Headset finder data
                        </a></li>
                        
                        <!-- Excel Integration Section -->
                        <li><hr class="dropdown-divider"></li>
                        <li><span class="dropdown-header">Excel Integration</span></li>
                        <li><a class="dropdown-item" href="excel_api.php?format=csv" target="_blank">
                            <i class="ti ti-file-spreadsheet me-2"></i>CSV API
                        </a></li>
                        <li><a class="dropdown-item" href="excel_api.php?format=json" target="_blank">
                            <i class="ti ti-code me-2"></i>JSON API
                        </a></li>
                    </ul>
                </li>
                
                <!-- Regional Views Dropdown -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="ti ti-map me-1"></i>Regional Views
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="regional_view.php">
                            <i class="ti ti-world me-2"></i>All Regions
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><span class="dropdown-header">Individual Regions</span></li>
                        <li><a class="dropdown-item" href="regional_view.php?region=AMER">
                            <i class="ti ti-world me-2"></i> AMER
                        </a></li>
                        <li><a class="dropdown-item" href="regional_view.php?region=ANZ">
                            <i class="ti ti-world me-2"></i> ANZ
                        </a></li>
                        <li><a class="dropdown-item" href="regional_view.php?region=APAC">
                            <i class="ti ti-world me-2"></i> APAC
                        </a></li>
                        <li><a class="dropdown-item" href="regional_view.php?region=BNL">
                            <i class="ti ti-world me-2"></i> BNL
                        </a></li>
                        <li><a class="dropdown-item" href="regional_view.php?region=DACH">
                            <i class="ti ti-world me-2"></i> DACH
                        </a></li>
                        <li><a class="dropdown-item" href="regional_view.php?region=EMEA_PARTNERS">
                            <i class="ti ti-world me-2"></i> EMEA PARTNERS
                        </a></li>
                        <li><a class="dropdown-item" href="regional_view.php?region=FRANCE">
                            <i class="ti ti-world me-2"></i> FRANCE
                        </a></li>
                        <li><a class="dropdown-item" href="regional_view.php?region=INDIA">
                            <i class="ti ti-world me-2"></i> INDIA
                        </a></li>
                        <li><a class="dropdown-item" href="regional_view.php?region=NORD">
                            <i class="ti ti-world me-2"></i> NORD
                        </a></li>
                        <li><a class="dropdown-item" href="regional_view.php?region=UKI">
                            <i class="ti ti-world me-2"></i> UKI
                        </a></li>
                    </ul>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link" href="reports.php">
                        <i class="ti ti-chart-pie me-1"></i>Reports
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link" href="conversion_rates.php">
                        <i class="ti ti-exchange me-1"></i>Conversion Rates
                    </a>
                </li>
                
                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                <li class="nav-item">
                    <a class="nav-link" href="user_management.php">
                        <i class="ti ti-users me-1"></i>User Management
                    </a>
                </li>
                <?php endif; ?>
            </ul>
            
            <ul class="navbar-nav">
                <?php if (isset($_SESSION['user_id'])): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle user-dropdown-btn" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="ti ti-user me-1"></i><?= htmlspecialchars($_SESSION['display_name'] ?? 'User') ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text">
                            <small class="text-muted">Role: <?= htmlspecialchars($_SESSION['role'] ?? 'Unknown') ?></small>
                        </span></li>
                        <li><span class="dropdown-item-text">
                            <small class="text-muted">Region: <?= !empty($_SESSION['region']) ? htmlspecialchars($_SESSION['region']) : 'All Regions' ?></small>
                        </span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="logout.php">
                            <i class="ti ti-logout me-2"></i>Logout
                        </a></li>
                    </ul>
                </li>
                <?php else: ?>
                <li class="nav-item">
                    <a class="nav-link" href="login.php">
                        <i class="ti ti-login me-1"></i>Login
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<!-- Tabler JavaScript -->
<script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/js/tabler.min.js"></script>
<!-- Bootstrap Bundle (Tabler depends on it) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>