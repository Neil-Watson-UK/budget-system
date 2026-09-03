<?php
// salesout/install.php - Run database schema
session_start();
require_once dirname(__DIR__) . '/config.php';

// Simple auth check - only run if logged into budget system
if (!isset($_SESSION['user_id'])) {
    die('Please log in to the budget system first, then run install.');
}

$pdo = getDBConnection();

$sql = file_get_contents(__DIR__ . '/schema.sql');

try {
    // Execute each statement (split by semicolon, skip comments)
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        fn($s) => !empty($s) && !preg_match('/^--/', $s)
    );
    
    foreach ($statements as $statement) {
        if (!empty(trim($statement))) {
            $pdo->exec($statement);
        }
    }
    $message = 'Schema installed successfully.';
    $success = true;
} catch (PDOException $e) {
    $message = 'Error: ' . $e->getMessage();
    $success = false;
}
?>
<!DOCTYPE html>
<html>
<head><title>Sales Out - Install</title></head>
<body>
    <p><?= $success ? '&#10003;' : '&#10007;' ?> <?= htmlspecialchars($message) ?></p>
    <p><a href="index.php">Back to Sales Out</a></p>
</body>
</html>
