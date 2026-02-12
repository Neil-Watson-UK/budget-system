<?php
// api/save_email.php

// This one line gives us the session, security, config, AND database connection.
require_once __DIR__ . '/../includes/init.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

// Security headers - prevent caching for dynamic content and sniffing
header('Cache-Control: no-cache, no-store, max-age=0, must-revalidate');
header('X-Content-Type-Options: nosniff');

error_log("delete_email.php: Script started.");

// Check if the user is NOT logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    error_log("delete_email.php: Authentication failed. User not logged in.");
    exit;
}

$response = ['success' => false, 'message' => 'An unknown error occurred.'];
$data = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    $response['message'] = 'Invalid JSON input.';
    echo json_encode($response);
    error_log("delete_email.php: Invalid JSON input received. Error: " . json_last_error_msg());
    exit;
}

// Ensure required data is present
if (!isset($data['referenceCode']) || !isset($data['createdBy'])) {
    http_response_code(400);
    $response['message'] = 'Missing referenceCode or createdBy in request. Data received: ' . json_encode($data);
    echo json_encode($response);
    error_log("delete_email.php: Missing required data in request. Data: " . json_encode($data));
    exit;
}

$referenceCodeToDelete = trim($data['referenceCode']);
$createdByOfEmail = strtolower(trim($data['createdBy'])); // Convert to lowercase for consistent comparison
$loggedInUsername = strtolower(trim($_SESSION['username'] ?? '')); // Convert to lowercase for consistent comparison
$loggedInUserLevel = strtolower(trim($_SESSION['user_level'] ?? 'user')); // Convert to lowercase

error_log("delete_email.php: Attempting to delete email with Reference Code: '{$referenceCodeToDelete}' created by '{$createdByOfEmail}'. Logged in user: '{$loggedInUsername}' (Level: '{$loggedInUserLevel}')");

$metadataFile = 'email_metadata.json';
$emailTemplatesDir = __DIR__ . '/emails/'; // Directory where HTML email files are stored

// Ensure the emails directory exists
if (!is_dir($emailTemplatesDir)) {
    $response['message'] = 'Email templates directory does not exist. No emails to delete.';
    echo json_encode($response);
    error_log("delete_email.php: Email templates directory '{$emailTemplatesDir}' does not exist.");
    exit;
}

// 1. Load existing metadata
if (!file_exists($metadataFile)) {
    $response['message'] = 'Email metadata file not found. No emails to delete.';
    echo json_encode($response);
    error_log("delete_email.php: Email metadata file '{$metadataFile}' not found.");
    exit;
}

$allMetadata = json_decode(file_get_contents($metadataFile), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(500);
    $response['message'] = 'Failed to decode email metadata. Ensure email_metadata.json is valid JSON.';
    echo json_encode($response);
    error_log("delete_email.php: Failed to decode email metadata from '{$metadataFile}'. Error: " . json_last_error_msg());
    exit;
}

$initialCount = count($allMetadata);
$updatedMetadata = [];
$emailDeleted = false;
$htmlFileDeleted = false;
$authorizedToDelete = false; // Flag to track if the user is authorized for the *found* email

foreach ($allMetadata as $entry) {
    $entryReferenceCode = trim($entry['referenceCode'] ?? '');
    $entryCreatedBy = strtolower(trim($entry['createdBy'] ?? ($entry['created_by_name'] ?? ''))); // Handle both keys for compatibility

    // Check if it's the email to delete
    if ($entryReferenceCode === $referenceCodeToDelete) {
        error_log("delete_email.php: Found matching email entry in metadata. Created by: '{$entryCreatedBy}'");
        // Authorization check: Admin can delete any. Creator can delete their own.
        if ($loggedInUserLevel === 'admin' || $loggedInUsername === $entryCreatedBy) {
            $authorizedToDelete = true;
            $emailDeleted = true; // Mark for deletion from metadata

            // Attempt to delete the associated HTML file
            $htmlFilePath = $emailTemplatesDir . $referenceCodeToDelete . '.html';
            error_log("delete_email.php: Attempting to delete HTML file: '{$htmlFilePath}'");
            if (file_exists($htmlFilePath)) {
                if (unlink($htmlFilePath)) {
                    $htmlFileDeleted = true;
                    error_log("delete_email.php: HTML file '{$htmlFilePath}' deleted successfully.");
                } else {
                    $response['message'] = 'Failed to delete associated HTML file due to permissions or other server issue.';
                    error_log("delete_email.php: FAILED to delete HTML file '{$htmlFilePath}'. Check file permissions.");
                }
            } else {
                error_log("delete_email.php: HTML file '{$htmlFilePath}' not found. Skipping file deletion.");
                $htmlFileDeleted = true; // Consider it 'deleted' for metadata purposes if file is already gone
            }
        } else {
            // Not authorized, keep this entry
            $updatedMetadata[] = $entry;
            error_log("delete_email.php: User '{$loggedInUsername}' (Level: '{$loggedInUserLevel}') NOT AUTHORIZED to delete email created by '{$entryCreatedBy}'. Keeping entry.");
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
        error_log("delete_email.php: Metadata updated successfully. Final count: " . count($updatedMetadata));
    } else {
        http_response_code(500);
        $response['message'] = 'Failed to save updated email metadata after deletion attempt. Check file permissions on ' . $metadataFile;
        error_log("delete_email.php: FAILED to write updated metadata to '{$metadataFile}'. Check file permissions.");
    }
} else {
    // If emailDeleted is false, it means either:
    // 1. The referenceCode was not found in the metadata at all.
    // 2. The email was found, but the user was not authorized to delete it (handled by $authorizedToDelete).
    if (!$authorizedToDelete) {
        http_response_code(403);
        $response['message'] = 'You are not authorized to delete this email.';
        error_log("delete_email.php: Final check: User '{$loggedInUsername}' not authorized for email '{$referenceCodeToDelete}'.");
    } else { // This means it was authorized but not found
        $response['message'] = 'Email with provided reference code not found in metadata.';
        error_log("delete_email.php: Final check: Email '{$referenceCodeToDelete}' not found in metadata despite authorization.");
    }
}

echo json_encode($response);
error_log("delete_email.php: Script finished.");
