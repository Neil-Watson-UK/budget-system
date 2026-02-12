<?php
// api/save_email.php

// This one line gives us the session, security, config, AND database connection.
require_once __DIR__ . '/../includes/init.php';
session_start();
header('Content-Type: application/json; charset=utf-8'); // Ensures UTF-8 and correct media type

// Security headers - prevent caching for dynamic content and sniffing
header('Cache-Control: no-cache, no-store, max-age=0, must-revalidate'); // Modern cache control
header('X-Content-Type-Options: nosniff'); // Prevent MIME type sniffing

// Check if the user is NOT logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

$response = ['success' => false, 'message' => 'An unknown error occurred.'];
$data = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    $response['message'] = 'Invalid JSON input.';
    echo json_encode($response);
    exit;
}

// Ensure required data is present
if (!isset($data['referenceCode']) || !isset($data['createdBy'])) {
    http_response_code(400);
    $response['message'] = 'Missing referenceCode or createdBy in request.';
    echo json_encode($response);
    exit;
}

$referenceCodeToDelete = trim($data['referenceCode']);
$createdByOfEmail = trim($data['createdBy']); // This is the 'createdBy' name from the email metadata
$loggedInUsername = $_SESSION['username'] ?? ''; // Get logged-in username from session
$loggedInUserLevel = $_SESSION['user_level'] ?? 'user'; // Get logged-in user level from session

$metadataFile = 'email_metadata.json';
$emailTemplatesDir = __DIR__ . '/emails/'; // Directory where HTML email files are stored

// Ensure the emails directory exists
if (!is_dir($emailTemplatesDir)) {
    // If the directory doesn't exist, it means no emails were saved, so nothing to delete.
    $response['message'] = 'Email templates directory does not exist. No emails to delete.';
    echo json_encode($response);
    exit;
}

// 1. Load existing metadata
if (!file_exists($metadataFile)) {
    $response['message'] = 'Email metadata file not found. No emails to delete.';
    echo json_encode($response);
    exit;
}

$allMetadata = json_decode(file_get_contents($metadataFile), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(500);
    $response['message'] = 'Failed to decode email metadata. Ensure email_metadata.json is valid JSON.';
    echo json_encode($response);
    exit;
}

$initialCount = count($allMetadata);
$updatedMetadata = [];
$emailDeleted = false;
$htmlFileDeleted = false;

foreach ($allMetadata as $entry) {
    // Check if it's the email to delete
    if (isset($entry['referenceCode']) && $entry['referenceCode'] === $referenceCodeToDelete) {
        // Authorization check: Admin can delete any. Creator can delete their own.
        // The 'createdBy' in $entry refers to the author who saved the email.
        if ($loggedInUserLevel === 'admin' || $loggedInUsername === ($entry['createdBy'] ?? $entry['created_by_name'] ?? '')) {
            // This is the email to delete, so skip adding it to $updatedMetadata
            $emailDeleted = true;

            // Attempt to delete the associated HTML file
            $htmlFilePath = $emailTemplatesDir . $referenceCodeToDelete . '.html';
            if (file_exists($htmlFilePath)) {
                if (unlink($htmlFilePath)) {
                    $htmlFileDeleted = true;
                } else {
                    $response['message'] = 'Failed to delete associated HTML file.';
                    // Don't exit yet, still save metadata if possible
                }
            } else {
                // If HTML file doesn't exist, it might have been deleted manually or never created.
                // Consider it 'deleted' for metadata purposes or log a warning.
                $htmlFileDeleted = true; // Still proceed with metadata deletion
            }
        } else {
            // Not authorized, keep this entry
            $updatedMetadata[] = $entry;
        }
    } else {
        // Not the email to delete, keep this entry
        $updatedMetadata[] = $entry;
    }
}

if ($emailDeleted) {
    // Save updated metadata back to file
    if (file_put_contents($metadataFile, json_encode($updatedMetadata, JSON_PRETTY_PRINT))) {
        $response['success'] = true;
        $response['message'] = 'Email and metadata deleted successfully.';
        if (!$htmlFileDeleted) {
             $response['message'] .= ' (Note: Associated HTML file was not found or could not be deleted.)';
        }
    } else {
        http_response_code(500);
        $response['message'] = 'Failed to save updated email metadata after deletion attempt.';
    }
} else {
    // If emailDeleted is false, it means either:
    // 1. The referenceCode was not found.
    // 2. The user was not authorized to delete it.
    if ($initialCount === count($updatedMetadata)) { // No change in count means not found or not authorized
        if ($loggedInUserLevel === 'admin' || $loggedInUsername === $createdByOfEmail) {
            // User is authorized for this specific email (or admin), but it wasn't found
            $response['message'] = 'Email with provided reference code not found.';
        } else {
            // User is not authorized for this specific email
            http_response_code(403);
            $response['message'] = 'You are not authorized to delete this email.';
        }
    }
}

echo json_encode($response);
