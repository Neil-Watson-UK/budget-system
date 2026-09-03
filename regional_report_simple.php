<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Minimal version that works
$selected_region = $_GET['region'] ?? 'AMER';
$regional_items = [];
$regional_spent = 50000; // Example data
$budget_limit = 100000; // Example data
$currency_symbol = '$';

// Calculate
$remaining_budget = $budget_limit - $regional_spent;
$usage_percentage = ($regional_spent / $budget_limit) * 100;
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Regional Report - <?= $selected_region ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-4">
        <h1><?= $selected_region ?> Regional Report</h1>
        
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center p-3">
                    <div class="h3 text-primary"><?= $currency_symbol ?><?= number_format($budget_limit, 2) ?></div>
                    <div class="text-muted">Total Budget</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center p-3">
                    <div class="h3 text-success"><?= $currency_symbol ?><?= number_format($regional_spent, 2) ?></div>
                    <div class="text-muted">Amount Spent</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center p-3">
                    <div class="h3 text-info"><?= $currency_symbol ?><?= number_format($remaining_budget, 2) ?></div>
                    <div class="text-muted">Remaining</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center p-3">
                    <div class="h3 text-secondary"><?= count($regional_items) ?></div>
                    <div class="text-muted">Total Items</div>
                </div>
            </div>
        </div>
        
        <div class="alert alert-info">
            This is the simple working version. Budget: <?= $currency_symbol ?><?= number_format($budget_limit, 2) ?> | 
            Spent: <?= $currency_symbol ?><?= number_format($regional_spent, 2) ?> | 
            Remaining: <?= $currency_symbol ?><?= number_format($remaining_budget, 2) ?>
        </div>
    </div>
</body>
</html>