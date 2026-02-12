<?php
// final_test.php
require_once 'config.php';
require_once 'functions.php';

echo "<h2>Final System Test</h2>";

// Test 1: Check if functions exist
echo "<h3>1. Function Check:</h3>";
echo function_exists('getRegionalBudgetFromDB') ? "✅ getRegionalBudgetFromDB exists<br>" : "❌ Missing getRegionalBudgetFromDB<br>";
echo function_exists('getRegionalBudgetLimit') ? "✅ getRegionalBudgetLimit exists<br>" : "❌ Missing getRegionalBudgetLimit<br>";
echo function_exists('getRegionalSummary') ? "✅ getRegionalSummary exists<br>" : "❌ Missing getRegionalSummary<br>";

// Test 2: Test database connection
try {
    $pdo = getDBConnection();
    echo "✅ Database connection successful<br>";
    
    // Test 3: Direct database query
    echo "<h3>2. Database Budget Check:</h3>";
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM region_budgets");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Budget records in database: " . $result['count'] . "<br>";
    
    // Test 4: Test getRegionalBudgetFromDB
    echo "<h3>3. getRegionalBudgetFromDB Test:</h3>";
    $budget_info = getRegionalBudgetFromDB($pdo, 'AMER', 2026);
    echo "AMER 2026: " . ($budget_info['budget_limit'] ?? 'ERROR') . " " . ($budget_info['currency'] ?? 'ERROR') . "<br>";
    echo ($budget_info['budget_limit'] == 600090) ? "✅ Correct amount" : "❌ Wrong amount";
    
    // Test 5: Test getRegionalBudgetLimit (backward compatibility)
    echo "<h3>4. getRegionalBudgetLimit Test:</h3>";
    $limit_old = getRegionalBudgetLimit('AMER'); // Old way
    $limit_new = getRegionalBudgetLimit('AMER', 2026); // New way
    echo "Old way (no year): " . $limit_old . "<br>";
    echo "New way (with year): " . $limit_new . "<br>";
    echo ($limit_new == 600090) ? "✅ Both work" : "❌ Issue with backward compatibility";
    
    // Test 6: Test getRegionalSummary
    echo "<h3>5. getRegionalSummary Test:</h3>";
    $summary = getRegionalSummary($pdo, 'AMER', 2026);
    echo "Budget in summary: " . ($summary['budget_limit'] ?? 'NOT FOUND') . "<br>";
    echo ($summary['budget_limit'] == 600090) ? "✅ Summary uses database budget" : "❌ Summary using wrong budget";
    
} catch (Exception $e) {
    echo "❌ Database Error: " . $e->getMessage();
}
?>