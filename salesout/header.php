<?php
// salesout/header.php - Nav for Sales Out
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!defined('SALESOUT_APP_NAME')) define('SALESOUT_APP_NAME', 'Sales Out Report');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= defined('SALESOUT_APP_NAME') ? SALESOUT_APP_NAME : 'Sales Out' ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.40.0/tabler-icons.min.css">
</head>
<body>
    <div class="page-wrapper">
        <header class="navbar navbar-expand-md navbar-light d-print-none">
            <div class="container-xl">
                <a href="index.php" class="navbar-brand"><?= SALESOUT_APP_NAME ?></a>
                <div class="navbar-nav flex-row order-md-last">
                    <a href="../index.php" class="nav-link">Budget System</a>
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <span class="nav-link"><?= htmlspecialchars($_SESSION['username'] ?? 'User') ?></span>
                    <?php endif; ?>
                </div>
                <div class="collapse navbar-collapse">
                    <ul class="navbar-nav">
                        <li class="nav-item"><a href="index.php" class="nav-link">Dashboard</a></li>
                        <li class="nav-item"><a href="insights.php" class="nav-link">Insights</a></li>
                        <li class="nav-item"><a href="import.php" class="nav-link">Import</a></li>
                        <li class="nav-item"><a href="export.php" class="nav-link">Export Excel</a></li>
                        <li class="nav-item"><a href="products.php" class="nav-link">Products</a></li>
                        <li class="nav-item"><a href="mapping.php" class="nav-link">Reseller Mapping</a></li>
                    </ul>
                </div>
            </div>
        </header>
        <div class="page-body" style="padding: 1.5rem;">
