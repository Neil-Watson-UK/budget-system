<?php
// api/save_email.php

// This one line gives us the session, security, config, AND database connection.
require_once __DIR__ . '/../includes/init.php';
session_start();
header('Content-Type: application/json');

$response = ['success' => false, 'message' => '', 'blocks' => []];

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

$metadataFile = 'reusable_blocks_metadata.json';

if (!file_exists($metadataFile)) {
    $response['success'] = true; // No blocks file is not an error, just means no blocks
    $response['message'] = 'No reusable blocks found.';
    echo json_encode($response);
    exit;
}

$allBlocks = json_decode(file_get_contents($metadataFile), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(500);
    $response['message'] = 'Error reading reusable block metadata.';
    echo json_encode($response);
    exit;
}

$userBlocks = [];
foreach ($allBlocks as $block) {
    // Filter blocks by the logged-in user's ID
    if (isset($block['userId']) && $block['userId'] === $loggedInUserId) {
        $userBlocks[] = $block;
    }
}

// Sort blocks by creation time, newest first
usort($userBlocks, function($a, $b) {
    $timeA = strtotime($a['createdAt'] ?? '1970-01-01 00:00:00');
    $timeB = strtotime($b['createdAt'] ?? '1970-01-01 00:00:00');
    return $timeB - $timeA; // Descending order (newest first)
});


$response['success'] = true;
$response['message'] = 'Reusable blocks fetched successfully.';
$response['blocks'] = $userBlocks;

echo json_encode($response);
?>
