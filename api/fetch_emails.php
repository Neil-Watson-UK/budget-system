<?php
// api/fetch_emails.php

// This one line gives us the session, security, config, AND database connection.
require_once __DIR__ . '/../includes/init.php';

// IMPORTANT: After init.php (which includes db_connect.php), check the global connection status.
// If the database connection failed in db_connect.php, init.php itself or this script
// should gracefully exit with a JSON error, preventing HTML output.
global $db_connection_success; // Access the global variable set in db_connect.php
global $mysqli; // Access the global mysqli object (if needed for direct queries later in the script)

// If init.php has already handled authentication and exited (e.g., 403 Forbidden for AJAX),
// this code won't execute. If it passed, we can now confidently set headers.
// Check database connection before proceeding with any data operations.
if (!$db_connection_success || $mysqli === null) {
    http_response_code(500); // Internal Server Error
    echo json_encode(['success' => false, 'message' => 'Database connection failed. Please try again later.']);
    exit; // Terminate script execution
}

// This header MUST be sent before any other output (including PHP Notices/Warnings).
// The require_once init.php should handle session_start() and authentication pre-checks.
header('Content-Type: application/json; charset=utf-8');

// The session should already be active from init.php, so no need for session_start() here.
// session_start(); // REMOVED: Redundant and causes "headers already sent" warning.


// Check if the user is NOT logged in. This should generally happen after headers are set.
// This check is a fail-safe; init.php should already handle authentication for API calls.
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(403);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$response = ['success' => false, 'message' => '', 'emails' => []];
$metadataFile = 'email_metadata.json'; // Path to your metadata file
$emailsDir = 'emails/'; // Directory where full HTML content and JSON data are stored

// Check if metadata file exists
if (!file_exists($metadataFile)) {
    $response['message'] = 'No email data available.';
    echo json_encode($response);
    exit;
}

$allMetadata = json_decode(file_get_contents($metadataFile), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    // Log the JSON error for debugging, but don't output it directly to the user if sensitive.
    error_log("Failed to decode email metadata: " . json_last_error_msg());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to decode email metadata. Please check the file format.']);
    exit;
}

// Handle request to load a specific email's full structured content
if (isset($_GET['loadCode'])) {
    $loadCode = $_GET['loadCode'];
    // Sanitize the loadCode to prevent directory traversal or other file system attacks.
    // Only allow alphanumeric characters, hyphens, and underscores.
    $safeLoadCode = preg_replace('/[^a-zA-Z0-9_-]/', '', $loadCode);
    $emailDataFilePath = $emailsDir . $safeLoadCode . '.json'; // Path for structured JSON data

    if (file_exists($emailDataFilePath)) {
        $fullStructuredData = json_decode(file_get_contents($emailDataFilePath), true);

        if (json_last_error() === JSON_ERROR_NONE) {
            $response['success'] = true;
            $response['message'] = 'Email structured data loaded.';
            $response['emailData'] = $fullStructuredData; // Return the full structured JSON data
        } else {
            // Error parsing the specific email's JSON data
            error_log("Error parsing email structured data for {$safeLoadCode}.json: " . json_last_error_msg());
            $response['message'] = 'Error parsing email structured data.';
        }
    } else {
        $response['message'] = 'Email structured data file not found.';
    }
    echo json_encode($response);
    exit;
}

// Handle search query (this block is executed if 'loadCode' is NOT set)
if (isset($_GET['code']) || isset($_GET['subject'])) {
    $searchCode = $_GET['code'] ?? '';
    $searchSubject = $_GET['subject'] ?? '';

    $filteredEmails = [];
    foreach ($allMetadata as $email) {
        $matchCode = empty($searchCode) || (isset($email['referenceCode']) && strcasecmp($email['referenceCode'], $searchCode) === 0);
        $matchSubject = empty($searchSubject) || (isset($email['subjectLine']) && stripos($email['subjectLine'], $searchSubject) !== false);

        if ($matchCode && $matchSubject) {
            $filteredEmails[] = [
                'referenceCode' => $email['referenceCode'],
                'subjectLine' => $email['subjectLine'] ?? 'N/A',
                'createdBy' => $email['createdBy'] ?? 'N/A', // Include createdBy
                'senderEmail' => $email['senderEmail'] ?? 'N/A', // Include senderEmail
                'sendTime' => $email['sendTime'] ?? 'N/A', // Include sendTime
                'region' => $email['region'] ?? 'N/A', // Include region
            ];
        }
    }
    $response['success'] = true;
    $response['emails'] = $filteredEmails;
    $response['message'] = 'Search results retrieved.';
    echo json_encode($response);
    exit;
}

// If no specific action (loadCode or search params) is requested, return all emails metadata
$response['success'] = true;
$response['emails'] = array_map(function($email) {
    return [
        'referenceCode' => $email['referenceCode'],
        'subjectLine' => $email['subjectLine'] ?? 'N/A',
        'createdBy' => $email['createdBy'] ?? 'N/A',
        'senderEmail' => $email['senderEmail'] ?? 'N/A',
        'sendTime' => $email['sendTime'] ?? 'N/A',
        'region' => $email['region'] ?? 'N/A',
    ];
}, $allMetadata);
$response['message'] = 'All email metadata retrieved.';
echo json_encode($response);
exit;
