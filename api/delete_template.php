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

$response = ['success' => false, 'message' => ''];
$data = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    ob_clean();
    $response['message'] = 'Invalid JSON input: ' . json_last_error_msg();
    echo json_encode($response);
    exit;
}

$templateIdToDelete = $data['templateId'] ?? '';
$templateCreator = $data['createdBy'] ?? null; // The original creator of the template

if (empty($templateIdToDelete)) {
    ob_clean();
    $response['message'] = 'Template ID is required for deletion.';
    echo json_encode($response);
    exit;
}

$templatesFile = 'email_templates.json';

if (!file_exists($templatesFile)) {
    ob_clean();
    $response['message'] = 'Templates file does not exist, so template cannot be deleted.';
    echo json_encode($response);
    exit;
}

$currentTemplates = json_decode(file_get_contents($templatesFile), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    ob_clean();
    $response['message'] = 'Error reading email_templates.json: ' . json_last_error_msg();
    echo json_encode($response);
    exit;
}

$userLevel = $_SESSION['user_level'] ?? 'user';
$loggedInUserName = $_SESSION['name'] ?? null;
$templateFoundAndAuthorized = false;
$updatedTemplates = [];

foreach ($currentTemplates as $template) {
    if ($template['id'] === $templateIdToDelete) {
        // Authorization check: Admin can delete any, creator can delete their own
        if ($userLevel === 'admin' || (isset($template['createdBy']) && $template['createdBy'] === $loggedInUserName)) {
            $templateFoundAndAuthorized = true;
            // Skip adding this template to updatedTemplates to effectively delete it
        } else {
            // If unauthorized, stop and return error
            ob_clean();
            $response['message'] = 'Unauthorized: You do not have permission to delete this template.';
            echo json_encode($response);
            exit;
        }
    } else {
        $updatedTemplates[] = $template; // Keep templates that are not being deleted
    }
}

if (!$templateFoundAndAuthorized) {
    ob_clean();
    $response['message'] = 'Template not found or you are not authorized to delete it.';
    echo json_encode($response);
    exit;
}

// Save the updated list of templates
if (file_put_contents($templatesFile, json_encode($updatedTemplates, JSON_PRETTY_PRINT)) === false) {
    ob_clean();
    $response['message'] = 'Failed to delete template from file.';
    echo json_encode($response);
    exit;
}

ob_clean();
$response['success'] = true;
$response['message'] = 'Template deleted successfully!';
echo json_encode($response);
exit;
?>