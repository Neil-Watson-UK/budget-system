<?php
// includes/init.php
session_start(); // Start the session at the very beginning of script execution.

// --- Environment Configuration ---
// Determine if the server is running on a local environment or a production environment like Azure.
// This is useful for conditional error reporting or other environment-specific settings.
define('IS_LOCAL_ENV', in_array($_SERVER['SERVER_NAME'], ['localhost', '127.0.0.1']));

// --- Error Reporting ---
// Show all errors in local environment for easy debugging.
// Log errors but do not display them to the user in production for security.
ini_set('display_errors', IS_LOCAL_ENV ? 1 : 0);
ini_set('display_startup_errors', IS_LOCAL_ENV ? 1 : 0);
error_reporting(IS_LOCAL_ENV ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_STRICT);
ini_set('log_errors', 1);
// For Azure App Services, logs are typically directed to /home/LogFiles/ by default,
// or collected via 'Application logging' and visible in 'Log stream'.
// You generally don't need to specify a custom error_log path unless required.


// --- Security Check: Redirect if user is not logged in ---
// This is a critical security measure to ensure only authenticated users can access core application pages.
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    // Before redirecting, set a proper HTTP status code.
    http_response_code(403); // Forbidden access.

    // For AJAX/API requests, return a JSON response indicating authentication is required.
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Authentication required. Please log in.']);
    } else {
        // For standard browser requests, redirect to the login page.
        header('Location: index.php'); // Assuming index.php is your login page.
    }
    exit; // Terminate script execution after handling.
}

// --- Base URL Definition ---
// This is crucial for correctly linking CSS, JS, images, and other pages,
// especially in cloud environments like Azure App Services which use proxy servers.
// It detects whether the original request was HTTP or HTTPS.
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    $protocol = 'https://';
} elseif (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    // This condition specifically handles Azure App Services and other proxy environments
    // where the original request protocol is indicated by X-Forwarded-Proto.
    $protocol = 'https://';
} else {
    $protocol = 'http://';
}

$host = $_SERVER['HTTP_HOST'];
// For applications deployed to the root of the App Service (e.g., /home/site/wwwroot),
// the base URL is simply the protocol + host.
// If your application is intended to run in a *subdirectory* within wwwroot (e.g., wwwroot/emailpos/),
// then you would need more complex logic to include that subdirectory.
// However, based on the console errors and typical deployments, it's assumed the app is at the root.
define('BASE_APP_URL', $protocol . $host . '/');


// --- Session Data & User Information ---
// Centralized place to retrieve and sanitize user data from the session.
$loggedInUserName = isset($_SESSION['name']) ? htmlspecialchars($_SESSION['name']) : 'EPOS hero!';
$current_user_level = $_SESSION['user_level'] ?? 'user';
$loggedInUserId = $_SESSION['user_id'] ?? null; // Use null for clearer checks if ID is missing.

if ($loggedInUserId === null) {
    // This should ideally not be hit if the security check above works,
    // but it's a good safeguard for unexpected session states.
    http_response_code(500); // Internal Server Error.
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Internal error: User ID not found in session after login.']);
    exit;
}

// --- Load Global Settings from settings.json ---
$globalSettings = [];
// Use __DIR__ to ensure the path is absolute and correct regardless of where init.php is included.
// __DIR__ is the directory of the current file (includes/). We need to go up one level to find settings.json.
$settingsFile = __DIR__ . '/../settings.json';
if (file_exists($settingsFile)) {
    $settingsContent = file_get_contents($settingsFile);
    $globalSettings = json_decode($settingsContent, true);
    if ($globalSettings === null) {
        $globalSettings = []; // Reset to empty array if JSON is invalid or empty.
        error_log("FATAL: Could not decode settings.json. File might be empty or corrupt.");
    }
} else {
    error_log("WARNING: settings.json file not found at: " . $settingsFile);
}


// --- Define Global Constants from Settings ---
// These constants are made available globally for easier access throughout the application.
define('DEFAULT_LOGO_URL', htmlspecialchars($globalSettings['defaultLogoUrl'] ?? BASE_APP_URL . 'assets/images/emailpos.svg'));
define('PRIMARY_BRAND_COLOR', htmlspecialchars($globalSettings['primaryBrandColor'] ?? '#00353d'));
define('SECONDARY_BRAND_COLOR', htmlspecialchars($globalSettings['secondaryBrandColor'] ?? '#00a399'));

// Font settings - ensure these keys exist in your settings.json or provide suitable defaults.
$customFontUrl = htmlspecialchars($globalSettings['customFontUrl'] ?? '');
$customFontFamilyName = htmlspecialchars($globalSettings['customFontFamilyName'] ?? '');
// If a custom font URL and name are provided, use the custom name; otherwise, use a default.
define('DEFAULT_FONT_FAMILY', (!empty($customFontUrl) && !empty($customFontFamilyName)) ? $customFontFamilyName : "Arial, sans-serif"); // Changed default to generic web font

// Define color palettes as PHP arrays. emailpos.php will then json_encode these for JavaScript.
define('ALLOWED_TEXT_COLORS', array_map('trim', explode(',', $globalSettings['allowedTextColors'] ?? '#131313,#ffffff,#00353d,#00a399,#ff5549')));
define('ALLOWED_BACKGROUND_COLORS', array_map('trim', explode(',', $globalSettings['allowedBackgroundColors'] ?? '#131313,#ffffff,#00353d,#00a399,#ff5549')));

// --- Database Connection ---
// Includes the database connection script.
// Since init.php is in 'includes/' and db_connect.php is in the parent directory,
// the path is __DIR__ . '/../db_connect.php'.
require_once __DIR__ . '/../db_connect.php';


// --- Helper Functions ---
/**
 * Sends a consistent JSON response and terminates the script.
 * This function is useful for API endpoints.
 * @param bool $success - Whether the operation was successful.
 * @param string $message - A human-readable message to return.
 * @param array $data - Optional: Additional data to include in the response.
 * @param int $statusCode - Optional: The HTTP status code to send (default: 200).
 */
function send_json_response($success, $message, $data = [], $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}