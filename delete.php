<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

// Simple error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Log the request
error_log("=== DELETE REQUEST ===");
error_log("Time: " . date('Y-m-d H:i:s'));
error_log("Method: " . $_SERVER['REQUEST_METHOD']);

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_log("ERROR: Wrong method - expected POST, got " . $_SERVER['REQUEST_METHOD']);
    header("Location: index.php?error=Please+use+form+to+delete");
    exit;
}

// Get and validate ID
$item_id = intval($_POST['id'] ?? 0);
error_log("Item ID from POST: $item_id");

if ($item_id <= 0) {
    error_log("ERROR: Invalid item ID");
    header("Location: index.php?error=Invalid+item+ID");
    exit;
}

// CSRF check
$session_token = $_SESSION['csrf_token'] ?? '';
$post_token = $_POST['csrf_token'] ?? '';

error_log("CSRF - Session: $session_token");
error_log("CSRF - POST: $post_token");

if (empty($session_token) || empty($post_token)) {
    error_log("ERROR: Missing CSRF token");
    header("Location: index.php?error=Security+token+missing");
    exit;
}

if ($session_token !== $post_token) {
    error_log("ERROR: CSRF token mismatch");
    header("Location: index.php?error=Security+token+mismatch");
    exit;
}

error_log("CSRF check passed");

// Proceed with deletion
try {
    $pdo = getDBConnection();
    
    // First, get item info for logging
    $stmt = $pdo->prepare("SELECT po_number, activity_title FROM budget_items WHERE id = ?");
    $stmt->execute([$item_id]);
    $item = $stmt->fetch();
    
    if (!$item) {
        error_log("ERROR: Item not found in database");
        header("Location: index.php?error=Item+not+found");
        exit;
    }
    
    error_log("Found item: " . $item['po_number'] . " - " . $item['activity_title']);
    
    // Delete the item
    $delete_stmt = $pdo->prepare("DELETE FROM budget_items WHERE id = ?");
    $result = $delete_stmt->execute([$item_id]);
    $rows_affected = $delete_stmt->rowCount();
    
    error_log("Delete executed: " . ($result ? 'success' : 'failed'));
    error_log("Rows affected: $rows_affected");
    
    if ($rows_affected > 0) {
        error_log("SUCCESS: Item deleted");
        header("Location: index.php?success=Item+deleted+successfully");
    } else {
        error_log("ERROR: No rows affected (item may have already been deleted)");
        header("Location: index.php?error=Could+not+delete+item");
    }
    
} catch (Exception $e) {
    error_log("EXCEPTION: " . $e->getMessage());
    header("Location: index.php?error=Database+error:+". urlencode($e->getMessage()));
}

exit;