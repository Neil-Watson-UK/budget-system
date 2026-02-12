<?php
// init_budgets.php - Initialize budgets from config
require_once 'config.php';
require_once 'functions.php';

$pdo = getDBConnection();

echo "<h2>Initializing Budgets from Config</h2>";

global $REGIONAL_SETTINGS;

foreach ($REGIONAL_SETTINGS as $region => $settings) {
    $budget = $settings['budget_limit'] ?? 0;
    $currency = $settings['currency'] ?? 'EUR';
    
    // Initialize for 2026 (from your config)
    $stmt = $pdo->prepare("
        INSERT INTO region_budgets (region, year, budget_amount, currency) 
        VALUES (?, 2026, ?, ?)
        ON DUPLICATE KEY UPDATE 
        budget_amount = VALUES(budget_amount),
        currency = VALUES(currency)
    ");
    
    try {
        $stmt->execute([$region, $budget, $currency]);
        echo "✓ $region: " . formatCurrency($budget, $currency) . "<br>";
    } catch (PDOException $e) {
        echo "✗ $region: Error - " . $e->getMessage() . "<br>";
    }
}

echo "<h3>Done!</h3>";
echo "<a href='budget_manager.php'>Go to Budget Manager</a>";
?>