<?php
// api/save_email.php

// This one line gives us the session, security, config, AND database connection.
require_once __DIR__ . '/../includes/init.php';
session_start();
header('Content-Type: application/json');

// Start output buffering to catch any stray output
ob_start();

// Security Check: Redirect if user is not logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Not logged in.']);
    exit;
}

$response = ['success' => false, 'message' => ''];
$data = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    ob_clean();
    $response['message'] = 'Invalid JSON input: ' . json_last_error_msg();
    echo json_encode($response);
    exit;
}

$templateName = $data['templateName'] ?? '';
$templateData = $data['templateData'] ?? []; // This will contain the full email structure
$loggedInUserName = $_SESSION['name'] ?? 'Unknown User'; // Get logged-in username

if (empty($templateName)) {
    ob_clean();
    $response['message'] = 'Template name is required.';
    echo json_encode($response);
    exit;
}

$templatesFile = 'email_templates.json';

// Ensure the templates file exists
if (!file_exists($templatesFile)) {
    file_put_contents($templatesFile, json_encode([])); // Create an empty JSON array
}

$currentTemplates = json_decode(file_get_contents($templatesFile), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    // If file is corrupted, reinitialize
    $currentTemplates = [];
}

// Generate a unique ID for the template
$templateId = uniqid('tpl_');

// Prepare the new template entry
$newTemplateEntry = [
    'id' => $templateId,
    'name' => $templateName,
    'createdBy' => $loggedInUserName,
    'createdAt' => date('Y-m-d H:i:s'),
    'templateData' => $templateData // Save the full email structure
];

// Add the new template to the list
$currentTemplates[] = $newTemplateEntry;

// Save the updated templates list
if (file_put_contents($templatesFile, json_encode($currentTemplates, JSON_PRETTY_PRINT)) === false) {
    ob_clean();
    $response['message'] = 'Failed to save template.';
    echo json_encode($response);
    exit;
}

ob_clean();
$response['success'] = true;
$response['message'] = 'Template saved successfully!';
$response['templateId'] = $templateId;
echo json_encode($response);
exit;
?>