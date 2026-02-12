<?php
// salesout/debug.php - Diagnose 500 errors. DELETE THIS FILE after fixing.
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

echo "<h1>Sales Out Debug</h1>";
echo "<p>If you see this, PHP is working.</p>";

echo "<h2>Step 1: Config</h2>";
try {
    require_once __DIR__ . '/config.php';
    echo "✓ Config loaded. DB: " . DB_NAME . "<br>";
} catch (Throwable $e) {
    echo "✗ Config error: " . $e->getMessage() . "<br>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
    exit;
}

echo "<h2>Step 2: Database</h2>";
try {
    $pdo = getDBConnection();
    echo "✓ Connected to database<br>";
} catch (Throwable $e) {
    echo "✗ DB error: " . $e->getMessage() . "<br>";
    exit;
}

echo "<h2>Step 3: Tables</h2>";
$tables = ['sales_out_raw', 'sales_out_products', 'sales_out_reseller_mapping', 'sales_out_imports'];
foreach ($tables as $t) {
    try {
        $pdo->query("SELECT 1 FROM $t LIMIT 1");
        echo "✓ $t exists<br>";
    } catch (Throwable $e) {
        echo "✗ $t: " . $e->getMessage() . "<br>";
    }
}

echo "<h2>Step 4: Session</h2>";
session_start();
echo "Session ID: " . session_id() . "<br>";
echo "user_id: " . ($_SESSION['user_id'] ?? 'not set') . "<br>";

echo "<h2>Step 5: Functions</h2>";
try {
    require_once __DIR__ . '/functions.php';
    echo "✓ Functions loaded<br>";
} catch (Throwable $e) {
    echo "✗ Functions: " . $e->getMessage() . "<br>";
}

echo "<hr><p>All checks passed. Try <a href='index.php'>index.php</a></p>";
echo "<p><strong>Delete this file (debug.php) when done!</strong></p>";
