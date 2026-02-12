<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

$pdo = getDBConnection();
$global_summary = getGlobalSummary($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Global Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/style.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark glass-nav fixed-top">
        <div class="container">
            <a class="navbar-brand fw-bold" href="#">
                <i class="fas fa-globe-americas me-2"></i><?= APP_NAME ?> <?= FISCAL_YEAR ?>
            </a>
            <div class="navbar-nav ms-auto">
                <a href="regional_view.php" class="nav-link glass-btn me-2">
                    <i class="fas fa-map me-1"></i>Regional Views
                </a>
                <a href="reports.php" class="nav-link glass-btn me-2">
                    <i class="fas fa-chart-pie me-1"></i>Reports
                </a>
                <a href="export.php" class="nav-link glass-btn">
                    <i class="fas fa-download me-1"></i>Export
                </a>
            </div>
        </div>
    </nav>

    <div class="container mt-5 pt-5">
        <!-- Global Summary Cards -->
        <div class="row mb-4 fade-in">
            <?php 
            $total_budget = array_sum(array_column($global_summary, 'budget_limit'));
            $total_spent = array_sum(array_column($global_summary, 'spent'));
            $utilization = $total_budget > 0 ? ($total_spent / $total_budget) * 100 : 0;
            ?>
            <div class="col-md-3 mb-3">
                <div class="glass-card stat-card primary">
                    <h4><?= formatCurrency($total_budget, 'EUR') ?></h4>
                    <h6>Total Budget</h6>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="glass-card stat-card success">
                    <h4><?= formatCurrency($total_spent, 'EUR') ?></h4>
                    <h6>Total Spent</h6>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="glass-card stat-card warning">
                    <h4><?= formatCurrency($total_budget - $total_spent, 'EUR') ?></h4>
                    <h6>Remaining</h6>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="glass-card stat-card info">
                    <h4><?= array_sum(array_column($global_summary, 'total_items')) ?></h4>
                    <h6>Total Items</h6>
                </div>
            </div>
        </div>

        <!-- Global Utilization -->
        <div class="glass-card mb-4 fade-in">
            <div class="card-body">
                <h5 class="card-title mb-3">
                    <i class="fas fa-chart-line me-2"></i>Global Budget Utilization
                </h5>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span>Overall Utilization</span>
                    <span class="fw-bold"><?= round($utilization, 1) ?>%</span>
                </div>
                <div class="glass-progress mb-4">
                    <div class="progress-bar" style="width: <?= $utilization ?>%"></div>
                </div>
            </div>
        </div>

        <!-- Regional Utilization -->
        <div class="glass-card mb-4 fade-in">
            <div class="card-body">
                <h5 class="card-title mb-4">
                    <i class="fas fa-chart-bar me-2"></i>Regional Budget Utilization
                </h5>
                <?php foreach ($global_summary as $region => $data): 
                    if ($data['budget_limit'] > 0):
                    $utilization = ($data['spent'] / $data['budget_limit']) * 100;
                    $color = $utilization > 90 ? 'danger' : ($utilization > 75 ? 'warning' : 'success');
                ?>
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div>
                            <span class="fw-bold"><?= $region ?></span>
                            <span class="region-badge ms-2" style="background-color: <?= getRegionalColor($region) ?>">
                                <?= $REGIONAL_SETTINGS[$region]['currency'] ?>
                            </span>
                        </div>
                        <span class="fw-bold"><?= round($utilization, 1) ?>%</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <small><?= formatCurrency($data['spent'], $REGIONAL_SETTINGS[$region]['currency']) ?></small>
                        <small><?= formatCurrency($data['budget_limit'], $REGIONAL_SETTINGS[$region]['currency']) ?></small>
                    </div>
                    <div class="glass-progress">
                        <div class="progress-bar bg-<?= $color ?>" style="width: <?= $utilization ?>%"></div>
                    </div>
                </div>
                <?php endif; endforeach; ?>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="glass-card mb-4 fade-in">
            <div class="card-body">
                <h5 class="card-title mb-3">
                    <i class="fas fa-bolt me-2"></i>Quick Actions
                </h5>
                <div class="d-flex flex-wrap gap-2">
    <!-- Simple Add Budget Item Button - Goes directly to form -->
    <a href="add_item.php" class="btn btn-success glass-btn">
        <i class="fas fa-plus me-2"></i>Add Budget Item
    </a>
    
    <a href="regional_view.php" class="btn btn-primary glass-btn">
        <i class="fas fa-map me-2"></i>Regional Views
    </a>
    
    <a href="form_manager.php" class="btn btn-secondary glass-btn">
        <i class="fas fa-cog me-2"></i>Form Settings
    </a>
    
    <a href="export.php" class="btn btn-info glass-btn">
        <i class="fas fa-download me-2"></i>Export Data
    </a>
    
    <a href="reports.php" class="btn btn-warning glass-btn">
        <i class="fas fa-chart-pie me-2"></i>Analytics
    </a>
</div>
            </div>
        </div>

        <!-- Recent Items -->
        <div class="glass-card fade-in">
            <div class="card-body">
                <h5 class="card-title mb-4">
                    <i class="fas fa-list me-2"></i>Recent Budget Items
                </h5>
                <div class="table-responsive">
                    <table class="table glass-table">
                        <thead>
                            <tr>
                                <th>PO Number</th>
                                <th>Region</th>
                                <th>Activity Title</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Vendor</th>
                                <th>Start Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $stmt = $pdo->query("
                                SELECT * FROM budget_items 
                                ORDER BY entry_creation_date DESC 
                                LIMIT 10
                            ");
                            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)):
                            ?>
                            <tr>
                                <td><strong class="text-white"><?= htmlspecialchars($row['po_number']) ?></strong></td>
                                <td>
                                    <span class="region-badge" style="background-color: <?= getRegionalColor($row['region']) ?>">
                                        <?= $row['region'] ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($row['activity_title']) ?></td>
                                <td class="fw-bold"><?= formatCurrency($row['amount_requested'], $row['currency']) ?></td>
                                <td><?= getStatusBadge($row['status']) ?></td>
                                <td><?= htmlspecialchars($row['vendor']) ?></td>
                                <td><?= $row['start_date'] ?></td>
                                <td>
                                    <div class="btn-group">
                                        <a href="edit_item.php?id=<?= $row['id'] ?>" class="btn btn-sm glass-btn">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="delete_item.php?id=<?= $row['id'] ?>" class="btn btn-sm glass-btn" onclick="return confirm('Delete this item?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Add subtle animations
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.glass-card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
            });
        });
    </script>
</body>
</html>