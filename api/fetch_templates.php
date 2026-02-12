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

$response = ['success' => false, 'message' => '', 'templates' => []];
$templatesFile = 'email_templates.json';
$loggedInUserName = $_SESSION['name'] ?? null;
$userLevel = $_SESSION['user_level'] ?? 'user';

if (!file_exists($templatesFile)) {
    ob_clean();
    $response['success'] = true; // No templates yet, but not an error
    echo json_encode($response);
    exit;
}

$templatesContent = file_get_contents($templatesFile);
$allTemplates = json_decode($templatesContent, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    ob_clean();
    $response['message'] = 'Error reading email_templates.json: ' . json_last_error_msg();
    echo json_encode($response);
    exit;
}

$filteredTemplates = [];
foreach ($allTemplates as $template) {
    // Admins see all templates, regular users see only their own
    if ($userLevel === 'admin' || (isset($template['createdBy']) && $template['createdBy'] === $loggedInUserName)) {
        // Exclude the large 'templateData' from the initial list to keep it lightweight
        // We'll fetch full templateData only when a user explicitly loads it.
        $lightweightTemplate = $template;
        unset($lightweightTemplate['templateData']);
        $filteredTemplates[] = $lightweightTemplate;
    }
}

// Sort templates by creation date (newest first)
usort($filteredTemplates, function($a, $b) {
    $timeA = strtotime($a['createdAt'] ?? '1970-01-01 00:00:00');
    $timeB = strtotime($b['createdAt'] ?? '1970-01-01 00:00:00');
    return $timeB - $timeA; // Sort descending
});

ob_clean();
$response['success'] = true;
$response['templates'] = $filteredTemplates;
echo json_encode($response);
exit;
?>