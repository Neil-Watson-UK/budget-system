<?php
// api/save_email.php

// This one line gives us the session, security, config, AND database connection.
require_once __DIR__ . '/../includes/init.php';
// IMPORTANT: Ensure there are NO characters (including whitespace/newlines) before this opening <?php tag.
// Also, ensure the file is saved without a Byte Order Mark (BOM) if your editor provides that option.

// Turn off display of errors to prevent HTML output in JSON response
ini_set('display_errors', 0);
// Ensure errors are logged to the PHP error log
ini_set('log_errors', 1);
// You can optionally specify a custom error log file if needed, e.g.:
// ini_set('error_log', '/path/to/your/php_error.log');

session_start();
header('Content-Type: application/json');

// Start output buffering to catch any stray output (e.g., PHP warnings, whitespace)
// Note: Output BEFORE this ob_start() or before the opening <?php tag cannot be buffered.
ob_start();

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    // Clean any buffered output before sending JSON
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Not logged in.']);
    exit;
}

$userId = $_SESSION['user_id'] ?? null;
$userLevel = $_SESSION['user_level'] ?? 'user';
$loggedInUserName = $_SESSION['name'] ?? null; // Get logged-in username for filtering

if (!$userId || !$loggedInUserName) {
    // Clean any buffered output before sending JSON
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'User session data incomplete.']);
    exit;
}

$emails = [];
$metadataFile = 'email_metadata.json'; // Path to your metadata file

if (!file_exists($metadataFile)) {
    // Clean any buffered output before sending JSON
    ob_clean();
    echo json_encode(['success' => true, 'emails' => []]); // No emails if metadata file doesn't exist
    exit;
}

$metadataContent = file_get_contents($metadataFile);
$allEmailsMetadata = json_decode($metadataContent, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    // Clean any buffered output before sending JSON
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Error reading email metadata.json: ' . json_last_error_msg()]);
    exit;
}

// Filter emails based on user level
if ($userLevel === 'admin') {
    // Admins get all emails from metadata
    $emails = $allEmailsMetadata;
} else {
    // Regular users get only emails where 'createdBy' matches their username
    foreach ($allEmailsMetadata as $email) {
        // Ensure 'createdBy' field exists in the metadata entry
        if (isset($email['createdBy']) && $email['createdBy'] === $loggedInUserName) {
            $emails[] = $email;
        }
    }
}

// Sort emails by sendTime as expected by the frontend
usort($emails, function($a, $b) {
    $timeA = strtotime($a['sendTime'] ?? '1970-01-01');
    $timeB = strtotime($b['sendTime'] ?? '1970-01-01');
    return $timeA - $timeB;
});

// Clean any buffered output before sending JSON
ob_clean();
echo json_encode(['success' => true, 'emails' => $emails]);
