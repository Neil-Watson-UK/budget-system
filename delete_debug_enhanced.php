<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html>";
echo "<html><head><title>Enhanced Delete Debug</title>";
echo "<style>
    pre { background: #f0f0f0; padding: 10px; border-radius: 5px; }
    .success { color: green; }
    .error { color: red; }
    .warning { color: orange; }
</style>";
echo "</head><body style='padding: 20px;'>";

echo "<h1>Enhanced Delete Debug</h1>";

// Check ALL request data
echo "<h2>Full Request Analysis</h2>";
echo "<pre>";
echo "REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD'] . "\n";
echo "REQUEST_URI: " . $_SERVER['REQUEST_URI'] . "\n";
echo "QUERY_STRING: " . ($_SERVER['QUERY_STRING'] ?? 'none') . "\n";
echo "CONTENT_TYPE: " . ($_SERVER['CONTENT_TYPE'] ?? 'not set') . "\n";
echo "HTTP_REFERER: " . ($_SERVER['HTTP_REFERER'] ?? 'not set') . "\n";
echo "\n";

echo "=== SUPER GLOBALS ===\n";
echo "\$_POST:\n";
if (empty($_POST)) {
    echo "  EMPTY\n";
} else {
    print_r($_POST);
}

echo "\n\$_GET:\n";
if (empty($_GET)) {
    echo "  EMPTY\n";
} else {
    print_r($_GET);
}

echo "\n\$_REQUEST:\n";
print_r($_REQUEST);

echo "\n\$_SERVER['argv']:\n";
print_r($_SERVER['argv'] ?? 'not set');

// Check raw input
echo "\n\n=== RAW INPUT ===\n";
$raw_input = file_get_contents('php://input');
echo "php://input length: " . strlen($raw_input) . " bytes\n";
if ($raw_input) {
    echo "Content: " . htmlspecialchars($raw_input) . "\n";
}

echo "</pre>";

// Test what happens if we force POST
echo "<h2>Test Manual POST</h2>";
echo "<form method='POST'>";
echo "<input type='hidden' name='test_field' value='test_value'>";
echo "<button type='submit' name='manual_test' value='1'>Test POST to This Page</button>";
echo "</form>";

if (isset($_POST['manual_test'])) {
    echo "<div class='success'>✓ Manual POST worked! Method was: " . $_SERVER['REQUEST_METHOD'] . "</div>";
}

echo "<h2>What to Check:</h2>";
echo "<ol>";
echo "<li>Is there a .htaccess file redirecting POST to GET?</li>";
echo "<li>Is there a JavaScript error preventing form submission?</li>";
echo "<li>Is there a browser extension modifying forms?</li>";
echo "<li>Is the server configured correctly?</li>";
echo "</ol>";

// Check for .htaccess issues
echo "<h2>Check .htaccess</h2>";
if (file_exists('.htaccess')) {
    echo "<pre>" . htmlspecialchars(file_get_contents('.htaccess')) . "</pre>";
} else {
    echo "<p class='warning'>No .htaccess file found</p>";
}

echo "</body></html>";