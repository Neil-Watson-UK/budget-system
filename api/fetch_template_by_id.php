<?php
// api/save_email.php

// This one line gives us the session, security, config, AND database connection.
require_once __DIR__ . '/../includes/init.php';
session_start();
header('Content-Type: application/json');

ob_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Not logged in.']);
    exit;
}

$response = ['success' => false, 'message' => '', 'template' => null];
$templateId = $_GET['templateId'] ?? ''; // Expecting templateId via GET parameter

if (empty($templateId)) {
    ob_clean();
    $response['message'] = 'Template ID is required.';
    echo json_encode($response);
    exit;
}

$templatesFile = 'email_templates.json';

if (!file_exists($templatesFile)) {
    ob_clean();
    $response['message'] = 'Templates file does not exist.';
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

$foundTemplate = null;
$loggedInUserName = $_SESSION['name'] ?? null;
$userLevel = $_SESSION['user_level'] ?? 'user';

foreach ($allTemplates as $template) {
    if ($template['id'] === $templateId) {
        // Authorization check: Admin can load any, creator can load their own
        if ($userLevel === 'admin' || (isset($template['createdBy']) && $template['createdBy'] === $loggedInUserName)) {
            $foundTemplate = $template;
            break;
        } else {
            ob_clean();
            $response['message'] = 'Unauthorized: You do not have permission to load this template.';
            echo json_encode($response);
            exit;
        }
    }
}

if ($foundTemplate) {
    ob_clean();
    $response['success'] = true;
    $response['template'] = $foundTemplate;
} else {
    ob_clean();
    $response['message'] = 'Template not found or unauthorized.';
}

echo json_encode($response);
exit;
?>