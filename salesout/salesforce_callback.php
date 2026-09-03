<?php
/**
 * Salesforce OAuth 2.0 callback (Redirect URI).
 *
 * Register this URL in your Salesforce Connected App as the Callback URL:
 *   https://eposaudioevents.com/budgets/salesout/salesforce_callback.php
 *
 * After authorization, Salesforce redirects here with ?code=... (and optionally ?state=...).
 * Implement: exchange the code for access/refresh tokens, store them, then redirect.
 */
require_once __DIR__ . '/config.php';
require_once BUDGET_PATH . '/bootstrap.php';

$code = $_GET['code'] ?? '';
$state = $_GET['error'] ?? $_GET['state'] ?? '';

if (!empty($_GET['error'])) {
    // User denied or error (e.g. access_denied)
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Salesforce authorization</title></head><body>';
    echo '<p>Authorization failed or was denied. <a href="index.php">Return to SalesOut</a></p>';
    echo '</body></html>';
    exit;
}

if (empty($code)) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Salesforce callback</title></head><body>';
    echo '<p>No authorization code received. Use the app’s Salesforce connect link to authorize first. <a href="index.php">Return to SalesOut</a></p>';
    echo '</body></html>';
    exit;
}

// TODO: Exchange $code for access_token and refresh_token (POST to Salesforce token endpoint).
// Store tokens securely (DB or env); then redirect to the page that uses the API.
header('Location: index.php?sf_connected=1');
exit;
