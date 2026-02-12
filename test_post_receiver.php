<?php
session_start();
echo "<pre>";
echo "=== POST TEST RECEIVER ===\n";
echo "Method: " . $_SERVER['REQUEST_METHOD'] . "\n\n";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "✅ SUCCESS: POST method detected!\n\n";
    echo "POST data:\n";
    print_r($_POST);
} else {
    echo "❌ FAILED: Expected POST, got " . $_SERVER['REQUEST_METHOD'] . "\n\n";
    echo "What was received:\n";
    echo "GET: "; print_r($_GET);
    echo "POST: "; print_r($_POST);
}
echo "</pre>";