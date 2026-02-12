<?php
// api/save_email.php

// This one line gives us the session, security, config, AND database connection.
require_once __DIR__ . '/../includes/init.php';
session_start();
header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

// Check if the user is NOT logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(403);
    $response['message'] = 'Authentication required';
    echo json_encode($response);
    exit;
}

$loggedInUserId = $_SESSION['user_id'] ?? null;

if (empty($loggedInUserId)) {
    http_response_code(403);
    $response['message'] = 'User ID not found in session. Please log in again.';
    echo json_encode($response);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    $response['message'] = 'Invalid JSON input.';
    echo json_encode($response);
    exit;
}

$blockName = $input['name'] ?? '';
$htmlContent = $input['htmlContent'] ?? '';
$blockType = $input['blockType'] ?? 'unknown';
$detailedData = $input['detailedData'] ?? []; // Capture the detailed data structure

if (empty($blockName) || empty($htmlContent)) {
    http_response_code(400);
    $response['message'] = 'Block name and HTML content are required.';
    echo json_encode($response);
    exit;
}

$metadataFile = 'reusable_blocks_metadata.json';
$allBlocks = [];

// Read existing metadata
if (file_exists($metadataFile)) {
    $existingContent = file_get_contents($metadataFile);
    $decodedContent = json_decode($existingContent, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $allBlocks = $decodedContent;
    } else {
        // Log malformed JSON error but continue with empty array
        error_log("reusable_blocks_metadata.json is malformed. Error: " . json_last_error_msg());
    }
}

// Generate a unique ID for the new block
$blockId = uniqid('block_', true);

// Add new block metadata
$allBlocks[] = [
    'id' => $blockId,
    'userId' => $loggedInUserId,
    'name' => htmlspecialchars($blockName, ENT_QUOTES, 'UTF-8'),
    'htmlContent' => $htmlContent, // Save the full HTML content
    'blockType' => $blockType,
    'detailedData' => $detailedData, // Save the detailed data
    'createdAt' => date('Y-m-d H:i:s')
];

// Save updated metadata back to file
if (file_put_contents($metadataFile, json_encode($allBlocks, JSON_PRETTY_PRINT))) {
    $response['success'] = true;
    $response['message'] = 'Reusable block saved successfully.';
    $response['blockId'] = $blockId;
} else {
    http_response_code(500);
    $response['message'] = 'Failed to save reusable block metadata.';
}

echo json_encode($response);
?>