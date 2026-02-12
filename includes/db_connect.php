<?php
// db_connect.php - Handles database connection for both local and Azure environments.

// Error reporting is now managed globally by includes/init.php.

// Define global variable to hold the mysqli connection object, or null if connection fails.
global $mysqli;
$mysqli = null; // Initialize to null

// Define global variable to track connection status
global $db_connection_success;
$db_connection_success = false;

// --- Local Database Configuration (for your local development environment) ---
// IMPORTANT: For Azure deployment, ensure these are NOT used and
// Azure environment variables are properly set.
define('DB_SERVER_LOCAL', 'emailpos-db-server.mysql.database.azure.com');
define('DB_USERNAME_LOCAL', 'emailposadmin'); 
define('DB_PASSWORD_LOCAL', 'TreeFrogBiscuit@2025'); 
define('DB_NAME_LOCAL', 'emailpos'); 

// --- Determine which database configuration to use (Azure or Local) ---
$db_host = getenv('DB_SERVER_AZURE');
$db_user = getenv('DB_USERNAME_AZURE');
$db_pass = getenv('DB_PASSWORD_AZURE');
$db_name = getenv('DB_NAME_AZURE');

// If Azure environment variables are NOT set, fall back to local development settings.
if (!$db_host || !$db_user || !$db_pass || !$db_name) {
    $db_host = DB_SERVER_LOCAL;
    $db_user = DB_USERNAME_LOCAL;
    $db_pass = DB_PASSWORD_LOCAL;
    $db_name = DB_NAME_LOCAL;
    error_log("WARNING: Azure DB environment variables not set. Falling back to local/hardcoded DB settings.");
}

// --- SSL Configuration for Azure MySQL ---
$port = 3306; // Default MySQL port
$socket = NULL; // No specific socket file

// Attempt to initialize mysqli
$mysqli = mysqli_init();
if (!$mysqli) {
    error_log("FATAL ERROR: Could not initialize mysqli object.");
    // No specific JSON response here, as this is a very low-level PHP issue.
    // The calling script will handle the global $db_connection_success being false.
    return; // Exit script gracefully without die()
}

// Apply SSL options if connecting to an Azure MySQL server.
// Adjust this logic if you have different server types or strict SSL requirements.
$use_ssl = false;
if (strpos($db_host, '.mysql.database.azure.com') !== false) {
    $use_ssl = true;
    // Current setting: Disables server certificate verification.
    // This makes the connection susceptible to Man-in-the-Middle attacks.
    // FOR PRODUCTION: YOU MUST AIM TO SET MYSQLI_OPT_SSL_VERIFY_SERVER_CERT to true
    // and provide a valid CA certificate if possible to ensure secure connections.
    $mysqli->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false); // !!! WARNING: NOT SECURE FOR PRODUCTION !!!
    
    // Path to the downloaded SSL certificate. Ensure this file exists and is accessible.
    // For more secure connections, enable verification and uncomment the line below:
    // $ssl_ca = __DIR__ . '/certs/DigiCertGlobalRootG2.crt.pem';
    // if (file_exists($ssl_ca)) {
    //    mysqli_ssl_set($mysqli, NULL, NULL, $ssl_ca, NULL, NULL);
    // } else {
    //    error_log("WARNING: SSL CA certificate not found at {$ssl_ca}. SSL verification might fail or be skipped.");
    // }
}

// Attempt connection using mysqli_real_connect for finer control, especially with SSL.
$connect_flags = $use_ssl ? MYSQLI_CLIENT_SSL : 0;

if (!@mysqli_real_connect($mysqli, $db_host, $db_user, $db_pass, $db_name, $port, $socket, $connect_flags)) {
    // Use error_log for logging the detailed error
    error_log("Database Connection Failed to {$db_host} for user {$db_user}: " . mysqli_connect_error());
    // The $db_connection_success remains false, which calling scripts will check.
} else {
    // Set the character set to UTF-8 for proper handling of various characters.
    if (!$mysqli->set_charset("utf8mb4")) {
        error_log("Error loading character set utf8mb4: " . $mysqli->error);
        $mysqli->close(); // Close connection if charset fails
        $mysqli = null; // Mark connection as failed
        $db_connection_success = false;
    } else {
        $db_connection_success = true; // Connection successful and charset set
    }
}

// No closing PHP tag is needed if the file only contains PHP code.
