<?php
// api/send_test_email.php
// Sends test emails via Azure Communication Services Email REST API.

require_once __DIR__ . '/../includes/init.php'; // Session, security, utilities

header('Content-Type: application/json; charset=utf-8');
error_log("Starting send_test_email.php");

Temporarily bypass session check for CLI testing
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
send_json_response(false, 'Authentication required.', [], 403);
}

// Ensure POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_log("Invalid request method: " . $_SERVER['REQUEST_METHOD']);
    send_json_response(false, 'Invalid request method. Only POST is allowed.', [], 405);
}

// Get raw POST data
$input = file_get_contents('php://input');
error_log("Raw input: $input");
$data = json_decode($input, true);

// Validate input
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("JSON Decode Error: " . json_last_error_msg());
    send_json_response(false, 'Invalid JSON input.', [], 400);
}

$recipient = $data['recipient'] ?? '';
$subject = $data['subject'] ?? 'Test Email from EmailPOS';
$htmlContent = $data['html_content'] ?? '';
error_log("Recipient: $recipient, Subject: $subject");

if (empty($recipient) || empty($htmlContent)) {
    error_log("Missing recipient or HTML content");
    send_json_response(false, 'Recipient email and HTML content are required.', [], 400);
}

// Azure Configuration (from env vars)
$endpoint = rtrim(getenv('AZURE_EMAIL_ENDPOINT') ?: '', '/');
$accessKey = getenv('AZURE_EMAIL_ACCESS_KEY');
$senderEmail = getenv('AZURE_EMAIL_SENDER');
error_log("Endpoint: $endpoint, Sender: $senderEmail");

if (empty($endpoint) || empty($accessKey) || empty($senderEmail)) {
    error_log("Azure config missing - Endpoint: $endpoint, AccessKey: " . ($accessKey ? 'set' : 'unset') . ", Sender: $senderEmail");
    send_json_response(false, 'Azure configuration missing.', [], 500);
}

$apiVersion = '2023-03-31';
$url = $endpoint . '/emails:Send?api-version=' . $apiVersion;
error_log("Request URL: $url");

// Prepare the email payload
$payload = [
    'senderAddress' => $senderEmail,
    'content' => [
        'subject' => $subject,
        'html' => $htmlContent
    ],
    'recipients' => [
        'to' => [
            [
                'address' => $recipient
            ]
        ]
    ]
];

$jsonPayload = json_encode($payload);
error_log("Payload: $jsonPayload");

// Initialize cURL
$ch = curl_init($url);

// Set cURL options
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $jsonPayload,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($jsonPayload),
        'Authorization: HMAC-SHA256 Endpoint=' . $endpoint . ',SharedKey=' . $accessKey,
        'Accept: application/json'
    ],
    CURLOPT_TIMEOUT => 30
]);

// Execute cURL request
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
error_log("cURL Response: HTTP $httpCode, Body: " . ($response ?: 'empty'));
curl_close($ch);

if ($error) {
    error_log("cURL Error: $error");
    send_json_response(false, 'Failed to send test email (cURL error): ' . $error, [], 500);
}

if ($httpCode === 202) {
    error_log("Email sent successfully");
    send_json_response(true, 'Test email sent successfully via Azure.', [], 200);
} else {
    $errorMessage = $response ? json_decode($response, true)['error']['message'] ?? 'Unknown error' : 'HTTP ' . $httpCode;
    error_log("Azure Email Error: HTTP $httpCode - $errorMessage");
    send_json_response(false, 'Failed to send test email via Azure: ' . $errorMessage, [], 500);
}
?>