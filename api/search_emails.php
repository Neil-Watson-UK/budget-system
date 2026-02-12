<?php
// api/search_emails.php

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

// Ensure absolutely no whitespace or characters before the opening <?php tag of this file.
// This header MUST be sent before any other output (including PHP Notices/Warnings).
// The require_once init.php should handle session_start() and authentication pre-checks.
header('Content-Type: application/json; charset=utf-8');

// The session should already be active from init.php, so no need for session_start() here.
// session_start(); // REMOVED: Redundant and causes "headers already sent" warning.

// Security headers - prevent caching for dynamic content and sniffing
header('Cache-Control: no-cache, no-store, max-age=0, must-revalidate'); // Modern cache control
header('X-Content-Type-Options: nosniff'); // Prevent MIME type sniffing

// Note: To remove 'X-Powered-By' header, you typically need to configure your web server (e.g., Apache's httpd.conf or Nginx configuration)
// It cannot be reliably removed via PHP's header() function if the server adds it after PHP execution.

// The security check below is technically redundant if init.php already handled it for AJAX requests,
// but it acts as a safeguard if init.php's behavior changes or for non-AJAX direct access.
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    // init.php should ideally catch this first and exit, but this is a fail-safe.
    // If headers have already been sent by init.php (e.g., for a browser redirect),
    // this header modification will fail, but the output will already be non-JSON.
    // The main goal is to prevent initial PHP notices from causing header issues.
    http_response_code(403);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$response = [];
$data = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON input.']);
    exit;
}

// IMPORTANT: Using 'email_metadata.json' for searching emails.
// Please ensure this path is correct for your setup.
$metadataFile = 'email_metadata.json'; // Path to your metadata file

if (!file_exists($metadataFile)) {
    // If the metadata file doesn't exist, it means no emails have been saved yet.
    // Return an empty array indicating no search results, but successful operation.
    // Ensure the response is always a JSON object or array as expected by the frontend.
    echo json_encode([]); // Changed to an empty JSON array if no data, as the frontend might expect this.
    exit;
}

$allMetadata = json_decode(file_get_contents($metadataFile), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(500);
    error_log("Failed to decode email metadata: " . json_last_error_msg()); // Log for server-side debugging
    echo json_encode(['error' => 'Failed to decode email metadata. Ensure email_metadata.json is valid JSON.']);
    exit;
}

$searchResults = [];

foreach ($allMetadata as $entry) {
    $match = true;

    // Filter by Subject Line (case-insensitive, partial match)
    if (!empty($data['subjectLine'])) {
        if (!isset($entry['subjectLine']) || stripos($entry['subjectLine'], $data['subjectLine']) === false) {
             $match = false;
        }
    }

    // Filter by Reference Code (case-insensitive, partial match)
    if ($match && !empty($data['referenceCode'])) {
        if (!isset($entry['referenceCode']) || stripos($entry['referenceCode'], $data['referenceCode']) === false) {
            $match = false;
        }
    }

    // Filter by Author (case-insensitive, partial match)
    // Assuming 'createdBy' or 'created_by_name' field in metadata for author
    if ($match && !empty($data['author'])) {
        $authorField = $entry['created_by_name'] ?? $entry['createdBy'] ?? ''; // Check both possible keys
        if (stripos($authorField, $data['author']) === false) {
            $match = false;
        }
    }

    // Filter by Sender Email (case-insensitive, partial match)
    if ($match && !empty($data['senderEmail'])) {
        if (!isset($entry['senderEmail']) || stripos($entry['senderEmail'], $data['senderEmail']) === false) {
             $match = false;
        }
    }

    // Filter by Region (case-insensitive, exact match)
    if ($match && !empty($data['region'])) {
        if (!isset($entry['region']) || strtolower($entry['region']) !== strtolower($data['region'])) {
            $match = false;
        }
    }

    if ($match) {
        // Add created_by_name if not present, useful for display
        $entry['created_by_name'] = $entry['created_by_name'] ?? ($entry['createdBy'] ?? 'N/A');
        $searchResults[] = $entry;
    }
}

// Sort results by sendTime (or 'date' if available) in descending order (newest to oldest)
usort($searchResults, function($a, $b) {
    // Prefer 'sendTime', fallback to 'date' for older entries
    $timeA = isset($a['sendTime']) && !empty($a['sendTime']) ? strtotime($a['sendTime']) : 0;
    $timeB = isset($b['sendTime']) && !empty($b['sendTime']) ? strtotime($b['sendTime']) : 0;

    // If sendTime is not available, try 'date'
    if ($timeA === 0 && isset($a['date']) && !empty($a['date'])) {
        $timeA = strtotime($a['date']);
    }
    if ($timeB === 0 && isset($b['date']) && !empty($b['date'])) {
        $timeB = strtotime($b['date']);
    }

    return $timeB <=> $timeA; // Descending order (newest first)
});

// Return the filtered and sorted results as a JSON array
echo json_encode($searchResults);
