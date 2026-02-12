<?php
// api/save_email.php

// This one line gives us the session, security, config, AND database connection.
require_once __DIR__ . '/../includes/init.php';
// save_email.php
header('Content-Type: application/json');

$response = ['success' => false, 'message' => '', 'referenceCode' => ''];
$data = json_decode(file_get_contents('php://input'), true);

// --- START DEBUGGING LOG ---
// Define a log file path (make sure it's writable by your web server)
// IMPORTANT: Remove this logging code after debugging for security reasons!
$logFile = 'debug_save_email.log';
file_put_contents($logFile, "Received data in save_email.php at " . date('Y-m-d H:i:s') . ":\n", FILE_APPEND);
file_put_contents($logFile, print_r($data, true) . "\n\n", FILE_APPEND);
// --- END DEBUGGING LOG ---

if (json_last_error() !== JSON_ERROR_NONE) {
    $response['message'] = 'Invalid JSON input.';
    echo json_encode($response);
    exit;
}

// Ensure the 'emails' directory exists for storing full HTML content and JSON data
$emailsDir = 'emails/';
if (!is_dir($emailsDir)) {
    if (!mkdir($emailsDir, 0755, true)) {
        $response['message'] = 'Failed to create emails directory.';
        echo json_encode($response);
        exit;
    }
}

// Ensure the 'email_metadata.json' file exists and is writable
$metadataFile = 'email_metadata.json';
if (!file_exists($metadataFile)) {
    file_put_contents($metadataFile, json_encode([])); // Create an empty JSON array
}

// Validate essential data
$referenceCode = $data['referenceCode'] ?? '';
$subjectLine = $data['subjectLine'] ?? '';
$htmlContent = $data['htmlContent'] ?? ''; // Assuming you'll pass the generated HTML here

// Remove htmlContent from the data object before saving it as JSON,
// as it will be saved separately for efficiency and to keep the form data concise.
$formDataToSave = $data;
unset($formDataToSave['htmlContent']);

if (empty($referenceCode)) {
    $response['message'] = 'Reference code is required.';
    echo json_encode($response);
    exit;
}

// Sanitize reference code for filename
$safeReferenceCode = preg_replace('/[^a-zA-Z0-9_-]/', '', $referenceCode);
if (empty($safeReferenceCode)) {
    $response['message'] = 'Invalid reference code after sanitization.';
    echo json_encode($response);
    exit;
}

$emailHtmlFilePath = $emailsDir . $safeReferenceCode . '.html'; // Path for HTML content
$emailDataFilePath = $emailsDir . $safeReferenceCode . '.json'; // Path for structured JSON data

// Corrected: Get createdBy, region, sendTime, senderEmail, and now AUDIENCE from the fullEmailData
$fullEmailData = $formDataToSave; // The fullEmailData is now the $formDataToSave itself after unsetting htmlContent
$createdBy = $fullEmailData['createdBy'] ?? 'Unknown Author';
file_put_contents($logFile, "CreatedBy value extracted: " . $createdBy . "\n", FILE_APPEND); // Added specific log for createdBy
$region = $fullEmailData['region'] ?? '';
$audience = $fullEmailData['audience'] ?? ''; // NEW: Extract audience
$sendTime = $fullEmailData['sendTime'] ?? ''; // Keep the full sendTime as is for consistency
$senderEmail = $fullEmailData['senderEmail'] ?? '';

// --- NEW: Extract first image URL and content excerpt ---
$firstImageUrl = '';
$contentExcerpt = '';

if (!empty($fullEmailData['articles'])) {
    // Find the first article block (assuming keys are numeric positions, starting from '1' or lowest)
    $firstArticleKey = null;
    foreach (array_keys($fullEmailData['articles']) as $key) {
        if (is_numeric($key)) { // Ensure it's a numeric position
            if ($firstArticleKey === null || (int)$key < (int)$firstArticleKey) {
                $firstArticleKey = $key;
            }
        }
    }

    if ($firstArticleKey !== null) {
        $firstArticle = $fullEmailData['articles'][$firstArticleKey];

        // Extract first image URL based on article type
        if (isset($firstArticle['type'])) {
            if ($firstArticle['type'] === 'single' && !empty($firstArticle['mainImage'])) {
                $firstImageUrl = $firstArticle['mainImage'];
            } elseif ($firstArticle['type'] === 'double' && !empty($firstArticle['leftImage'])) {
                $firstImageUrl = $firstArticle['leftImage'];
            } elseif ($firstArticle['type'] === 'double' && !empty($firstArticle['rightImage'])) {
                $firstImageUrl = $firstArticle['rightImage'];
            }
            // For 'table' type, there's no direct image field, so $firstImageUrl remains empty.
        }

        // Extract and sanitize content excerpt
        $rawContent = '';
        if (isset($firstArticle['type'])) {
            if ($firstArticle['type'] === 'single' && !empty($firstArticle['content'])) {
                $rawContent = $firstArticle['content'];
            } elseif ($firstArticle['type'] === 'double' && !empty($firstArticle['leftContent'])) {
                $rawContent = $firstArticle['leftContent'];
            } elseif ($firstArticle['type'] === 'double' && !empty($firstArticle['rightContent'])) {
                $rawContent = $firstArticle['rightContent'];
            } elseif ($firstArticle['type'] === 'table' && !empty($firstArticle['tableHeader'])) {
                $rawContent = $firstArticle['tableHeader']; // Use table header as content excerpt
            }
        }

        // Strip HTML tags and truncate to 50 characters
        $contentExcerpt = strip_tags($rawContent);
        // Use mb_substr for multi-byte safe string operations (important for various characters/emojis)
        $contentExcerpt = mb_substr($contentExcerpt, 0, 50);
        if (mb_strlen(strip_tags($rawContent)) > 50) { // Check original stripped length
            $contentExcerpt .= '...'; // Add ellipsis if content was truncated
        }
    }
}
// --- END NEW EXTRACTION ---

try {
    // Save the full HTML content to a file
    if (file_put_contents($emailHtmlFilePath, $htmlContent) === false) {
        throw new Exception('Failed to save email HTML content.');
    }

    // Save the full structured form data as JSON
    if (file_put_contents($emailDataFilePath, json_encode($formDataToSave, JSON_PRETTY_PRINT)) === false) {
        throw new Exception('Failed to save email structured data.');
    }

    // Load existing metadata
    $metadata = json_decode(file_get_contents($metadataFile), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        // If metadata file is corrupted, start fresh (or handle error differently)
        $metadata = [];
    }

    // Check if reference code already exists and update, otherwise add new
    $found = false;
    foreach ($metadata as $key => $entry) {
        if ($entry['referenceCode'] === $referenceCode) {
            $metadata[$key]['subjectLine'] = $subjectLine;
            $metadata[$key]['emailHtmlFilePath'] = $emailHtmlFilePath; // Update HTML path
            $metadata[$key]['emailDataFilePath'] = $emailDataFilePath; // Store path to the full JSON data
            // Update new fields for consistency with fetch_emails.php
            $metadata[$key]['createdBy'] = $createdBy; // Changed from 'author' to 'createdBy'
            $metadata[$key]['region'] = $region;
            $metadata[$key]['audience'] = $audience; // NEW: Update audience
            $metadata[$key]['sendTime'] = $sendTime; // Changed from 'date' to 'sendTime'
            $metadata[$key]['senderEmail'] = $senderEmail;
            $metadata[$key]['firstImage'] = $firstImageUrl;
            $metadata[$key]['contentExcerpt'] = $contentExcerpt;
            $found = true;
            break;
        }
    }

    if (!$found) {
        $metadata[] = [
            'referenceCode' => $referenceCode,
            'subjectLine' => $subjectLine,
            'emailHtmlFilePath' => $emailHtmlFilePath,
            'emailDataFilePath' => $emailDataFilePath, // Store path to the full JSON data
            // Add new fields for consistency with fetch_emails.php
            'createdBy' => $createdBy, // Changed from 'author' to 'createdBy'
            'region' => $region,
            'audience' => $audience, // NEW: Add audience
            'sendTime' => $sendTime, // Changed from 'date' to 'sendTime'
            'senderEmail' => $senderEmail,
            'firstImage' => $firstImageUrl,
            'contentExcerpt' => $contentExcerpt
        ];
    }

    // Save updated metadata
    if (file_put_contents($metadataFile, json_encode($metadata, JSON_PRETTY_PRINT)) === false) {
        throw new Exception('Failed to save email metadata.');
    }

    $response['success'] = true;
    $response['message'] = 'Email saved successfully!';
    $response['referenceCode'] = $referenceCode;

} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
    // Clean up partial files if an error occurred during saving
    if (file_exists($emailHtmlFilePath)) unlink($emailHtmlFilePath);
    if (file_exists($emailDataFilePath)) unlink($emailDataFilePath);
}

echo json_encode($response);
exit;
?>