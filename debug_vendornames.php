<?php
// debug_import.php - Debug CSV import
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'cmmbudget');
define('DB_USER', 'budgetadmin');
define('DB_PASS', 'NotReevesP13453');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", 
        DB_USER, 
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    
    echo "<h2>Debug CSV Import</h2>";
    
    if ($_FILES['debug_csv']['error'] === UPLOAD_ERR_OK) {
        $csvData = file_get_contents($_FILES['debug_csv']['tmp_name']);
        $lines = explode(PHP_EOL, $csvData);
        
        echo "Total lines in CSV: " . count($lines) . "<br>";
        
        // Get headers
        $headers = str_getcsv(array_shift($lines));
        echo "<h3>CSV Headers Found:</h3>";
        echo "<pre>" . print_r($headers, true) . "</pre>";
        
        // Show first few rows
        echo "<h3>First 5 Rows:</h3>";
        for ($i = 0; $i < min(5, count($lines)); $i++) {
            if (!empty(trim($lines[$i]))) {
                $row = str_getcsv($lines[$i]);
                echo "<pre>Row " . ($i+1) . ": " . print_r($row, true) . "</pre>";
            }
        }
        
        // Check what columns exist in database
        echo "<h3>Database Table Structure:</h3>";
        $columns = $pdo->query("DESCRIBE vendors")->fetchAll();
        echo "<pre>" . print_r($columns, true) . "</pre>";
        
    } else {
        echo "Please upload a CSV file";
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Debug CSV Import</title>
</head>
<body>
    <form method="post" enctype="multipart/form-data">
        <input type="file" name="debug_csv" accept=".csv">
        <button type="submit">Debug CSV</button>
    </form>
</body>
</html>